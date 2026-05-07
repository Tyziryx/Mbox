#!/usr/bin/env bash
set -euo pipefail

# reorganize.sh
# Idempotent migration script for the MBox project layout.
#
# Usage:
#   bash reorganize.sh [project_root]
#
# Optional environment flags:
#   DRY_RUN=1            Preview actions without writing files
#   NO_GIT_BASELINE=1    Skip pre-migration git snapshot
#   NO_APACHE=1          Skip Apache config patch/reload
#   NO_CRON_SYSTEMD=1    Skip cron/systemd path updates

TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
DRY_RUN="${DRY_RUN:-0}"
NO_GIT_BASELINE="${NO_GIT_BASELINE:-0}"
NO_APACHE="${NO_APACHE:-0}"
NO_CRON_SYSTEMD="${NO_CRON_SYSTEMD:-0}"

PROJECT_ROOT="${1:-$(pwd)}"
PROJECT_ROOT="$(cd "$PROJECT_ROOT" && pwd)"

PUBLIC_DIR="$PROJECT_ROOT/public"
INCLUDES_DIR="$PROJECT_ROOT/includes"
BIN_DIR="$PROJECT_ROOT/bin"
BIN_BASH_DIR="$BIN_DIR/bash"
BIN_PY_DIR="$BIN_DIR/python"
BIN_SQL_DIR="$BIN_DIR/sql"
BIN_PHP_DIR="$BIN_DIR/php"
DATA_DIR="$PROJECT_ROOT/data"
CONFIG_DIR="$PROJECT_ROOT/config"
ARCHIVES_DIR="$PROJECT_ROOT/archives"

log()  { printf '[INFO] %s\n' "$*"; }
warn() { printf '[WARN] %s\n' "$*" >&2; }
die()  { printf '[ERR ] %s\n' "$*" >&2; exit 1; }

run() {
  if [ "$DRY_RUN" = "1" ]; then
    printf '[DRY ]'
    for arg in "$@"; do
      printf ' %q' "$arg"
    done
    printf '\n'
  else
    "$@"
  fi
}

run_shell() {
  local cmd="$1"
  if [ "$DRY_RUN" = "1" ]; then
    printf '[DRY ] %s\n' "$cmd"
  else
    bash -lc "$cmd"
  fi
}

run_sudo_shell() {
  local cmd="$1"
  if [ "$DRY_RUN" = "1" ]; then
    printf '[DRY ] sudo %s\n' "$cmd"
  else
    sudo bash -lc "$cmd"
  fi
}

ensure_dir() {
  [ -d "$1" ] || run mkdir -p "$1"
}

move_path() {
  local src="$1"
  local dst="$2"

  [ -e "$src" ] || return 0
  ensure_dir "$(dirname "$dst")"

  if [ -e "$dst" ]; then
    if [ -d "$src" ] && [ -d "$dst" ]; then
      local item
      shopt -s dotglob nullglob
      for item in "$src"/*; do
        move_path "$item" "$dst/$(basename "$item")"
      done
      shopt -u dotglob nullglob
      if [ "$DRY_RUN" = "1" ]; then
        log "DRY cleanup directory $src"
      else
        rmdir "$src" 2>/dev/null || true
      fi
      return 0
    fi

    if [ -f "$src" ] && [ -f "$dst" ] && cmp -s "$src" "$dst"; then
      run rm -f "$src"
      return 0
    fi

    run mv "$dst" "${dst}.bak.${TIMESTAMP}"
  fi

  run mv "$src" "$dst"
}

move_rel() {
  local src_rel="$1"
  local dst_rel="$2"
  move_path "$PROJECT_ROOT/$src_rel" "$PROJECT_ROOT/$dst_rel"
}

replace_literal() {
  local file="$1"
  local old="$2"
  local new="$3"

  [ -f "$file" ] || return 0

  if [ "$DRY_RUN" = "1" ]; then
    if grep -Fq "$old" "$file"; then
      log "DRY replace in $file"
    fi
    return 0
  fi

  OLD="$old" NEW="$new" perl -0777 -i -pe 's/\Q$ENV{OLD}\E/$ENV{NEW}/g' "$file"
}

insert_after_literal() {
  local file="$1"
  local anchor="$2"
  local addition="$3"

  [ -f "$file" ] || return 0
  if grep -Fq "$addition" "$file"; then
    return 0
  fi

  replace_literal "$file" "$anchor" "$anchor"$'\n'"$addition"
}

cleanup_parasites() {
  log "Cleanup: remove archive temp folders and macOS artifacts"

  local d
  for d in "$PROJECT_ROOT"/.ArchiveServiceTemp.sb-*; do
    [ -e "$d" ] || continue
    run rm -rf "$d"
  done

  run find "$PROJECT_ROOT" -type f -name '._*' -delete
  run find "$PROJECT_ROOT" -type f -name '.DS_Store' -delete

  if [ -f "$PROJECT_ROOT/depot intermediaire s6.zip" ]; then
    ensure_dir "$ARCHIVES_DIR"
    move_rel "depot intermediaire s6.zip" "archives/depot intermediaire s6.zip"
  fi
}

git_baseline() {
  if [ "$NO_GIT_BASELINE" = "1" ]; then
    log "Git baseline skipped (NO_GIT_BASELINE=1)"
    return 0
  fi

  if ! command -v git >/dev/null 2>&1; then
    warn "git not found, skipping baseline snapshot"
    return 0
  fi

  log "Git baseline snapshot"

  if [ "$DRY_RUN" = "1" ]; then
    log "DRY git init/add/commit in $PROJECT_ROOT"
    return 0
  fi

  if [ ! -d "$PROJECT_ROOT/.git" ]; then
    git -C "$PROJECT_ROOT" init >/dev/null
  fi

  git -C "$PROJECT_ROOT" add -A

  if git -C "$PROJECT_ROOT" diff --cached --quiet; then
    log "No staged change for baseline commit"
    return 0
  fi

  if git -C "$PROJECT_ROOT" commit -m "pre-reorg baseline" >/dev/null 2>&1; then
    log "Baseline commit created"
    return 0
  fi

  if git -C "$PROJECT_ROOT" -c user.name="mbox-reorg" -c user.email="mbox-reorg@local" commit -m "pre-reorg baseline" >/dev/null 2>&1; then
    warn "Baseline commit created with temporary local identity"
    return 0
  fi

  warn "Could not create baseline commit automatically"
}

create_tree() {
  log "Create target directory tree"

  ensure_dir "$PUBLIC_DIR"
  ensure_dir "$PUBLIC_DIR/assets/css"
  ensure_dir "$PUBLIC_DIR/assets/js"
  ensure_dir "$PUBLIC_DIR/assets/img"

  ensure_dir "$INCLUDES_DIR"

  ensure_dir "$BIN_BASH_DIR/tests"
  ensure_dir "$BIN_PY_DIR"
  ensure_dir "$BIN_SQL_DIR"
  ensure_dir "$BIN_PHP_DIR"

  ensure_dir "$DATA_DIR/logs"
  ensure_dir "$CONFIG_DIR"
  ensure_dir "$ARCHIVES_DIR"
}

move_files() {
  log "Move files to new structure"

  local public_php=(
    index.php login.php logout.php speedtest.php
    dhcp.php dhcp_avance.php config_dns.php change_ip.php
    forum.php topic.php historique.php logs.php mail.php login_mail.php
    parental.php dns_blocked.php
    api_parental.php api_domain_lists.php
  )

  local includes_php=(
    auth.php db_connect.php header.php navbar.php server_info.php
  )

  local backend_php=(
    build_rpz_per_device.php
  )

  local config_files=(
    env_loader.php named.conf.local.example .env sudoers.d_mbox
  )

  local data_files=(
    blacklist.txt blacklist.txt.example knowdomain.txt domain_lists_summary.json
  )

  local f
  for f in "${public_php[@]}"; do
    move_rel "$f" "public/$f"
  done

  for f in "${includes_php[@]}"; do
    move_rel "$f" "includes/$f"
  done

  for f in "${backend_php[@]}"; do
    move_rel "$f" "bin/php/$f"
  done

  for f in "${config_files[@]}"; do
    move_rel "$f" "config/$f"
  done

  for f in "${data_files[@]}"; do
    move_rel "$f" "data/$f"
  done

  move_rel "style.css" "public/assets/css/style.css"
  move_rel "parental.js" "public/assets/js/parental.js"
  move_rel ".htaccess" "public/.htaccess"

  if [ -d "$PROJECT_ROOT/speedtest" ]; then
    # On shared folders, this move may be denied by ownership/lock semantics.
    # Keep migration going and leave the folder in place if it cannot be moved.
    if ! move_path "$PROJECT_ROOT/speedtest" "$DATA_DIR/speedtest"; then
      warn "Could not move speedtest directory; leaving original folder in place"
    fi
  fi

  local sh
  for sh in "$PROJECT_ROOT"/*.sh; do
    [ -f "$sh" ] || continue
    local base
    base="$(basename "$sh")"

    case "$base" in
      reorganize.sh|fix_history.sh)
        continue
        ;;
      dns_block_test.sh|dns_levenshtein_quick_test.sh|diag_parental.sh|diag_rpz.sh)
        move_path "$sh" "$BIN_BASH_DIR/tests/$base"
        ;;
      *)
        move_path "$sh" "$BIN_BASH_DIR/$base"
        ;;
    esac
  done

  local py
  for py in "$PROJECT_ROOT"/*.py; do
    [ -f "$py" ] || continue
    move_path "$py" "$BIN_PY_DIR/$(basename "$py")"
  done

  local sql
  for sql in "$PROJECT_ROOT"/*.sql; do
    [ -f "$sql" ] || continue
    move_path "$sql" "$BIN_SQL_DIR/$(basename "$sql")"
  done
}

write_paths_php() {
  local file="$INCLUDES_DIR/paths.php"

  log "Generate includes/paths.php constants"

  if [ "$DRY_RUN" = "1" ]; then
    log "DRY write $file"
    return 0
  fi

  cat > "$file" <<'PHP'
<?php
if (!defined('MBOX_PROJECT_ROOT')) {
    define('MBOX_PROJECT_ROOT', realpath(__DIR__ . '/..'));
}

if (!defined('MBOX_PUBLIC_DIR')) {
    define('MBOX_PUBLIC_DIR', MBOX_PROJECT_ROOT . '/public');
}

if (!defined('MBOX_INCLUDES_DIR')) {
    define('MBOX_INCLUDES_DIR', MBOX_PROJECT_ROOT . '/includes');
}

if (!defined('MBOX_CONFIG_DIR')) {
    define('MBOX_CONFIG_DIR', MBOX_PROJECT_ROOT . '/config');
}

if (!defined('MBOX_BIN_DIR')) {
    define('MBOX_BIN_DIR', MBOX_PROJECT_ROOT . '/bin');
}

if (!defined('MBOX_BIN_BASH')) {
    define('MBOX_BIN_BASH', MBOX_BIN_DIR . '/bash');
}

if (!defined('MBOX_BIN_PYTHON')) {
    define('MBOX_BIN_PYTHON', MBOX_BIN_DIR . '/python');
}

if (!defined('MBOX_BIN_SQL')) {
    define('MBOX_BIN_SQL', MBOX_BIN_DIR . '/sql');
}

if (!defined('MBOX_BIN_PHP')) {
    define('MBOX_BIN_PHP', MBOX_BIN_DIR . '/php');
}

if (!defined('MBOX_DATA_DIR')) {
    define('MBOX_DATA_DIR', MBOX_PROJECT_ROOT . '/data');
}
PHP
}

write_functions_php_if_missing() {
  local file="$INCLUDES_DIR/functions.php"

  if [ -f "$file" ]; then
    return 0
  fi

  log "Create includes/functions.php placeholder"

  if [ "$DRY_RUN" = "1" ]; then
    log "DRY write $file"
    return 0
  fi

  cat > "$file" <<'PHP'
<?php
// Shared helpers can be added here.
PHP
}

patch_php_paths() {
  log "Patch PHP includes, assets and script paths"

  local php
  for php in "$PUBLIC_DIR"/*.php; do
    [ -f "$php" ] || continue

    replace_literal "$php" "require_once 'auth.php';" "require_once __DIR__ . '/../includes/auth.php';"
    replace_literal "$php" "require_once 'server_info.php';" "require_once __DIR__ . '/../includes/server_info.php';"
    replace_literal "$php" "require_once 'db_connect.php';" "require_once __DIR__ . '/../includes/db_connect.php';"

    replace_literal "$php" "<?php require_once 'header.php'; ?>" "<?php require_once __DIR__ . '/../includes/header.php'; ?>"
    replace_literal "$php" "<?php require_once 'navbar.php'; ?>" "<?php require_once __DIR__ . '/../includes/navbar.php'; ?>"

    replace_literal "$php" "include 'header.php';" "include __DIR__ . '/../includes/header.php';"
    replace_literal "$php" "include 'navbar.php';" "include __DIR__ . '/../includes/navbar.php';"
  done

  replace_literal "$INCLUDES_DIR/db_connect.php" "require_once __DIR__ . '/env_loader.php';" "require_once __DIR__ . '/../config/env_loader.php';"

  replace_literal "$INCLUDES_DIR/header.php" "<link rel=\"stylesheet\" href=\"style.css\">" "<link rel=\"stylesheet\" href=\"assets/css/style.css\">"
  replace_literal "$PUBLIC_DIR/login.php" "<link rel=\"stylesheet\" href=\"style.css\">" "<link rel=\"stylesheet\" href=\"assets/css/style.css\">"
  replace_literal "$PUBLIC_DIR/parental.php" "<script src=\"parental.js\"></script>" "<script src=\"assets/js/parental.js\"></script>"

  insert_after_literal "$PUBLIC_DIR/change_ip.php" "require_once __DIR__ . '/../includes/server_info.php';" "require_once __DIR__ . '/../includes/paths.php';"
  insert_after_literal "$PUBLIC_DIR/config_dns.php" "require_once __DIR__ . '/../includes/server_info.php';" "require_once __DIR__ . '/../includes/paths.php';"
  insert_after_literal "$PUBLIC_DIR/dhcp.php" "require_once __DIR__ . '/../includes/server_info.php';" "require_once __DIR__ . '/../includes/paths.php';"
  insert_after_literal "$PUBLIC_DIR/dhcp_avance.php" "require_once __DIR__ . '/../includes/server_info.php';" "require_once __DIR__ . '/../includes/paths.php';"
  insert_after_literal "$PUBLIC_DIR/api_parental.php" "header('Content-Type: application/json');" "require_once __DIR__ . '/../includes/paths.php';"

  replace_literal "$PUBLIC_DIR/change_ip.php" '$cmd = "sudo ./change_ip.sh " . escapeshellarg($interface) . " " . escapeshellarg($new_ip) . " " . escapeshellarg($dns_arg) . " 2>&1";' '$cmd = "sudo " . escapeshellarg(MBOX_BIN_BASH . "/change_ip.sh") . " " . escapeshellarg($interface) . " " . escapeshellarg($new_ip) . " " . escapeshellarg($dns_arg) . " 2>&1";'
  replace_literal "$PUBLIC_DIR/config_dns.php" '$cmd = "sudo ./config_dns.sh " . escapeshellarg($domain) . " " . escapeshellarg($current_ip) . " 2>&1";' '$cmd = "sudo " . escapeshellarg(MBOX_BIN_BASH . "/config_dns.sh") . " " . escapeshellarg($domain) . " " . escapeshellarg($current_ip) . " 2>&1";'
  replace_literal "$PUBLIC_DIR/dhcp.php" '$cmd = "sudo ./dhcp.sh " . escapeshellarg($network) . " " . escapeshellarg($dhcp_start) . " " . escapeshellarg($dhcp_end) . " " . escapeshellarg($current_ip) . " 2>&1";' '$cmd = "sudo " . escapeshellarg(MBOX_BIN_BASH . "/dhcp.sh") . " " . escapeshellarg($network) . " " . escapeshellarg($dhcp_start) . " " . escapeshellarg($dhcp_end) . " " . escapeshellarg($current_ip) . " 2>&1";'
  replace_literal "$PUBLIC_DIR/dhcp_avance.php" '$cmd = "./dhcp_avance.sh " . escapeshellarg($network) . " " . escapeshellarg($dhcp_start) . " " . escapeshellarg($dhcp_end) . " " . escapeshellarg($current_ip) . " 2>&1";' '$cmd = "sudo " . escapeshellarg(MBOX_BIN_BASH . "/dhcp_avance.sh") . " " . escapeshellarg($network) . " " . escapeshellarg($dhcp_start) . " " . escapeshellarg($dhcp_end) . " " . escapeshellarg($current_ip) . " 2>&1";'

  replace_literal "$PUBLIC_DIR/api_parental.php" "\$script = __DIR__ . '/apply_parental.sh';" "\$script = MBOX_BIN_BASH . '/apply_parental.sh';"

  replace_literal "$PUBLIC_DIR/api_domain_lists.php" "\$envLoader = __DIR__ . '/env_loader.php';" "\$envLoader = __DIR__ . '/../config/env_loader.php';"
  replace_literal "$PUBLIC_DIR/api_domain_lists.php" "__DIR__ . '/sync_blacklist_rpz.sh'," "__DIR__ . '/../bin/bash/sync_blacklist_rpz.sh',"
  replace_literal "$PUBLIC_DIR/api_domain_lists.php" "'/var/www/html/sync_blacklist_rpz.sh'," "__DIR__ . '/../bin/bash/sync_blacklist_rpz.sh',"
  replace_literal "$PUBLIC_DIR/api_domain_lists.php" "return __DIR__ . DIRECTORY_SEPARATOR . \$name;" "return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . \$name;"
  replace_literal "$PUBLIC_DIR/api_domain_lists.php" "__DIR__ . '/' . \$filename," "dirname(__DIR__) . '/data/' . \$filename,"
}

patch_shell_scripts() {
  log "Patch bash/python/sql references"

  replace_literal "$BIN_BASH_DIR/refresh_rpz.sh" 'SCRIPT="/var/www/html/build_rpz_per_device.php"' $'SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"\nPROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"\nSCRIPT="$PROJECT_ROOT/bin/php/build_rpz_per_device.php"'

  replace_literal "$BIN_BASH_DIR/watch_domain_lists.sh" 'SYNC_SCRIPT="/var/www/html/refresh_rpz.sh"' 'SYNC_SCRIPT="$PROJECT_ROOT/bin/bash/refresh_rpz.sh"'
  replace_literal "$BIN_BASH_DIR/watch_domain_lists.sh" 'NORM_SCRIPT="/var/www/html/normalize_domain_lists.py"' 'NORM_SCRIPT="$PROJECT_ROOT/bin/python/normalize_domain_lists.py"'
  insert_after_literal "$BIN_BASH_DIR/watch_domain_lists.sh" 'set -euo pipefail' $'SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"\nPROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"'

  replace_literal "$BIN_BASH_DIR/install_domain_watch_service.sh" 'WEBROOT="/var/www/html"' $'SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"\nWEBROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"'
  replace_literal "$BIN_BASH_DIR/install_domain_watch_service.sh" 'WATCH_SCRIPT="$WEBROOT/watch_domain_lists.sh"' 'WATCH_SCRIPT="$WEBROOT/bin/bash/watch_domain_lists.sh"'
  replace_literal "$BIN_BASH_DIR/install_domain_watch_service.sh" 'ExecStart=/bin/bash /var/www/html/watch_domain_lists.sh' 'ExecStart=/bin/bash $WATCH_SCRIPT'
  replace_literal "$BIN_BASH_DIR/install_domain_watch_service.sh" "<<'EOF'" "<<EOF"

  replace_literal "$BIN_BASH_DIR/init_dns_events_db.sh" 'SQL_FILE="${1:-/var/www/html/create_dns_events_table.sql}"' $'SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"\nPROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"\nSQL_FILE="${1:-$PROJECT_ROOT/bin/sql/create_dns_events_table.sql}"'

  insert_after_literal "$BIN_BASH_DIR/config_dns.sh" 'REV_ZONE_FILE="/etc/bind/db.100.168.192"' 'SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"'
  replace_literal "$BIN_BASH_DIR/config_dns.sh" 'sudo /var/www/html/config_vhost.sh "$DOMAIN"' 'sudo "$SCRIPT_DIR/config_vhost.sh" "$DOMAIN"'
  replace_literal "$BIN_BASH_DIR/config_dns.sh" 'sudo /var/www/html/update_operator_dns.sh "$DOMAIN" "$WAN_IP"' 'sudo "$SCRIPT_DIR/update_operator_dns.sh" "$DOMAIN" "$WAN_IP"'

  insert_after_literal "$BIN_BASH_DIR/config_vhost.sh" 'DOMAIN_REGEX="${DOMAIN//./\\.}"' $'SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"\nPROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"\nPUBLIC_DIR="$PROJECT_ROOT/public"'
  replace_literal "$BIN_BASH_DIR/config_vhost.sh" '/var/www/html' '$PUBLIC_DIR'

  if [ "$DRY_RUN" = "1" ]; then
    log "DRY chmod +x on bash scripts"
  else
    find "$BIN_BASH_DIR" -type f -name '*.sh' -exec chmod +x {} +
  fi
}

rewrite_sudoers_template() {
  log "Rewrite sudoers template with new absolute paths"

  local sudoers_file="$CONFIG_DIR/sudoers.d_mbox"

  if [ "$DRY_RUN" = "1" ]; then
    log "DRY write $sudoers_file"
  else
    cat > "$sudoers_file" <<EOF
# /etc/sudoers.d/mbox
# Generated by reorganize.sh on ${TIMESTAMP}
# Minimal permissions for MBox web actions.

www-data ALL=(root) NOPASSWD: /bin/cp, /bin/chown, /usr/sbin/named-checkzone, /bin/systemctl reload bind9, /usr/bin/php ${BIN_PHP_DIR}/build_rpz_per_device.php, ${BIN_BASH_DIR}/refresh_rpz.sh, ${BIN_BASH_DIR}/sync_blacklist_rpz.sh *, ${BIN_BASH_DIR}/apply_parental.sh *, ${BIN_BASH_DIR}/dhcp.sh *, ${BIN_BASH_DIR}/dhcp_avance.sh *, ${BIN_BASH_DIR}/change_ip.sh *, ${BIN_BASH_DIR}/config_dns.sh *
EOF
  fi

  if command -v sudo >/dev/null 2>&1 && [ -d /etc/sudoers.d ]; then
    run_sudo_shell "cp '$sudoers_file' /etc/sudoers.d/mbox"
    run_sudo_shell "chmod 440 /etc/sudoers.d/mbox"
    if [ "$DRY_RUN" != "1" ]; then
      if ! sudo visudo -c >/dev/null 2>&1; then
        warn "visudo check failed, review /etc/sudoers.d/mbox"
      fi
    fi
  else
    warn "sudo or /etc/sudoers.d unavailable, sudoers not installed"
  fi
}

patch_cron_and_systemd() {
  if [ "$NO_CRON_SYSTEMD" = "1" ]; then
    log "Cron/systemd patch skipped (NO_CRON_SYSTEMD=1)"
    return 0
  fi

  log "Patch cron and systemd references"

  if command -v crontab >/dev/null 2>&1; then
    local current_cron updated_cron
    current_cron="$(crontab -l 2>/dev/null || true)"

    if [ -n "$current_cron" ]; then
      updated_cron="$(printf '%s\n' "$current_cron" | sed \
        -e "s|/var/www/html/refresh_rpz.sh|$BIN_BASH_DIR/refresh_rpz.sh|g" \
        -e "s|/var/www/html/build_rpz_per_device.php|$BIN_PHP_DIR/build_rpz_per_device.php|g" \
        -e "s|/var/www/html/watch_domain_lists.sh|$BIN_BASH_DIR/watch_domain_lists.sh|g")"

      if [ "$current_cron" != "$updated_cron" ]; then
        if [ "$DRY_RUN" = "1" ]; then
          log "DRY update user crontab"
        else
          printf '%s\n' "$updated_cron" | crontab -
        fi
      fi
    fi
  fi

  if [ -d /etc/systemd/system ] && command -v sudo >/dev/null 2>&1; then
    local service_files
    service_files="$(sudo grep -RIl '/var/www/html/watch_domain_lists.sh\|/var/www/html/refresh_rpz.sh\|/var/www/html/build_rpz_per_device.php' /etc/systemd/system 2>/dev/null || true)"

    if [ -n "$service_files" ]; then
      local svc
      while IFS= read -r svc; do
        [ -n "$svc" ] || continue
        run_sudo_shell "cp '$svc' '${svc}.bak.${TIMESTAMP}'"
        run_sudo_shell "perl -0777 -i -pe 's|/var/www/html/watch_domain_lists.sh|$BIN_BASH_DIR/watch_domain_lists.sh|g; s|/var/www/html/refresh_rpz.sh|$BIN_BASH_DIR/refresh_rpz.sh|g; s|/var/www/html/build_rpz_per_device.php|$BIN_PHP_DIR/build_rpz_per_device.php|g' '$svc'"
      done <<< "$service_files"

      run_sudo_shell "systemctl daemon-reload"
    fi
  fi
}

patch_apache_confs() {
  if [ "$NO_APACHE" = "1" ]; then
    log "Apache patch skipped (NO_APACHE=1)"
    return 0
  fi

  if [ ! -d /etc/apache2 ]; then
    warn "/etc/apache2 not found, skipping Apache patch"
    return 0
  fi

  if ! command -v sudo >/dev/null 2>&1; then
    warn "sudo not found, skipping Apache patch"
    return 0
  fi

  log "Patch Apache DocumentRoot and Directory to public/"

  local conf_files
  conf_files="$(sudo grep -RIl 'DocumentRoot[[:space:]]\+/var/www/html\|<Directory[[:space:]]\+/var/www/html' /etc/apache2/sites-available /etc/apache2/sites-enabled 2>/dev/null || true)"

  if [ -z "$conf_files" ]; then
    log "No Apache vhost containing old /var/www/html references"
    return 0
  fi

  local conf
  while IFS= read -r conf; do
    [ -n "$conf" ] || continue
    run_sudo_shell "cp '$conf' '${conf}.bak.${TIMESTAMP}'"
    run_sudo_shell "perl -0777 -i -pe 's|DocumentRoot\\s+/var/www/html\\S*|DocumentRoot $PUBLIC_DIR|g; s|<Directory\\s+/var/www/html\\S*>|<Directory $PUBLIC_DIR>|g' '$conf'"
  done <<< "$conf_files"

  if [ "$DRY_RUN" = "1" ]; then
    log "DRY apachectl -t and reload apache2"
  else
    sudo apachectl -t
    sudo systemctl reload apache2
  fi
}

final_sanity() {
  log "Final sanity checks"

  if [ "$DRY_RUN" = "1" ]; then
    log "DRY sanity checks skipped"
    return 0
  fi

  [ -d "$PUBLIC_DIR" ] || die "Missing $PUBLIC_DIR"
  [ -d "$INCLUDES_DIR" ] || die "Missing $INCLUDES_DIR"
  [ -d "$BIN_BASH_DIR" ] || die "Missing $BIN_BASH_DIR"
  [ -d "$BIN_PY_DIR" ] || die "Missing $BIN_PY_DIR"
  [ -d "$BIN_SQL_DIR" ] || die "Missing $BIN_SQL_DIR"
  [ -d "$BIN_PHP_DIR" ] || die "Missing $BIN_PHP_DIR"

  if [ -f "$PUBLIC_DIR/login.php" ] && [ -f "$INCLUDES_DIR/db_connect.php" ]; then
    log "Core web files moved"
  else
    warn "Some expected files are missing after move"
  fi
}

main() {
  log "Starting project reorganization in $PROJECT_ROOT"

  cleanup_parasites
  git_baseline
  create_tree
  move_files
  write_paths_php
  write_functions_php_if_missing

  patch_php_paths
  patch_shell_scripts
  rewrite_sudoers_template
  patch_cron_and_systemd
  patch_apache_confs

  final_sanity

  log "Done. Next recommended checks:"
  log "1) sudo apachectl -t"
  log "2) sudo systemctl status bind9 apache2"
  log "3) php -l $PUBLIC_DIR/*.php"
  log "4) Open /login.php and test dashboard + DHCP/DNS actions"
}

main "$@"
