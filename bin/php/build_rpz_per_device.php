<?php
/**
 * build_rpz_per_device.php
 *
 * Genere une zone RPZ BIND et une view par device configure dans
 * /etc/mbox/parental_config.json, en resolvant le MAC -> IP courante
 * via /var/lib/dhcp/dhcpd.leases.
 *
 * Ecrit :
 *   - /etc/bind/db.rpz.mbox.<mac_hex>   (une zone par device)
 *   - /etc/bind/named.conf.views.inc    (toutes les acl + views)
 *
 * Appele :
 *   - par cron (chaque minute) en tant que root
 *   - par apply_parental.sh a chaque save depuis l'UI
 *   - manuellement pour debug
 *
 * Non destructif : si rien ne change, touche rien, ne recharge pas BIND.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

const PARENTAL_CONFIG = '/etc/mbox/parental_config.json';
const BLACKLIST_FILE  = '/etc/mbox/dns/blacklist.txt';
const DHCP_LEASES     = '/var/lib/dhcp/dhcpd.leases';
const BIND_DIR        = '/etc/bind';
const VIEWS_INCLUDE   = '/etc/bind/named.conf.views.inc';
const GLOBAL_ZONE_FILE = '/etc/bind/db.rpz.mbox';
const STATE_FILE      = '/var/lib/mbox/rpz_state.json';

// UI toggles -> balises dans blacklist.txt (identique en majuscules)
const FILTER_TO_CAT = [
    'adult'     => 'ADULT',
    'games'     => 'GAMES',
    'streaming' => 'STREAMING',
    'social'    => 'SOCIAL',
    'ads'       => 'ADS',
];

// Modes qui declenchent un filtre personalise (correspond au DNAT dans apply_parental.sh)
const MODES_WITH_DNAT = ['child', 'teen', 'custom'];

function log_info($msg) { echo "[build_rpz] $msg\n"; }
function log_err($msg)  { fwrite(STDERR, "[build_rpz][ERR] $msg\n"); }

function normalize_domain($d) {
    $d = strtolower(trim($d));
    $d = preg_replace('#^[a-z]+://#', '', $d);
    $d = preg_replace('#/.*$#', '', $d);
    $d = preg_replace('/:\d+$/', '', $d);
    $d = preg_replace('/[^a-z0-9.-]/', '', $d);
    $d = preg_replace('/\.+/', '.', $d);
    $d = trim($d, '.');
    $d = preg_replace('/^www\./', '', $d);
    return $d;
}

function to_root_domain($d) {
    $parts = array_values(array_filter(explode('.', $d), 'strlen'));
    $n = count($parts);
    if ($n >= 2) return $parts[$n - 2] . '.' . $parts[$n - 1];
    return $n === 1 ? $parts[0] : '';
}

/**
 * Parse blacklist.txt avec balises ## CATEGORY ## .
 * Rétrocompat : les lignes avant toute balise -> categorie _UNCATEGORIZED
 * (appliquee a tous les profils comme baseline commune).
 */
function parse_blacklist_categorized($path) {
    if (!is_readable($path)) return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $cats = ['_UNCATEGORIZED' => []];
    $cur = '_UNCATEGORIZED';
    foreach ($lines as $line) {
        $l = trim($line);
        if ($l === '') continue;
        if (preg_match('/^##\s*([A-Z0-9_]+)\s*##/i', $l, $m)) {
            $cur = strtoupper($m[1]);
            if (!isset($cats[$cur])) $cats[$cur] = [];
            continue;
        }
        if ($l[0] === '#') continue;
        $norm = normalize_domain($l);
        $root = to_root_domain($norm);
        if ($root === '' || strpos($root, '.') === false) continue;
        $cats[$cur][] = $root;
    }
    foreach ($cats as $k => $v) $cats[$k] = array_values(array_unique($v));
    return $cats;
}

/**
 * Lit dhcpd.leases. Retourne MAC_UPPERCASE => IP_COURANTE.
 * Dernier bail actif gagne (fichier append-only, meme logique que parse_dhcp_leases).
 */
function parse_leases($path) {
    if (!is_readable($path)) return [];
    $c = @file_get_contents($path);
    if ($c === false) return [];
    $map = [];
    if (preg_match_all('/lease\s+([\d\.]+)\s*\{([^}]*)\}/s', $c, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $ip = $m[1];
            $block = $m[2];
            if (!preg_match('/binding\s+state\s+active/i', $block)) continue;
            if (preg_match('/hardware\s+ethernet\s+([\da-f:]+)/i', $block, $hw)) {
                $mac = strtoupper($hw[1]);
                $map[$mac] = $ip;
            }
        }
    }
    return $map;
}

/**
 * Calcule pour chaque device : cat a bloquer + IP courante.
 * Retourne tableau indexe par MAC majuscule.
 */
function build_device_plans($config, $ip_map) {
    $plans = [];
    foreach ($config as $mac => $entry) {
        $mac_u = strtoupper($mac);
        $mode = $entry['mode'] ?? 'adult';
        if (!in_array($mode, MODES_WITH_DNAT, true)) continue;

        $filters = $entry['filters'] ?? [];
        $cats = [];
        foreach (FILTER_TO_CAT as $fkey => $ctag) {
            if (!empty($filters[$fkey])) $cats[] = $ctag;
        }
        if (empty($cats)) continue;

        $ip = $ip_map[$mac_u] ?? null;
        if ($ip === null) continue; // device offline ou jamais connecte

        $plans[$mac_u] = [
            'ip'   => $ip,
            'cats' => $cats,
            'name' => $entry['name'] ?? $mac_u,
            'mode' => $mode,
        ];
    }
    return $plans;
}

/**
 * Genere le contenu d'une zone RPZ a partir d'une liste de domaines root.
 * Format identique a sync_blacklist_rpz.sh (NXDOMAIN via CNAME .).
 */
function render_zone($domains, $serial) {
    $h  = "\$TTL 60\n";
    $h .= "@   IN  SOA localhost. root.localhost. (\n";
    $h .= "        $serial ; Serial\n";
    $h .= "        60      ; Refresh\n";
    $h .= "        60      ; Retry\n";
    $h .= "        60      ; Expire\n";
    $h .= "        60 )    ; Minimum\n";
    $h .= "    IN  NS  localhost.\n";
    $body = '';
    foreach ($domains as $d) {
        $body .= "$d    IN    CNAME    .\n";
        $body .= "*.$d    IN    CNAME    .\n";
    }
    return $h . $body;
}

/**
 * Exec sudo/cp/chown sur un fichier temporaire vers la destination.
 * $asRoot=true si on est deja root (cron), false si on doit passer par sudo (cas apply_parental.sh = deja root aussi).
 */
function install_file($tmp_path, $dest_path, $owner = 'bind:bind') {
    // Toujours via sudo pour etre safe, meme en root sudo ne demande rien
    @exec('sudo cp ' . escapeshellarg($tmp_path) . ' ' . escapeshellarg($dest_path));
    @exec('sudo chown ' . escapeshellarg($owner) . ' ' . escapeshellarg($dest_path));
}

function check_zone($zone_name, $zone_file) {
    $out = []; $rc = 0;
    @exec('sudo named-checkzone ' . escapeshellarg($zone_name) . ' ' . escapeshellarg($zone_file) . ' 2>&1', $out, $rc);
    return $rc === 0;
}

function reload_bind() {
    @exec('sudo systemctl reload bind9 2>&1', $out, $rc);
    if ($rc !== 0) {
        log_err("reload bind9 rc=$rc : " . implode(' | ', $out));
        return false;
    }
    log_info("reload bind9 OK");
    return true;
}

/**
 * Construit le contenu complet de named.conf.views.inc.
 * Chaque view a ses propres zones (BIND impose : si views -> toutes les zones doivent etre dans une view).
 */
function render_views_include($generated) {
    $out  = "// Auto-generated by build_rpz_per_device.php - DO NOT EDIT MANUALLY\n";
    $out .= "// Regenerated on : " . date('Y-m-d H:i:s') . "\n\n";

    // Une view par device
    foreach ($generated as $mac => $g) {
        $label = $g['label'];
        $out .= "acl acl_{$label} { {$g['ip']}; };\n";
        $out .= "view \"view_{$label}\" {\n";
        $out .= "    match-clients { acl_{$label}; };\n";
        $out .= "    recursion yes;\n";
        $out .= "    response-policy { zone \"{$g['zone_name']}\"; };\n";
        $out .= "    zone \"{$g['zone_name']}\" { type master; file \"{$g['zone_path']}\"; };\n";
        $out .= "    // Forward unknown -> upstream\n";
        $out .= "};\n\n";
    }

    // View default (tout le reste : non configure, ou mode adult)
    // Inclut les zones par defaut d'Ubuntu (localhost, etc) qui doivent etre
    // dans une view des qu'on utilise des views.
    $out .= "view \"default\" {\n";
    $out .= "    match-clients { any; };\n";
    $out .= "    recursion yes;\n";
    $out .= "    response-policy { zone \"rpz.mbox.local\"; };\n";
    $out .= "    zone \"rpz.mbox.local\" { type master; file \"" . GLOBAL_ZONE_FILE . "\"; };\n";
    $out .= "    include \"/etc/bind/named.conf.default-zones\";\n";
    $out .= "};\n";

    return $out;
}

/**
 * Compare un signature d'etat pour eviter de recharger BIND si rien n'a change.
 */
function compute_state_signature($generated, $categories_hash) {
    $sig = ['cat_hash' => $categories_hash, 'devices' => []];
    foreach ($generated as $mac => $g) {
        $sig['devices'][$mac] = [
            'ip'      => $g['ip'],
            'hash'    => $g['content_hash'],
        ];
    }
    return md5(json_encode($sig));
}

function load_prev_state() {
    if (!is_readable(STATE_FILE)) return null;
    $c = @file_get_contents(STATE_FILE);
    return $c !== false ? trim($c) : null;
}

function save_state($sig) {
    $dir = dirname(STATE_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents(STATE_FILE, $sig);
}

// =================================================================
// MAIN
// =================================================================
function main() {
    // 1. Charger la config
    $config = [];
    if (is_readable(PARENTAL_CONFIG)) {
        $raw = @file_get_contents(PARENTAL_CONFIG);
        $config = json_decode($raw, true) ?: [];
    }

    // 2. Parser blacklist categorisee
    $categories = parse_blacklist_categorized(BLACKLIST_FILE);
    $cat_hash = md5(json_encode($categories));

    // 3. Lire leases
    $ip_map = parse_leases(DHCP_LEASES);

    // 4. Calculer plans par device
    $plans = build_device_plans($config, $ip_map);

    // 5. Pour chaque plan, preparer le contenu de zone (sans ecrire encore)
    $generated = [];
    $serial = time();
    foreach ($plans as $mac => $p) {
        $mac_hex = strtolower(str_replace(':', '', $mac));

        $domains = [];
        foreach ($p['cats'] as $cat) {
            if (isset($categories[$cat])) {
                $domains = array_merge($domains, $categories[$cat]);
            }
        }
        // Baseline commune (domaines avant toute balise dans blacklist.txt)
        if (!empty($categories['_UNCATEGORIZED'])) {
            $domains = array_merge($domains, $categories['_UNCATEGORIZED']);
        }
        $domains = array_values(array_unique($domains));
        sort($domains);

        $zone_name = "rpz.mbox.dev$mac_hex";
        $zone_path = BIND_DIR . "/db.rpz.mbox.$mac_hex";
        $content   = render_zone($domains, $serial);
        $label     = 'dev' . $mac_hex;

        $generated[$mac] = [
            'zone_name'    => $zone_name,
            'zone_path'    => $zone_path,
            'ip'           => $p['ip'],
            'label'        => $label,
            'name'         => $p['name'],
            'content'      => $content,
            // Hash sur le CONTENU moins le serial (qui change toujours)
            'content_hash' => md5(preg_replace('/\d+ ; Serial/', 'X ; Serial', $content)),
            'domain_count' => count($domains),
        ];
    }

    // 6. Check signature : si identique, on sort sans toucher a rien
    $new_sig = compute_state_signature($generated, $cat_hash);
    $prev_sig = load_prev_state();
    if ($prev_sig === $new_sig) {
        log_info("Aucun changement detecte. Rien a faire.");
        return;
    }

    // 7. Ecrire les fichiers de zone
    $valid_generated = [];
    foreach ($generated as $mac => $g) {
        $tmp = tempnam(sys_get_temp_dir(), 'rpz_');
        file_put_contents($tmp, $g['content']);

        if (!check_zone($g['zone_name'], $tmp)) {
            log_err("Zone invalide pour $mac (skip)");
            @unlink($tmp);
            continue;
        }
        install_file($tmp, $g['zone_path'], 'bind:bind');
        @unlink($tmp);
        $valid_generated[$mac] = $g;
        log_info(sprintf("Zone %s : %s -> %s (%d dom, IP %s)",
            $g['label'], $g['name'], basename($g['zone_path']),
            $g['domain_count'], $g['ip']));
    }

    // 8. Ecrire l'include views
    $views_content = render_views_include($valid_generated);
    $tmp = tempnam(sys_get_temp_dir(), 'views_');
    file_put_contents($tmp, $views_content);
    install_file($tmp, VIEWS_INCLUDE, 'bind:bind');
    @unlink($tmp);
    log_info("Ecrit " . VIEWS_INCLUDE . " (" . count($valid_generated) . " device(s) + default view)");

    // 9. Reload BIND
    if (reload_bind()) {
        save_state($new_sig);
    }
}

main();
