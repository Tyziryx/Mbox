#!/bin/bash
# Runtime quota helpers:
# - sync [MAC]     : ensure counter rules and return byte counters per MAC
# - block MAC on|off : toggle FORWARD block for one MAC when quota is exhausted

set -u

CONFIG_FILE="/etc/mbox/parental_config.json"
COUNTER_CHAIN="MBOX_QUOTA_COUNTER"
BLOCK_CHAIN="MBOX_QUOTA_BLOCK"

json_escape() {
    printf '%s' "$1" | jq -Rs .
}

json_error() {
    local msg="$1"
    printf '{"success":false,"error":%s}\n' "$(json_escape "$msg")"
    exit 1
}

require_cmd() {
    local cmd="$1"
    if ! command -v "$cmd" >/dev/null 2>&1; then
        json_error "Commande manquante: $cmd"
    fi
}

normalize_mac() {
    printf '%s' "$1" | tr '[:lower:]' '[:upper:]'
}

is_valid_mac() {
    printf '%s' "$1" | grep -Eq '^([0-9A-F]{2}:){5}[0-9A-F]{2}$'
}

ensure_chain() {
    local chain="$1"
    iptables -nL "$chain" >/dev/null 2>&1 || iptables -N "$chain" >/dev/null 2>&1 || return 1
    return 0
}

ensure_forward_jumps() {
    iptables -C FORWARD -j "$BLOCK_CHAIN" >/dev/null 2>&1 || iptables -I FORWARD 1 -j "$BLOCK_CHAIN" >/dev/null 2>&1
    iptables -C FORWARD -j "$COUNTER_CHAIN" >/dev/null 2>&1 || iptables -I FORWARD 2 -j "$COUNTER_CHAIN" >/dev/null 2>&1
}

resolve_ip_for_mac() {
    local mac_lc
    mac_lc=$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')

    local ip=""
    ip=$(ip neigh show 2>/dev/null | awk -v m="$mac_lc" 'tolower($5)==m {print $1; exit}')
    if [ -z "$ip" ] && [ -r /proc/net/arp ]; then
        ip=$(awk -v m="$mac_lc" 'tolower($4)==m {print $1; exit}' /proc/net/arp 2>/dev/null)
    fi

    if [ -n "$ip" ] && printf '%s' "$ip" | grep -Eq '^[0-9]+(\.[0-9]+){3}$'; then
        printf '%s' "$ip"
    else
        printf ''
    fi
}

counter_token_for_mac() {
    local compact
    compact=$(printf '%s' "$1" | tr -d ':')
    printf 'MBOXQ_%s' "$compact"
}

block_token_for_mac() {
    local compact
    compact=$(printf '%s' "$1" | tr -d ':')
    printf 'MBOXQB_%s' "$compact"
}

ensure_counter_rule() {
    local mac="$1"
    local ip="$2"
    local token
    token=$(counter_token_for_mac "$mac")

    [ -z "$ip" ] && return 0

    iptables -C "$COUNTER_CHAIN" -d "$ip" -m comment --comment "$token" -j RETURN >/dev/null 2>&1 || \
        iptables -A "$COUNTER_CHAIN" -d "$ip" -m comment --comment "$token" -j RETURN >/dev/null 2>&1
}

read_counter_bytes() {
    local mac="$1"
    local token
    token=$(counter_token_for_mac "$mac")

    # iptables-save -c prefixes each rule with "[pkts:bytes] -A CHAIN ...",
    # so we match on the "-A CHAIN " substring rather than anchoring at line start.
    iptables-save -c 2>/dev/null | awk -v chain="$COUNTER_CHAIN" -v token="$token" '
        index($0, "-A " chain " ") && index($0, token) {
            if (match($0, /^\[[0-9]+:[0-9]+\]/)) {
                block = substr($0, RSTART + 1, RLENGTH - 2)
                split(block, parts, ":")
                sum += parts[2]
            }
        }
        END { printf "%.0f", sum + 0 }
    '
}

set_block_state() {
    local mac="$1"
    local state="$2"
    local token
    token=$(block_token_for_mac "$mac")

    # Retire d'abord toutes les occurrences exactes de la regle MAC.
    while iptables -D "$BLOCK_CHAIN" -m mac --mac-source "$mac" -m comment --comment "$token" -j DROP >/dev/null 2>&1; do :; done

    if [ "$state" = "on" ]; then
        iptables -I "$BLOCK_CHAIN" 1 -m mac --mac-source "$mac" -m comment --comment "$token" -j DROP >/dev/null 2>&1 || return 1
    fi

    return 0
}

cmd_sync() {
    local target_mac="${1:-}"
    if [ -n "$target_mac" ]; then
        target_mac=$(normalize_mac "$target_mac")
        if ! is_valid_mac "$target_mac"; then
            json_error "MAC invalide pour sync"
        fi
    fi

    [ -r "$CONFIG_FILE" ] || json_error "Config introuvable: $CONFIG_FILE"

    ensure_chain "$COUNTER_CHAIN" || json_error "Impossible de creer/ouvrir la chaine $COUNTER_CHAIN"
    ensure_chain "$BLOCK_CHAIN" || json_error "Impossible de creer/ouvrir la chaine $BLOCK_CHAIN"
    ensure_forward_jumps

    local rows
    rows=$(jq -c '
        to_entries[]
        | {
            mac: (.key | ascii_upcase),
            name: ((.value.name // .key) | tostring),
            quota_gb: (((.value.download_quota_gb // 0) | tostring | gsub(","; ".") | tonumber?) // 0)
        }
        | select(.quota_gb > 0)
    ' "$CONFIG_FILE" 2>/dev/null)

    local entries='[]'
    local found=0

    while IFS= read -r row; do
        [ -z "$row" ] && continue

        local mac name quota_gb ip counter_bytes ready
        mac=$(echo "$row" | jq -r '.mac')
        name=$(echo "$row" | jq -r '.name')
        quota_gb=$(echo "$row" | jq -r '.quota_gb')

        if [ -n "$target_mac" ] && [ "$mac" != "$target_mac" ]; then
            continue
        fi

        found=1
        ip=$(resolve_ip_for_mac "$mac")
        ensure_counter_rule "$mac" "$ip"
        counter_bytes=$(read_counter_bytes "$mac")

        if [ -n "$ip" ]; then
            ready=true
        else
            ready=false
        fi

        entries=$(printf '%s' "$entries" | jq -c \
            --arg mac "$mac" \
            --arg name "$name" \
            --arg ip "$ip" \
            --arg quota_gb "$quota_gb" \
            --arg counter_bytes "${counter_bytes:-0}" \
            --arg ready "$ready" \
            '. + [{
                mac: $mac,
                name: $name,
                ip: (if ($ip|length) > 0 then $ip else null end),
                quota_gb: (($quota_gb | tonumber?) // 0),
                counter_bytes: (($counter_bytes | tonumber?) // 0),
                counter_rule_ready: ($ready == "true")
            }]'
        )
    done <<< "$rows"

    if [ -n "$target_mac" ] && [ "$found" -eq 0 ]; then
        printf '{"success":false,"error":%s}\n' "$(json_escape "MAC non trouvee dans parental_config.json")"
        exit 1
    fi

    jq -nc --arg now "$(date -Iseconds)" --argjson entries "$entries" '{success:true,timestamp:$now,entries:$entries}'
}

cmd_block() {
    local mac="${1:-}"
    local state="${2:-}"

    mac=$(normalize_mac "$mac")
    if ! is_valid_mac "$mac"; then
        json_error "MAC invalide pour block"
    fi
    if [ "$state" != "on" ] && [ "$state" != "off" ]; then
        json_error "Etat block invalide (on|off)"
    fi

    ensure_chain "$BLOCK_CHAIN" || json_error "Impossible de creer/ouvrir la chaine $BLOCK_CHAIN"
    ensure_chain "$COUNTER_CHAIN" || json_error "Impossible de creer/ouvrir la chaine $COUNTER_CHAIN"
    ensure_forward_jumps

    set_block_state "$mac" "$state" || json_error "Impossible d'appliquer block=$state pour $mac"

    jq -nc --arg mac "$mac" --arg state "$state" --arg now "$(date -Iseconds)" '{success:true,mac:$mac,blocked:($state=="on"),timestamp:$now}'
}

cmd_diag() {
    local mac="${1:-}"
    mac=$(normalize_mac "$mac")
    if ! is_valid_mac "$mac"; then
        json_error "MAC invalide pour diag"
    fi

    local ip token bytes
    ip=$(resolve_ip_for_mac "$mac")
    token=$(counter_token_for_mac "$mac")
    bytes=$(read_counter_bytes "$mac")

    printf '=== MAC: %s | IP resolved: %s | token: %s | counter_bytes: %s ===\n' "$mac" "${ip:-<none>}" "$token" "${bytes:-0}"

    printf '\n--- iptables -nL FORWARD -v --line-numbers ---\n'
    iptables -nL FORWARD -v --line-numbers 2>&1 | head -40

    printf '\n--- iptables -nL MBOX_QUOTA_COUNTER -v --line-numbers ---\n'
    iptables -nL MBOX_QUOTA_COUNTER -v --line-numbers 2>&1

    printf '\n--- iptables -nL MBOX_QUOTA_BLOCK -v --line-numbers ---\n'
    iptables -nL MBOX_QUOTA_BLOCK -v --line-numbers 2>&1

    printf '\n--- iptables-save matching %s ---\n' "$token"
    iptables-save -c 2>&1 | grep -F "$token" || echo "<no line matched>"

    printf '\n--- ip neigh (lower MAC) ---\n'
    ip neigh show 2>&1 | awk -v m="$(printf '%s' "$mac" | tr '[:upper:]' '[:lower:]')" 'tolower($5)==m || $1==m' || true

    printf '\n--- /proc/net/arp match ---\n'
    awk -v m="$(printf '%s' "$mac" | tr '[:upper:]' '[:lower:]')" 'tolower($4)==m' /proc/net/arp 2>&1 || true

    printf '\n--- ip route ---\n'
    ip route 2>&1

    printf '\n--- ip -br addr ---\n'
    ip -br addr 2>&1

    printf '\n--- nat PREROUTING / POSTROUTING summary ---\n'
    iptables -t nat -nL PREROUTING -v --line-numbers 2>&1 | head -20
    iptables -t nat -nL POSTROUTING -v --line-numbers 2>&1 | head -20

    printf '\n=== END DIAG ===\n'
}

main() {
    require_cmd jq
    require_cmd iptables
    require_cmd iptables-save
    require_cmd ip

    local action="${1:-sync}"
    case "$action" in
        sync)
            cmd_sync "${2:-}"
            ;;
        block)
            cmd_block "${2:-}" "${3:-}"
            ;;
        diag)
            cmd_diag "${2:-}"
            ;;
        *)
            json_error "Commande invalide. Usage: quota_runtime.sh sync [MAC] | block MAC on|off | diag MAC"
            ;;
    esac
}

main "$@"
