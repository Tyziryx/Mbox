<?php
// Recupere toutes les infos reseau du serveur (WAN, LAN, DHCP, DNS)
// Fonction get_server_info() retourne un tableau avec tout

function parse_bind_zone_map(array $paths): array {
    $zone_map = [];

    foreach ($paths as $path) {
        if (!is_readable($path)) {
            continue;
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            continue;
        }

        if (!preg_match_all('/zone\s+"([^"]+)"\s*\{(.*?)\};/is', $content, $matches, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($matches as $match) {
            $zone = trim((string)$match[1]);
            $block = (string)$match[2];
            if ($zone === '') {
                continue;
            }

            if (!preg_match('/file\s+"([^"]+)"/i', $block, $file_match)) {
                continue;
            }

            $zone_file = trim((string)$file_match[1]);
            if ($zone_file === '') {
                continue;
            }

            if (!isset($zone_map[$zone])) {
                $zone_map[$zone] = [
                    'zone' => $zone,
                    'file' => $zone_file,
                ];
            }
        }
    }

    return array_values($zone_map);
}

function is_candidate_public_dns_zone(string $zone): bool {
    $zone_l = strtolower(trim($zone));
    if ($zone_l === '') {
        return false;
    }

    if ($zone_l === '.' || $zone_l === 'root') {
        return false;
    }

    $ignored_exact = ['localhost', 'localdomain', '0.in-addr.arpa', '127.in-addr.arpa', '255.in-addr.arpa'];
    if (in_array($zone_l, $ignored_exact, true)) {
        return false;
    }

    if (substr($zone_l, -13) === '.in-addr.arpa' || substr($zone_l, -9) === '.ip6.arpa') {
        return false;
    }

    if (strpos($zone_l, 'rpz.') === 0 || strpos($zone_l, '.rpz.') !== false) {
        return false;
    }

    return true;
}

function pick_primary_dns_zone_from_db_files(string $bind_dir = '/etc/bind'): array {
    $files = glob(rtrim($bind_dir, '/') . '/db.*');
    if (!is_array($files) || empty($files)) {
        return ['zone' => '', 'file' => ''];
    }

    $candidates = [];
    foreach ($files as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }

        $base = basename($file);
        if (strpos($base, 'db.') !== 0) {
            continue;
        }

        $zone = substr($base, 3);
        $zone_l = strtolower($zone);
        if ($zone_l === '' || $zone_l === 'root' || $zone_l === 'local' || $zone_l === 'empty') {
            continue;
        }
        if ($zone_l === '0' || $zone_l === '127' || $zone_l === '255') {
            continue;
        }
        if (preg_match('/^\d+(\.\d+){1,3}$/', $zone_l)) {
            continue;
        }
        if (strpos($zone_l, 'rpz.') === 0 || strpos($zone_l, '.rpz.') !== false) {
            continue;
        }

        $candidates[] = [
            'zone' => $zone,
            'file' => $file,
            'mtime' => @filemtime($file) ?: 0,
        ];
    }

    if (empty($candidates)) {
        return ['zone' => '', 'file' => ''];
    }

    usort($candidates, function($a, $b) {
        $az = strtolower((string)$a['zone']);
        $bz = strtolower((string)$b['zone']);
        $as = (strpos($az, 'ceri.com') !== false) ? 2 : 1;
        $bs = (strpos($bz, 'ceri.com') !== false) ? 2 : 1;
        if ($as !== $bs) {
            return $bs - $as;
        }

        $am = (int)$a['mtime'];
        $bm = (int)$b['mtime'];
        if ($am !== $bm) {
            return $bm - $am;
        }

        return strcmp($az, $bz);
    });

    return ['zone' => $candidates[0]['zone'], 'file' => $candidates[0]['file']];
}

function pick_primary_dns_zone(array $zone_map): array {
    $candidates = [];
    foreach ($zone_map as $entry) {
        $zone = (string)($entry['zone'] ?? '');
        $file = (string)($entry['file'] ?? '');
        if (!is_candidate_public_dns_zone($zone)) {
            continue;
        }
        if ($file === '') {
            continue;
        }
        $candidates[] = ['zone' => $zone, 'file' => $file];
    }

    if (empty($candidates)) {
        return ['zone' => '', 'file' => ''];
    }

    usort($candidates, function($a, $b) {
        $az = strtolower((string)$a['zone']);
        $bz = strtolower((string)$b['zone']);
        $as = (strpos($az, 'ceri.com') !== false) ? 2 : 1;
        $bs = (strpos($bz, 'ceri.com') !== false) ? 2 : 1;

        if ($as === $bs) {
            return strcmp($az, $bz);
        }

        return $bs - $as;
    });

    return $candidates[0];
}

function extract_dns_records_from_zone_file(string $zone_file): string {
    if ($zone_file === '' || !is_readable($zone_file)) {
        return '';
    }

    $lines = file($zone_file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return '';
    }

    $records = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || strpos($line, ';') === 0) {
            continue;
        }

        $line = (string)preg_replace('/\s*;.*$/', '', $line);
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/\sIN\s+(A|NS|SOA|CNAME|MX)\b/i', $line)) {
            $records[] = $line;
        }
    }

    return trim(implode(PHP_EOL, $records));
}

function get_server_info() {
    $info = [];
    
    // Recuperation IP WAN (eth0 = vers Internet)
    $info['wan_ip'] = trim(shell_exec("ip addr show eth0 2>/dev/null | grep 'inet ' | awk '{print \$2}' | cut -d'/' -f1"));

    // Recuperation IP LAN (eth1 = vers clients)
    $info['lan_ip'] = trim(shell_exec("ip addr show eth1 2>/dev/null | grep 'inet ' | awk '{print \$2}' | cut -d'/' -f1"));
    $info['current_ip'] = $info['lan_ip'];

    // Calcul du reseau WAN (ex: 192.168.50)
    $info['wan_network'] = '';
    $info['wan_octet'] = 0;
    if ($info['wan_ip']) {
        $parts = explode('.', $info['wan_ip']);
        $info['wan_network'] = $parts[0] . '.' . $parts[1] . '.' . $parts[2];
        $info['wan_octet'] = intval($parts[3]);
    }

    // Calcul du reseau LAN (ex: 192.168.100)
    $info['network'] = '';
    $info['server_octet'] = 0;
    $info['max_machines'] = 0;
    if ($info['lan_ip']) {
        $parts = explode('.', $info['lan_ip']);
        $info['network'] = $parts[0] . '.' . $parts[1] . '.' . $parts[2];
        $info['server_octet'] = intval($parts[3]);
        // Max machines = nombre d'IP dispo apres l'IP du serveur
        $info['max_machines'] = 254 - $info['server_octet'];
    }

    // Lecture de la plage DHCP dans dhcpd.conf
    $info['dhcp_range'] = trim(shell_exec("grep -v '^[[:space:]]*#' /etc/dhcp/dhcpd.conf 2>/dev/null | grep 'range' | tail -1 | awk '{print \$2, \$3}' | sed 's/;//'"));

    // Etat du service DNS (BIND)
    $dns_status = trim((string)shell_exec("systemctl is-active bind9 2>/dev/null || systemctl is-active named 2>/dev/null"));
    $info['dns_service_up'] = ($dns_status === 'active');

    // Detection robuste des zones DNS (named.conf.local + views + default-zones)
    $zone_map = parse_bind_zone_map([
        '/etc/bind/named.conf.local',
        '/etc/bind/named.conf.views.inc',
        '/etc/bind/named.conf.default-zones'
    ]);
    $primary_zone = pick_primary_dns_zone($zone_map);

    $info['dns_domain'] = (string)($primary_zone['zone'] ?? '');
    $info['dns_zone_file'] = (string)($primary_zone['file'] ?? '');

    if ($info['dns_zone_file'] === '' && $info['dns_domain'] !== '') {
        $guessed = '/etc/bind/db.' . $info['dns_domain'];
        if (is_readable($guessed)) {
            $info['dns_zone_file'] = $guessed;
        }
    }

    $info['dns_records'] = extract_dns_records_from_zone_file($info['dns_zone_file']);

    // Fallback: cas limite ou la zone est hors mapping
    if ($info['dns_domain'] === '') {
        $fallback_domain = trim((string)shell_exec("grep -h -E '^[[:space:]]*zone\\s+\"[^\"]+\"' /etc/bind/named.conf.local /etc/bind/named.conf.views.inc 2>/dev/null | grep -Ev 'in-addr\\.arpa|ip6\\.arpa|rpz\\.' | head -1 | sed -E 's/.*zone[[:space:]]+\"([^\"]+)\".*/\\1/'"));
        if ($fallback_domain !== '') {
            $info['dns_domain'] = $fallback_domain;
            $guessed = '/etc/bind/db.' . $fallback_domain;
            if ($info['dns_zone_file'] === '' && is_readable($guessed)) {
                $info['dns_zone_file'] = $guessed;
            }
        }
    }

    if ($info['dns_records'] === '' && $info['dns_zone_file'] !== '') {
        $info['dns_records'] = extract_dns_records_from_zone_file($info['dns_zone_file']);
    }

    // Fallback final: chercher un db.<zone> local exploitable
    if ($info['dns_domain'] === '' || $info['dns_zone_file'] === '' || $info['dns_records'] === '') {
        $db_zone = pick_primary_dns_zone_from_db_files('/etc/bind');
        if (!empty($db_zone['zone']) && !empty($db_zone['file'])) {
            $info['dns_domain'] = $db_zone['zone'];
            $info['dns_zone_file'] = $db_zone['file'];
            $info['dns_records'] = extract_dns_records_from_zone_file($info['dns_zone_file']);
        }
    }
    
    return $info;
}

// Appel de la fonction et stockage
$server_info = get_server_info();

// Export en variables globales pour simplifier l'acces dans les pages
$wan_ip = $server_info['wan_ip'];
$lan_ip = $server_info['lan_ip'];
$current_ip = $server_info['current_ip'];
$wan_network = $server_info['wan_network'];
$wan_octet = $server_info['wan_octet'];
$network = $server_info['network'];
$server_octet = $server_info['server_octet'];
$max_machines = $server_info['max_machines'];
$dhcp_range = $server_info['dhcp_range'];
$dns_service_up = $server_info['dns_service_up'];
$dns_domain = $server_info['dns_domain'];
$dns_zone_file = $server_info['dns_zone_file'];
$dns_records = $server_info['dns_records'];

// Appareils connectés via baux DHCP
function get_icon_from_hostname(string $hostname): string {
    $h = strtolower($hostname);
    if (preg_match('/mac|macbook|imac/', $h))     return 'fas fa-laptop';
    if (preg_match('/iphone|phone|mobile/', $h))  return 'fas fa-mobile-alt';
    if (preg_match('/ipad|tablet/', $h))          return 'fas fa-tablet-alt';
    if (preg_match('/tv|samsung/', $h))           return 'fas fa-tv';
    if (preg_match('/server|srv/', $h))           return 'fas fa-server';
    return 'fas fa-desktop';
}

function parse_dhcp_leases(string $file, string $subnet_prefix): array {
    if (!is_readable($file)) return [];
    $content = file_get_contents($file);
    if ($content === false) return [];

    preg_match_all('/lease\s+([\d\.]+)\s*\{([^}]*)\}/s', $content, $matches, PREG_SET_ORDER);

    $leases_by_ip = [];
    foreach ($matches as $match) {
        $ip    = $match[1];
        $block = $match[2];
        if (strpos($ip, $subnet_prefix) !== 0) continue;
        if (!preg_match('/binding\s+state\s+active/i', $block)) continue;

        $hostname = $ip;
        if (preg_match('/client-hostname\s+"([^"]+)"/i', $block, $hn)) {
            $hostname = $hn[1];
        }

        $mac = '';
        if (preg_match('/hardware\s+ethernet\s+([\da-f:]+)/i', $block, $hw)) {
            $mac = strtoupper($hw[1]);
        }

        // Dernier bail pour cet IP ecrase les anciens (fichier append-only)
        $leases_by_ip[$ip] = [
            'ip'     => $ip,
            'name'   => $hostname,
            'type'   => get_icon_from_hostname($hostname),
            'detail' => 'Ethernet',
            'signal' => 5,
            'mac'    => $mac,
        ];
    }
    return array_values($leases_by_ip);
}

$dhcp_devices = parse_dhcp_leases('/var/lib/dhcp/dhcpd.leases', $network . '.');

// Table ARP: MAC => état voisin (REACHABLE, STALE, DELAY, ...)
function get_neighbor_states_by_mac(): array {
    $output = shell_exec("ip neigh show 2>/dev/null");
    if (!$output) return [];
    $states = [];
    foreach (explode("\n", trim($output)) as $line) {
        if (preg_match('/lladdr\s+([\da-f:]+)\s+([A-Z]+)/i', $line, $m)) {
            $states[strtoupper($m[1])] = strtoupper($m[2]);
        }
    }
    return $states;
}

function is_ip_alive(string $ip): bool {
    if (empty($ip)) return false;
    $cmd = "ping -c 1 -W 1 " . escapeshellarg($ip) . " >/dev/null 2>&1; echo $?";
    $result = trim((string) shell_exec($cmd));
    return $result === '0';
}

function has_real_hostname(array $device): bool {
    $name = trim((string)($device['name'] ?? ''));
    $ip = trim((string)($device['ip'] ?? ''));
    return $name !== '' && $name !== $ip;
}

function pick_best_device_entry(array $current, array $candidate): array {
    $score = function(array $d): int {
        $s = 0;
        if (!empty($d['online'])) {
            $s += 100;
        }
        if (has_real_hostname($d)) {
            $s += 10;
        }
        if (!empty($d['mac'])) {
            $s += 1;
        }
        return $s;
    };

    $currentScore = $score($current);
    $candidateScore = $score($candidate);
    return ($candidateScore >= $currentScore) ? $candidate : $current;
}

function dedupe_dhcp_devices(array $devices): array {
    $deduped = [];

    foreach ($devices as $d) {
        $mac = strtoupper(trim((string)($d['mac'] ?? '')));
        $ip = trim((string)($d['ip'] ?? ''));

        // Prefer MAC-based identity, fallback to IP when MAC is missing.
        $key = ($mac !== '') ? ('mac:' . $mac) : ('ip:' . $ip);
        if (!isset($deduped[$key])) {
            $deduped[$key] = $d;
            continue;
        }
        $deduped[$key] = pick_best_device_entry($deduped[$key], $d);
    }

    return array_values($deduped);
}

$neighbor_states = get_neighbor_states_by_mac();
$dhcp_devices  = array_map(function($d) use ($neighbor_states) {
    $mac = strtoupper($d['mac'] ?? '');
    $state = $neighbor_states[$mac] ?? '';

    if (in_array($state, ['REACHABLE', 'DELAY', 'PROBE'], true)) {
        $online = true;
    } elseif ($state === 'STALE') {
        // Client potentiellement idle: ping court pour confirmer
        $online = is_ip_alive($d['ip'] ?? '');
    } else {
        $online = false;
    }

    $d['online'] = $online;
    return $d;
}, $dhcp_devices);

$dhcp_devices = dedupe_dhcp_devices($dhcp_devices);

// Liste séparée: uniquement les appareils réellement en ligne
$dhcp_devices_online = array_values(array_filter($dhcp_devices, function($d) { return $d['online']; }));