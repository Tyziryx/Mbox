<?php
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', '0');

set_error_handler(function ($severity, $message, $file, $line) {
    echo json_encode([
        'success' => false,
        'error' => 'PHP warning: ' . $message,
        'where' => basename($file) . ':' . $line,
    ]);
    exit;
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'error' => 'PHP fatal: ' . $e['message'],
            'where' => basename($e['file']) . ':' . $e['line'],
        ]);
    }
});

function resolve_list_path($name) {
    $etcPath = '/etc/mbox/dns/' . $name;
    if (file_exists($etcPath) || (is_dir('/etc/mbox/dns') && is_writable('/etc/mbox/dns'))) {
        return $etcPath;
    }
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $name;
}

function connect_db_optional() {
    $envLoader = __DIR__ . '/../config/env_loader.php';
    if (!file_exists($envLoader)) {
        return null;
    }

    require_once $envLoader;

    $host = $_ENV['DB_HOST'] ?? 'localhost';
    $db = $_ENV['DB_NAME'] ?? 'db_box';
    $user = $_ENV['DB_USER'] ?? 'box_user';
    $pass = $_ENV['DB_PASS'] ?? '';
    $charset = 'utf8mb4';

    try {
        $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (Exception $e) {
        return null;
    }
}

function ensure_dns_events_table($pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS dns_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_hash CHAR(32) NOT NULL,
            event_type ENUM('blocked','seen') NOT NULL,
            domain VARCHAR(255) NOT NULL,
            ip_source VARCHAR(45) DEFAULT NULL,
            mac_source VARCHAR(32) DEFAULT NULL,
            device_name VARCHAR(255) DEFAULT NULL,
            reason_code VARCHAR(100) DEFAULT NULL,
            matched_domain VARCHAR(255) DEFAULT NULL,
            distance INT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            inserted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_event_hash (event_hash),
            KEY idx_type_created (event_type, created_at),
            KEY idx_domain (domain),
            KEY idx_ip (ip_source),
            KEY idx_mac (mac_source)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql);
}

function insert_dns_event($pdo, $eventType, $domain, $ip, $mac, $reason, $matched, $distance, $createdAt, $hashSeed) {
    $root = to_root_domain($domain);
    if ($root === '') {
        return;
    }

    $eventHash = md5($eventType . '|' . $hashSeed);
    $createdAtNorm = $createdAt;
    if ($createdAtNorm === null || $createdAtNorm === '') {
        $createdAtNorm = date('Y-m-d H:i:s');
    }

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO dns_events (
            event_hash, event_type, domain, ip_source, mac_source,
            reason_code, matched_domain, distance, created_at
        ) VALUES (
            :event_hash, :event_type, :domain, :ip_source, :mac_source,
            :reason_code, :matched_domain, :distance, :created_at
        )"
    );

    $stmt->execute([
        ':event_hash' => $eventHash,
        ':event_type' => $eventType,
        ':domain' => $root,
        ':ip_source' => $ip !== '' ? $ip : null,
        ':mac_source' => $mac !== '' ? $mac : null,
        ':reason_code' => $reason !== '' ? $reason : null,
        ':matched_domain' => $matched !== '' ? to_root_domain($matched) : null,
        ':distance' => is_numeric($distance) ? (int)$distance : null,
        ':created_at' => $createdAtNorm,
    ]);
}

function fetch_dns_events($pdo, $eventType, $limit) {
    $stmt = $pdo->prepare(
        "SELECT e.domain, e.ip_source, e.mac_source, e.reason_code, e.matched_domain, e.distance, e.created_at
         FROM dns_events e
         WHERE e.event_type = :event_type
         ORDER BY e.created_at DESC
         LIMIT :lim"
    );
    $stmt->bindValue(':event_type', $eventType, PDO::PARAM_STR);
    $stmt->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function fetch_dns_events_page($pdo, $eventType, $limit, $offset, $domainFilter = '') {
    $limit = (int) $limit;
    $offset = (int) $offset;
    if ($limit <= 0) {
        $limit = 50;
    }
    if ($limit > 500) {
        $limit = 500;
    }
    if ($offset < 0) {
        $offset = 0;
    }

    $whereFilter = ' WHERE e.event_type = :event_type ';
    if ($domainFilter !== '') {
        $whereFilter .= ' AND e.domain LIKE :domain_like ';
    }

    $sql =
        "SELECT e.domain, e.ip_source, e.mac_source, e.reason_code, e.matched_domain, e.distance, e.created_at
         FROM dns_events e" .
        $whereFilter .
        " ORDER BY e.created_at DESC
          LIMIT :lim OFFSET :off";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':event_type', $eventType, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    if ($domainFilter !== '') {
        $stmt->bindValue(':domain_like', '%' . $domainFilter . '%', PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $sqlCount =
        "SELECT COUNT(*) AS c
         FROM dns_events e" .
        $whereFilter;

    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->bindValue(':event_type', $eventType, PDO::PARAM_STR);
    if ($domainFilter !== '') {
        $stmtCount->bindValue(':domain_like', '%' . $domainFilter . '%', PDO::PARAM_STR);
    }
    $stmtCount->execute();
    $total = (int) ($stmtCount->fetchColumn() ?: 0);

    return ['rows' => $rows, 'total' => $total];
}

function normalize_domain($domain) {
    $value = strtolower(trim($domain));
    $value = trim($value, ". \t\n\r\0\x0B");
    $value = preg_replace('#^[a-z]+://#', '', $value);
    $value = preg_replace('#/.*$#', '', $value);
    $value = preg_replace('/:\d+$/', '', $value);
    $value = preg_replace('/[^a-z0-9.-]/', '', $value);
    $value = preg_replace('/\.+/', '.', $value);
    $value = preg_replace('/^www\./', '', $value);
    return (string) $value;
}

function to_root_domain($domain) {
    $parts = array_values(array_filter(explode('.', normalize_domain($domain))));
    $count = count($parts);
    if ($count >= 2) {
        return $parts[$count - 2] . '.' . $parts[$count - 1];
    }
    return $count === 1 ? $parts[0] : '';
}

function append_unique_domain($path, $domain) {
    $existing = [];
    if (file_exists($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $v = normalize_domain($line);
            if ($v !== '' && $v[0] !== '#') {
                $existing[$v] = true;
            }
        }
    }

    if (isset($existing[$domain])) {
        return false;
    }

    if (!file_exists($path)) {
        @touch($path);
    }

    file_put_contents($path, $domain . "\n", FILE_APPEND | LOCK_EX);
    return true;
}

function read_raw_list_file($path) {
    if (!is_readable($path)) {
        return '';
    }
    $c = @file_get_contents($path);
    return $c === false ? '' : (string) $c;
}

function write_raw_list_file($path, $content) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!file_exists($path)) {
        @touch($path);
    }

    $normalized = str_replace(["\r\n", "\r"], "\n", (string) $content);
    if ($normalized !== '' && substr($normalized, -1) !== "\n") {
        $normalized .= "\n";
    }
    @file_put_contents($path, $normalized, LOCK_EX);
}

function normalize_domain_lists_files($blacklistPath, $knownPath) {
    $candidates = [
        dirname(__DIR__) . '/bin/python/normalize_domain_lists.py',
    ];

    $script = '';
    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            $script = $candidate;
            break;
        }
    }

    if ($script === '') {
        return ['ok' => false, 'code' => 127, 'message' => 'normalize_domain_lists.py introuvable'];
    }

    $summaryPath = '/etc/mbox/dns/domain_lists_summary.json';
    $cmd = 'python3 ' . escapeshellarg($script)
        . ' --blacklist ' . escapeshellarg($blacklistPath)
        . ' --known ' . escapeshellarg($knownPath)
        . ' --summary ' . escapeshellarg($summaryPath)
        . ' 2>&1';

    $output = [];
    $code = 0;
    @exec($cmd, $output, $code);

    return [
        'ok' => ($code === 0),
        'code' => $code,
        'message' => implode("\n", $output),
    ];
}

function load_dhcp_ip_to_name_map() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $file = '/var/lib/dhcp/dhcpd.leases';
    if (!is_readable($file)) {
        return $cache;
    }
    $content = @file_get_contents($file);
    if ($content === false) {
        return $cache;
    }
    if (preg_match_all('/lease\s+([\d\.]+)\s*\{([^}]*)\}/s', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $ip = $m[1];
            $block = $m[2];
            if (!preg_match('/binding\s+state\s+active/i', $block)) {
                continue;
            }
            $hostname = '';
            if (preg_match('/client-hostname\s+"([^"]+)"/i', $block, $hn)) {
                $hostname = $hn[1];
            }
            // Last active lease wins (append-only file), matches parse_dhcp_leases()
            $cache[$ip] = $hostname;
        }
    }
    return $cache;
}

function load_special_ip_name_map() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [
        '127.0.0.1' => 'Localhost',
        '::1' => 'Localhost',
    ];

    $serverAddr = trim((string) ($_SERVER['SERVER_ADDR'] ?? ''));
    if ($serverAddr !== '' && filter_var($serverAddr, FILTER_VALIDATE_IP)) {
        $cache[$serverAddr] = 'MBox';
    }

    if (is_readable('/etc/hosts')) {
        $lines = @file('/etc/hosts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) < 2) {
                continue;
            }

            $ip = (string) ($parts[0] ?? '');
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $name = trim((string) ($parts[1] ?? ''));
            if ($name === '' || strcasecmp($name, 'localhost') === 0) {
                continue;
            }

            if (!isset($cache[$ip]) || trim((string) $cache[$ip]) === '') {
                $cache[$ip] = $name;
            }
        }
    }

    return $cache;
}

function device_name_from_ip($ip) {
    if ($ip === '' || $ip === null) {
        return '';
    }

    $ip = trim((string) $ip);
    if ($ip === '') {
        return '';
    }

    $map = load_dhcp_ip_to_name_map();
    $name = trim((string) ($map[$ip] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $specialMap = load_special_ip_name_map();
    $name = trim((string) ($specialMap[$ip] ?? ''));
    if ($name !== '') {
        return $name;
    }

    // Fallback simple: x.x.x.1 correspond souvent a la passerelle LAN.
    if (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $ip) && preg_match('/\.1$/', $ip)) {
        return 'Passerelle LAN';
    }

    return '';
}

function detect_mac_from_arp($ip) {
    if ($ip === '' || !is_readable('/proc/net/arp')) {
        return '';
    }
    $lines = file('/proc/net/arp', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $idx => $line) {
        if ($idx === 0) {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) >= 4 && $parts[0] === $ip) {
            return strtoupper($parts[3]);
        }
    }
    return '';
}

function load_hosts_set_from_file($filename) {
    $set = [];
    $sources = [
        '/etc/mbox/dns/' . $filename,
        dirname(__DIR__) . '/data/' . $filename,
    ];
    foreach ($sources as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '') {
                continue;
            }
            $root = to_root_domain($line);
            if ($root !== '') {
                $set[$root] = true;
            }
        }
    }
    return $set;
}

function load_knowdomain_hosts_set() {
    return load_hosts_set_from_file('knowdomain.txt');
}

function load_blocked_hosts_set() {
    return load_hosts_set_from_file('blacklist.txt');
}

function parse_squid_log_line($line) {
    // Format Squid configure: %tl %>a %rm %ru %>Hs %{Referer}>h
    // Ex: [18/Apr/2026:11:47:23 +0200] 192.168.100.50 CONNECT example.com:443 200 -
    // Ex: [18/Apr/2026:11:47:24 +0200] 192.168.100.50 GET http://foo.com/ 200 https://google.com/
    if (!preg_match('/^\[([^\]]+)\]\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S.*)$/', $line, $m)) {
        return null;
    }

    $ts_raw  = $m[1];
    $ip      = $m[2];
    $method  = strtoupper($m[3]);
    $url     = $m[4];
    $status  = (int) $m[5];
    $referer = trim($m[6]);

    if ($status < 200 || $status >= 400) {
        return null;
    }

    $host = '';
    if ($method === 'CONNECT') {
        $host = preg_replace('/:\d+$/', '', $url);
    } else {
        $parsed = @parse_url($url);
        $host = $parsed['host'] ?? '';
    }
    $host_root = to_root_domain($host);
    if ($host_root === '') {
        return null;
    }

    $ref_root = '';
    if ($referer !== '' && $referer !== '-') {
        $parsed_ref = @parse_url($referer);
        $ref_host = $parsed_ref['host'] ?? '';
        $ref_root = to_root_domain($ref_host);
    }

    $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $ts_raw);
    $ts_unix = $dt ? $dt->getTimestamp() : 0;

    return [
        'ts'       => $ts_unix,
        'ip'       => $ip,
        'host'     => $host_root,
        'ref_root' => $ref_root,
        'method'   => $method,
    ];
}

/**
 * Lit /var/log/squid/access.log et ne conserve que les domaines principaux
 * que l'utilisateur a reellement visites (pas les sous-ressources / trackers).
 *
 * Regle :
 *   Un hote est une "navigation" si
 *     (a) il n'a pas de Referer (URL saisie / signet / nouvel onglet), OU
 *     (b) il apparait comme origine d'un Referer dans une autre ligne
 *         (= une page web s'est chargee depuis cet hote => c'est bien un site).
 *   Les hotes qui n'apparaissent jamais en Referer et qui ont eux-memes un
 *   Referer sont des ressources (CDN, images, scripts, trackers) : on jette.
 */
function gather_recent_visited_domains($limit = 40) {
    $path = '/var/mbox/squid/access.log';
    if (!is_readable($path)) {
        return [];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || empty($lines)) {
        return [];
    }
    $lines = array_slice($lines, -10000);

    $blocked    = load_blocked_hosts_set();
    $knowdomain = load_knowdomain_hosts_set();

    $entries = [];
    $referer_origins = [];
    foreach ($lines as $line) {
        $p = parse_squid_log_line($line);
        if ($p === null) {
            continue;
        }
        if (isset($blocked[$p['host']]) || isset($knowdomain[$p['host']])) {
            continue;
        }
        $entries[] = $p;
        if ($p['ref_root'] !== '') {
            $referer_origins[$p['ref_root']] = true;
        }
    }

    $rows = [];
    $seen = [];
    for ($i = count($entries) - 1; $i >= 0 && count($rows) < $limit; $i--) {
        $e = $entries[$i];
        $host = $e['host'];
        if (isset($seen[$host])) {
            continue;
        }
        $is_nav = ($e['ref_root'] === '') || isset($referer_origins[$host]);
        if (!$is_nav) {
            continue;
        }
        $seen[$host] = true;
        $rows[] = [
            'host'      => $host,
            'ip'        => $e['ip'],
            'mac'       => detect_mac_from_arp($e['ip']),
            'source'    => 'squid',
            'ts'        => $e['ts'] ? date('c', $e['ts']) : '',
            'line_hash' => md5($e['ip'] . '|' . $host . '|' . ($e['ts'] ? date('Y-m-d H', $e['ts']) : date('Y-m-d H'))),
        ];
    }
    return $rows;
}

function parse_rpz_log_line($line) {
    // Lignes RPZ utiles (fichier rpz.log, query.log ou syslog/daemon):
    // 17-Apr-2026 22:30:15.123 client @0x... 192.168.100.20#53012 (youtube.com): rpz QNAME NXDOMAIN rewrite youtube.com via ...
    // Apr 18 22:30:15 hostname named[1234]: client 192.168.100.20#53012 (youtube.com): rpz QNAME CNAME rewrite youtube.com via ...
    // Apr 18 22:30:15 hostname named[1234]: client 192.168.100.20#53012 (youtube.com): view view_devabcd: rpz QNAME CNAME rewrite youtube.com via ...
    if (stripos($line, 'rpz') === false || stripos($line, 'rewrite') === false) {
        return null;
    }

    if (!preg_match('/client(?:\s+@[^\s]+)?\s+([0-9a-fA-F:.]+)#\d+\s+\(([^)]+)\):\s+(?:view\s+[^:]+:\s+)?rpz\s+QNAME\s+(.+?)\s+rewrite\s+(\S+)/i', $line, $m)) {
        return null;
    }

    $isoTs = date('c');
    if (preg_match('/(\d{2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}:\d{2}(?:\.\d+)?)/', $line, $dt1)) {
        $ts = strtotime(str_replace('-', ' ', $dt1[1]));
        if ($ts !== false) {
            $isoTs = date('c', $ts);
        }
    } elseif (preg_match('/([A-Za-z]{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})/', $line, $dt2)) {
        $ts = strtotime($dt2[1] . ' ' . date('Y'));
        if ($ts !== false) {
            $isoTs = date('c', $ts);
        }
    }

    $action = strtoupper(trim((string) $m[3]));
    $action = preg_replace('/\s+/', '_', $action);
    if ($action === null || $action === '') {
        $action = 'UNKNOWN';
    }

    return [
        'ts' => $isoTs,
        'ip' => $m[1],
        'host' => to_root_domain($m[2]),
        'action' => $action,
        'matched' => to_root_domain($m[4]),
        'line_hash' => md5($line),
    ];
}

function get_all_rpz_log_candidates() {
    return [
        '/var/log/named/rpz.log',
        '/var/log/named/query.log',
        '/var/log/named/named.log',
        '/var/log/bind/rpz.log',
        '/var/log/bind/query.log',
        '/var/log/bind/named.log',
        '/var/log/named.log',
        '/var/log/syslog',
        '/var/log/messages',
        '/var/log/daemon.log',
    ];
}

function get_rpz_log_candidates() {
    $out = [];
    foreach (get_all_rpz_log_candidates() as $path) {
        if (is_readable($path)) {
            $out[] = $path;
        }
    }
    return $out;
}

function ingest_rpz_log_into_db($pdo, $logPath, $limit = 800) {
    if (!is_readable($logPath)) {
        return 0;
    }
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return 0;
    }
    $lines = array_slice($lines, -$limit);
    $count = 0;
    foreach ($lines as $line) {
        $p = parse_rpz_log_line($line);
        if (!$p || $p['host'] === '') {
            continue;
        }
        insert_dns_event(
            $pdo,
            'blocked',
            $p['host'],
            $p['ip'],
            '',
            'blacklist_exact',
            $p['matched'],
            null,
            date('Y-m-d H:i:s', strtotime($p['ts']) ?: time()),
            $p['line_hash']
        );
        $count++;
    }
    return $count;
}

function ingest_rpz_logs_into_db($pdo, $limitPerFile = 800) {
    $count = 0;
    foreach (get_rpz_log_candidates() as $path) {
        $count += ingest_rpz_log_into_db($pdo, $path, $limitPerFile);
    }
    return $count;
}

function gather_rpz_blocks_plain($logPath, $limit = 30) {
    if (!is_readable($logPath)) {
        return [];
    }
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }
    $rows = [];
    $seen = [];
    for ($i = count($lines) - 1; $i >= 0 && count($rows) < $limit; $i--) {
        $p = parse_rpz_log_line($lines[$i]);
        if (!$p || $p['host'] === '') {
            continue;
        }
        $key = $p['host'] . '|' . $p['ip'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $rows[] = [
            'ts'           => $p['ts'],
            'host'         => $p['host'],
            'reason'       => 'blacklist_exact',
            'reason_label' => reason_label('blacklist_exact'),
            'matched'      => $p['matched'],
            'ip'           => $p['ip'],
        ];
    }
    return $rows;
}

function gather_rpz_blocks_from_logs($limit = 60) {
    $rows = [];
    $seen = [];

    foreach (get_rpz_log_candidates() as $path) {
        $chunk = gather_rpz_blocks_plain($path, $limit * 3);
        foreach ($chunk as $r) {
            $k = ($r['host'] ?? '') . '|' . ($r['ip'] ?? '') . '|' . substr((string)($r['ts'] ?? ''), 0, 10);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $rows[] = $r;
        }
    }

    usort($rows, function ($a, $b) {
        return strcmp((string)($b['ts'] ?? ''), (string)($a['ts'] ?? ''));
    });

    return array_slice($rows, 0, $limit);
}

function ingest_blocked_jsonl_into_db($pdo, $logPath) {
    if (!is_readable($logPath)) {
        return;
    }

    $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    $slice = array_slice($lines, -500);
    foreach ($slice as $line) {
        $obj = json_decode($line, true);
        if (!is_array($obj)) {
            continue;
        }

        $host = to_root_domain((string)($obj['host'] ?? ''));
        if ($host === '') {
            continue;
        }

        $ts = (string)($obj['ts'] ?? '');
        $dt = null;
        if ($ts !== '') {
            $dtTs = strtotime($ts);
            if ($dtTs !== false) {
                $dt = date('Y-m-d H:i:s', $dtTs);
            }
        }

        $hashSeed = ($obj['ts'] ?? '') . '|' . $host . '|' . ($obj['ip'] ?? '') . '|' . ($obj['reason'] ?? 'blocked') . '|' . ($obj['matched'] ?? '');
        insert_dns_event(
            $pdo,
            'blocked',
            $host,
            (string)($obj['ip'] ?? ''),
            '',
            (string)($obj['reason'] ?? 'blocked'),
            (string)($obj['matched'] ?? ''),
            isset($obj['distance']) ? $obj['distance'] : null,
            $dt,
            $hashSeed
        );
    }
}

function ingest_seen_into_db($pdo, $rows) {
    foreach ($rows as $r) {
        $host = to_root_domain((string)($r['host'] ?? ''));
        if ($host === '') {
            continue;
        }
        $ip = (string)($r['ip'] ?? '');
        $mac = (string)($r['mac'] ?? '');
        $hashSeed = (string)($r['line_hash'] ?? ($host . '|' . $ip . '|' . date('Y-m-d H:i')));
        insert_dns_event(
            $pdo,
            'seen',
            $host,
            $ip,
            $mac,
            'allow',
            '',
            null,
            date('Y-m-d H:i:s'),
            $hashSeed
        );
    }
}

function reason_label($code) {
    $map = [
        'blacklist_exact' => 'Site interdit',
        'blacklist_fuzzy' => 'Site similaire interdit',
        'leet_typosquat_known_domain' => 'Faux site proche d\'un site connu',
        'typosquat_known_domain' => 'Site suspect proche',
        'allow' => 'Autorise',
        'blocked' => 'Bloque',
    ];
    return $map[$code] ?? $code;
}

function row_timestamp_epoch($row) {
    $ts = (string) ($row['ts'] ?? '');
    if ($ts === '') {
        return null;
    }
    $epoch = strtotime($ts);
    return $epoch === false ? null : $epoch;
}

function compare_rows_by_ts_desc($a, $b) {
    $aEpoch = row_timestamp_epoch($a);
    $bEpoch = row_timestamp_epoch($b);

    if ($aEpoch !== null && $bEpoch !== null) {
        if ($aEpoch === $bEpoch) {
            return strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? ''));
        }
        return $bEpoch <=> $aEpoch;
    }

    if ($aEpoch !== null) {
        return -1;
    }
    if ($bEpoch !== null) {
        return 1;
    }

    return strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? ''));
}

function gather_block_reasons_recent($limit = 30) {
    $path = '/etc/mbox/dns/block_reasons.json';
    if (!is_readable($path)) {
        return [];
    }

    $json = json_decode((string) @file_get_contents($path), true);
    if (!is_array($json)) {
        return [];
    }

    $rows = [];
    foreach ($json as $domain => $entry) {
        $host = to_root_domain((string) $domain);
        if ($host === '') {
            continue;
        }

        $reason = 'blocked';
        $matched = '';
        $ts = date('c');

        if (is_array($entry)) {
            $reason = (string) ($entry['reason'] ?? 'blocked');
            $matched = (string) ($entry['matched'] ?? '');
            if (isset($entry['updated_at'])) {
                $rawTs = $entry['updated_at'];
                if (is_numeric($rawTs)) {
                    $epoch = (int) $rawTs;
                    if ($epoch > 0) {
                        $ts = date('c', $epoch);
                    }
                } elseif (is_string($rawTs) && $rawTs !== '') {
                    $parsed = strtotime($rawTs);
                    if ($parsed !== false) {
                        $ts = date('c', $parsed);
                    }
                }
            }
        }

        $rows[] = [
            'ts' => $ts,
            'host' => $host,
            'reason' => $reason,
            'reason_label' => reason_label($reason),
            'matched' => to_root_domain($matched),
            'ip' => '',
            'device_name' => '',
        ];
    }

    usort($rows, 'compare_rows_by_ts_desc');

    return array_slice($rows, 0, $limit);
}

function load_block_reasons_map() {
    $path = '/etc/mbox/dns/block_reasons.json';
    if (!is_readable($path)) {
        return [];
    }

    $json = json_decode((string) @file_get_contents($path), true);
    if (!is_array($json)) {
        return [];
    }

    $map = [];
    foreach ($json as $domain => $entry) {
        $host = to_root_domain((string) $domain);
        if ($host === '' || !is_array($entry)) {
            continue;
        }
        $map[$host] = $entry;
    }
    return $map;
}

function enrich_rows_with_block_reasons($rows) {
    if (!is_array($rows) || empty($rows)) {
        return $rows;
    }

    $reasonMap = load_block_reasons_map();
    if (empty($reasonMap)) {
        return $rows;
    }

    foreach ($rows as &$row) {
        $host = to_root_domain((string) ($row['host'] ?? ''));
        if ($host === '' || !isset($reasonMap[$host])) {
            continue;
        }

        $entry = $reasonMap[$host];
        $curReason = (string) ($row['reason'] ?? '');
        $newReason = (string) ($entry['reason'] ?? '');

        if ($newReason !== '' && ($curReason === '' || $curReason === 'blocked' || $curReason === 'blacklist_exact')) {
            $row['reason'] = $newReason;
            $row['reason_label'] = reason_label($newReason);
        }

        if (empty($row['matched']) && !empty($entry['matched'])) {
            $row['matched'] = to_root_domain((string) $entry['matched']);
        }
    }
    unset($row);

    return $rows;
}

function merge_recent_rows($sources, $limit = 30) {
    $rowsByKey = [];

    foreach ($sources as $rows) {
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $host = to_root_domain((string) ($r['host'] ?? ''));
            if ($host === '') {
                continue;
            }
            $r['host'] = $host;

            $ip = (string) ($r['ip'] ?? '');
            if (!isset($r['reason']) || $r['reason'] === '') {
                $r['reason'] = 'blocked';
            }
            if (!isset($r['reason_label']) || $r['reason_label'] === '') {
                $r['reason_label'] = reason_label((string) $r['reason']);
            }
            if (!isset($r['device_name']) || trim((string) $r['device_name']) === '') {
                $r['device_name'] = device_name_from_ip($ip);
            }

            $tsRaw = (string) ($r['ts'] ?? '');
            $tsEpoch = strtotime($tsRaw);
            $dayBucket = $tsEpoch !== false
                ? date('Y-m-d', $tsEpoch)
                : substr($tsRaw, 0, 10);
            if ($dayBucket === '') {
                $dayBucket = 'unknown-day';
            }

            $deviceName = trim((string) ($r['device_name'] ?? ''));
            $mac = trim((string) ($r['mac'] ?? ''));
            $deviceKey = $deviceName !== ''
                ? strtolower($deviceName)
                : ($ip !== '' ? strtolower($ip) : ($mac !== '' ? strtolower($mac) : 'unknown-device'));

            // Regle voulue: 1 domaine par jour et par personne/appareil.
            $k = $dayBucket . '|' . $deviceKey . '|' . $host;
            if (!isset($rowsByKey[$k])) {
                $rowsByKey[$k] = $r;
                continue;
            }

            if (compare_rows_by_ts_desc($r, $rowsByKey[$k]) < 0) {
                $rowsByKey[$k] = $r;
            }
        }
    }

    $merged = array_values($rowsByKey);

    usort($merged, 'compare_rows_by_ts_desc');

    if ($limit > 0 && count($merged) > $limit) {
        $merged = array_slice($merged, 0, $limit);
    }

    return $merged;
}

function merge_rows_with_recent_block_reasons($rows, $limit = 30) {
    if (!is_array($rows)) {
        $rows = [];
    }

    $fromReasons = gather_block_reasons_recent($limit > 0 ? $limit : 30);
    if (empty($fromReasons)) {
        return $rows;
    }

    $presentHosts = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $host = to_root_domain((string) ($r['host'] ?? ''));
        if ($host !== '') {
            $presentHosts[$host] = true;
        }
    }

    foreach ($fromReasons as $r) {
        $host = to_root_domain((string) ($r['host'] ?? ''));
        if ($host === '' || isset($presentHosts[$host])) {
            continue;
        }
        $rows[] = $r;
        $presentHosts[$host] = true;
    }

    usort($rows, 'compare_rows_by_ts_desc');

    if ($limit > 0 && count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
    }

    return $rows;
}

function collect_recent_blocked_sources($pdo, $limit = 30) {
    $limit = (int) $limit;
    if ($limit <= 0) {
        $limit = 30;
    }

    $logPath = '/etc/mbox/dns/blocked_visits.jsonl';
    $rowsFromDb = [];

    if ($pdo !== null) {
        if (file_exists($logPath)) {
            ingest_blocked_jsonl_into_db($pdo, $logPath);
        }

        ingest_rpz_logs_into_db($pdo, 1200);

        // Lire plus large pour pouvoir dedup ensuite et conserver 10 lignes utiles cote frontend.
        $dbScanLimit = max(80, $limit * 12);
        if ($dbScanLimit > 1200) {
            $dbScanLimit = 1200;
        }
        $dbRows = fetch_dns_events($pdo, 'blocked', $dbScanLimit);
        foreach ($dbRows as $r) {
            $ip = (string) ($r['ip_source'] ?? '');
            $reason = (string) ($r['reason_code'] ?? 'blocked');
            $rowsFromDb[] = [
                'ts' => (string) ($r['created_at'] ?? ''),
                'host' => (string) ($r['domain'] ?? ''),
                'reason' => $reason,
                'reason_label' => reason_label($reason),
                'matched' => (string) ($r['matched_domain'] ?? ''),
                'ip' => $ip,
                'mac' => (string) ($r['mac_source'] ?? ''),
                'device_name' => device_name_from_ip($ip),
                'distance' => $r['distance'] ?? null,
            ];
        }
    }

    $rowsFromJsonl = [];
    if (file_exists($logPath) && is_readable($logPath)) {
        $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $tailSize = max(80, $limit * 3);
        $lines = array_slice($lines, -$tailSize);

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $obj = json_decode($lines[$i], true);
            if (!is_array($obj)) {
                continue;
            }

            $host = to_root_domain((string) ($obj['host'] ?? ''));
            if ($host === '') {
                continue;
            }

            $reason = (string) ($obj['reason'] ?? 'blocked');
            $rowsFromJsonl[] = [
                'ts' => (string) ($obj['ts'] ?? ''),
                'host' => $host,
                'reason' => $reason,
                'reason_label' => reason_label($reason),
                'matched' => (string) ($obj['matched'] ?? ''),
                'ip' => (string) ($obj['ip'] ?? ''),
            ];
        }
    }

    $rowsFromRpz = gather_rpz_blocks_from_logs(max(60, $limit * 2));
    $rowsFromReasons = gather_block_reasons_recent(max(30, $limit));

    $mergedBeforeReasons = merge_recent_rows([$rowsFromDb, $rowsFromJsonl, $rowsFromRpz], $limit);
    $merged = merge_rows_with_recent_block_reasons($mergedBeforeReasons, $limit);

    $usedReasonsFallback = false;
    if (empty($merged)) {
        $merged = $rowsFromReasons;
        $usedReasonsFallback = true;
    }

    $merged = enrich_rows_with_block_reasons($merged);

    return [
        'rows' => $merged,
        'rows_from_db' => $rowsFromDb,
        'rows_from_jsonl' => $rowsFromJsonl,
        'rows_from_rpz' => $rowsFromRpz,
        'rows_from_reasons' => $rowsFromReasons,
        'rows_before_reasons_merge' => $mergedBeforeReasons,
        'used_reasons_fallback' => $usedReasonsFallback,
        'paths' => [
            'blocked_visits_jsonl' => $logPath,
            'block_reasons_json' => '/etc/mbox/dns/block_reasons.json',
        ],
    ];
}

function is_levenshtein_reason($reasonCode) {
    $reason = strtolower(trim((string) $reasonCode));
    if ($reason === '') {
        return false;
    }

    if (in_array($reason, [
        'blacklist_fuzzy',
        'typosquat_known_domain',
        'leet_typosquat_known_domain',
        'levenshtein',
    ], true)) {
        return true;
    }

    return (strpos($reason, 'typosquat') !== false)
        || (strpos($reason, 'fuzzy') !== false)
        || (strpos($reason, 'levenshtein') !== false);
}

function summarize_reason_counts($rows) {
    $counts = [];
    if (!is_array($rows)) {
        return $counts;
    }

    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $reason = strtolower(trim((string) ($r['reason'] ?? 'blocked')));
        if ($reason === '') {
            $reason = 'blocked';
        }
        if (!isset($counts[$reason])) {
            $counts[$reason] = 0;
        }
        $counts[$reason]++;
    }

    arsort($counts);
    return $counts;
}

function summarize_rows_for_debug($rows, $limit = 12) {
    $out = [];
    if (!is_array($rows)) {
        return $out;
    }

    $limit = (int) $limit;
    if ($limit <= 0) {
        $limit = 12;
    }

    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }

        $out[] = [
            'ts' => (string) ($r['ts'] ?? ''),
            'host' => (string) ($r['host'] ?? ''),
            'ip' => (string) ($r['ip'] ?? ''),
            'reason' => (string) ($r['reason'] ?? ''),
            'matched' => (string) ($r['matched'] ?? ''),
            'device_name' => (string) ($r['device_name'] ?? ''),
        ];

        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

function filter_rows_by_host($rows, $host) {
    $host = to_root_domain((string) $host);
    if ($host === '' || !is_array($rows)) {
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $rowHost = to_root_domain((string) ($r['host'] ?? ''));
        if ($rowHost === $host) {
            $out[] = $r;
        }
    }
    return $out;
}

function get_file_debug_info($path) {
    $exists = file_exists($path);
    $readable = is_readable($path);

    $mtimeEpoch = $exists ? @filemtime($path) : false;
    $mtimeIso = '';
    if (is_int($mtimeEpoch) && $mtimeEpoch > 0) {
        $mtimeIso = date('c', $mtimeEpoch);
    }

    $size = null;
    if ($exists && !$readable) {
        $size = null;
    } elseif ($exists) {
        $rawSize = @filesize($path);
        $size = (is_int($rawSize) && $rawSize >= 0) ? $rawSize : null;
    }

    return [
        'path' => $path,
        'exists' => $exists,
        'readable' => $readable,
        'size_bytes' => $size,
        'mtime' => $mtimeIso,
    ];
}

function build_block_reasons_debug_stats($sampleLimit = 20) {
    $path = '/etc/mbox/dns/block_reasons.json';
    $stats = [
        'file' => get_file_debug_info($path),
        'total_hosts' => 0,
        'by_reason' => [],
        'levenshtein_hosts_count' => 0,
        'levenshtein_recent' => [],
    ];

    if (!is_readable($path)) {
        return $stats;
    }

    $json = json_decode((string) @file_get_contents($path), true);
    if (!is_array($json)) {
        return $stats;
    }

    $levRows = [];
    $levTotal = 0;
    foreach ($json as $domain => $entry) {
        $host = to_root_domain((string) $domain);
        if ($host === '' || !is_array($entry)) {
            continue;
        }

        $reason = strtolower(trim((string) ($entry['reason'] ?? 'blocked')));
        if ($reason === '') {
            $reason = 'blocked';
        }

        $stats['total_hosts']++;
        if (!isset($stats['by_reason'][$reason])) {
            $stats['by_reason'][$reason] = 0;
        }
        $stats['by_reason'][$reason]++;

        if (!is_levenshtein_reason($reason)) {
            continue;
        }

        $levTotal++;

        $updatedAtRaw = $entry['updated_at'] ?? null;
        $updatedAtIso = '';
        $updatedAtEpoch = 0;
        if (is_numeric($updatedAtRaw)) {
            $updatedAtEpoch = (int) $updatedAtRaw;
            if ($updatedAtEpoch > 0) {
                $updatedAtIso = date('c', $updatedAtEpoch);
            }
        } elseif (is_string($updatedAtRaw) && $updatedAtRaw !== '') {
            $parsed = strtotime($updatedAtRaw);
            if ($parsed !== false) {
                $updatedAtEpoch = (int) $parsed;
                $updatedAtIso = date('c', $updatedAtEpoch);
            }
        }

        $levRows[] = [
            'host' => $host,
            'reason' => $reason,
            'matched' => to_root_domain((string) ($entry['matched'] ?? '')),
            'updated_at' => $updatedAtIso,
            '_epoch' => $updatedAtEpoch,
        ];
    }

    arsort($stats['by_reason']);

    usort($levRows, function ($a, $b) {
        $ea = (int) ($a['_epoch'] ?? 0);
        $eb = (int) ($b['_epoch'] ?? 0);
        if ($ea === $eb) {
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        }
        return ($ea < $eb) ? 1 : -1;
    });

    $sampleLimit = (int) $sampleLimit;
    if ($sampleLimit <= 0) {
        $sampleLimit = 20;
    }

    $levRows = array_slice($levRows, 0, $sampleLimit);
    foreach ($levRows as &$row) {
        unset($row['_epoch']);
    }
    unset($row);

    $stats['levenshtein_hosts_count'] = $levTotal;
    $stats['levenshtein_recent'] = $levRows;
    return $stats;
}

function build_recent_debug_payload($pdo, $limit = 30, $hostFilter = '') {
    $limit = (int) $limit;
    if ($limit <= 0) {
        $limit = 30;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    $hostFilter = to_root_domain((string) $hostFilter);
    $data = collect_recent_blocked_sources($pdo, $limit);

    $rows = $data['rows'];
    $rowsDb = $data['rows_from_db'];
    $rowsJsonl = $data['rows_from_jsonl'];
    $rowsRpz = $data['rows_from_rpz'];
    $rowsReasons = $data['rows_from_reasons'];
    $rowsBeforeReasons = $data['rows_before_reasons_merge'];

    $mergedLevenshtein = [];
    foreach ($rows as $r) {
        if (is_array($r) && is_levenshtein_reason((string) ($r['reason'] ?? ''))) {
            $mergedLevenshtein[] = $r;
        }
    }

    $reasonsLevenshtein = [];
    foreach ($rowsReasons as $r) {
        if (is_array($r) && is_levenshtein_reason((string) ($r['reason'] ?? ''))) {
            $reasonsLevenshtein[] = $r;
        }
    }

    $hostFocus = null;
    if ($hostFilter !== '') {
        $hostRowsDb = filter_rows_by_host($rowsDb, $hostFilter);
        $hostRowsJsonl = filter_rows_by_host($rowsJsonl, $hostFilter);
        $hostRowsRpz = filter_rows_by_host($rowsRpz, $hostFilter);
        $hostRowsReasons = filter_rows_by_host($rowsReasons, $hostFilter);
        $hostRowsMerged = filter_rows_by_host($rows, $hostFilter);

        $reasonsEntry = null;
        $reasonsMap = load_block_reasons_map();
        if (isset($reasonsMap[$hostFilter]) && is_array($reasonsMap[$hostFilter])) {
            $entry = $reasonsMap[$hostFilter];
            $reasonsEntry = [
                'reason' => (string) ($entry['reason'] ?? ''),
                'matched' => to_root_domain((string) ($entry['matched'] ?? '')),
                'updated_at' => $entry['updated_at'] ?? null,
            ];
        }

        $hostFocus = [
            'host' => $hostFilter,
            'in_sources' => [
                'db' => !empty($hostRowsDb),
                'jsonl' => !empty($hostRowsJsonl),
                'rpz_logs' => !empty($hostRowsRpz),
                'block_reasons' => !empty($hostRowsReasons),
                'merged_final' => !empty($hostRowsMerged),
            ],
            'rows' => [
                'db' => summarize_rows_for_debug($hostRowsDb, 10),
                'jsonl' => summarize_rows_for_debug($hostRowsJsonl, 10),
                'rpz_logs' => summarize_rows_for_debug($hostRowsRpz, 10),
                'block_reasons' => summarize_rows_for_debug($hostRowsReasons, 10),
                'merged_final' => summarize_rows_for_debug($hostRowsMerged, 10),
            ],
            'block_reasons_entry' => $reasonsEntry,
        ];
    }

    $rpzFiles = [];
    foreach (get_all_rpz_log_candidates() as $path) {
        $rpzFiles[] = get_file_debug_info($path);
    }

    return [
        'generated_at' => date('c'),
        'limit' => $limit,
        'db_enabled' => ($pdo !== null),
        'counts' => [
            'source_db' => count($rowsDb),
            'source_jsonl' => count($rowsJsonl),
            'source_rpz' => count($rowsRpz),
            'source_block_reasons' => count($rowsReasons),
            'merged_before_reasons' => count($rowsBeforeReasons),
            'merged_final' => count($rows),
            'merged_levenshtein' => count($mergedLevenshtein),
            'source_reasons_levenshtein' => count($reasonsLevenshtein),
            'used_reasons_fallback' => (bool) $data['used_reasons_fallback'],
        ],
        'reason_breakdown' => [
            'db' => summarize_reason_counts($rowsDb),
            'jsonl' => summarize_reason_counts($rowsJsonl),
            'rpz_logs' => summarize_reason_counts($rowsRpz),
            'block_reasons' => summarize_reason_counts($rowsReasons),
            'merged_final' => summarize_reason_counts($rows),
        ],
        'files' => [
            'blocked_visits_jsonl' => get_file_debug_info((string) ($data['paths']['blocked_visits_jsonl'] ?? '/etc/mbox/dns/blocked_visits.jsonl')),
            'block_reasons_json' => get_file_debug_info((string) ($data['paths']['block_reasons_json'] ?? '/etc/mbox/dns/block_reasons.json')),
            'rpz_candidates' => $rpzFiles,
        ],
        'samples' => [
            'merged_final' => summarize_rows_for_debug($rows, 12),
            'merged_levenshtein' => summarize_rows_for_debug($mergedLevenshtein, 12),
            'db' => summarize_rows_for_debug($rowsDb, 8),
            'jsonl' => summarize_rows_for_debug($rowsJsonl, 8),
            'rpz_logs' => summarize_rows_for_debug($rowsRpz, 8),
            'block_reasons' => summarize_rows_for_debug($rowsReasons, 8),
        ],
        'block_reasons_stats' => build_block_reasons_debug_stats(20),
        'host_focus' => $hostFocus,
    ];
}

function ensure_block_reasons_file() {
    $path = '/etc/mbox/dns/block_reasons.json';
    if (file_exists($path)) {
        return $path;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($path, "{}\n", LOCK_EX);
    return $path;
}

function sync_blacklist_rpz() {
    $steps = [
        [
            'name' => 'refresh_rpz',
            'candidates' => [
                __DIR__ . '/../bin/bash/refresh_rpz.sh',
            ],
        ],
        [
            'name' => 'sync_blacklist_rpz',
            'candidates' => [
                __DIR__ . '/../bin/bash/sync_blacklist_rpz.sh',
                __DIR__ . '/../sync_blacklist_rpz.sh',
            ],
        ],
    ];

    $messages = [];
    $results = [];
    $ok = true;
    $code = 0;

    foreach ($steps as $step) {
        $script = '';
        foreach ($step['candidates'] as $candidate) {
            if (file_exists($candidate)) {
                $script = $candidate;
                break;
            }
        }

        if ($script === '') {
            $ok = false;
            $code = 127;
            $results[] = [
                'step' => $step['name'],
                'ok' => false,
                'code' => 127,
                'script' => '',
                'output' => $step['name'] . ': script introuvable',
            ];
            $messages[] = '[' . $step['name'] . '] script introuvable';
            break;
        }

        $output = [];
        $stepCode = 0;
        $cmd = 'sudo ' . escapeshellarg($script) . ' 2>&1';
        @exec($cmd, $output, $stepCode);

        $results[] = [
            'step' => $step['name'],
            'ok' => ($stepCode === 0),
            'code' => $stepCode,
            'script' => $script,
            'output' => implode("\n", $output),
        ];

        if ($stepCode === 0) {
            $messages[] = '[' . $step['name'] . '] OK';
        } else {
            $ok = false;
            $code = $stepCode;
            $messages[] = '[' . $step['name'] . '] KO (code ' . $stepCode . ')';
            break;
        }
    }

    return [
        'ok' => $ok,
        'code' => $code,
        'message' => implode("\n", $messages),
        'steps' => $results,
    ];
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'recent');
$pdo = connect_db_optional();
if ($pdo !== null) {
    ensure_dns_events_table($pdo);
}

if ($action === 'recent') {
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : (isset($_POST['limit']) ? (int) $_POST['limit'] : 30);
    if ($limit <= 0) {
        $limit = 30;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    $recentData = collect_recent_blocked_sources($pdo, $limit);
    $includeReasons = isset($_GET['include_reasons'])
        ? in_array(strtolower((string) $_GET['include_reasons']), ['1', 'true', 'yes', 'on'], true)
        : false;

    $rows = $recentData['rows_before_reasons_merge'];
    if ($includeReasons) {
        $rows = merge_rows_with_recent_block_reasons($rows, $limit);
        if (empty($rows)) {
            $rows = gather_block_reasons_recent($limit);
        }
    }

    $rows = enrich_rows_with_block_reasons($rows);
    echo json_encode(['success' => true, 'rows' => $rows]);
    exit;
}

if ($action === 'recent_debug') {
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : (isset($_POST['limit']) ? (int) $_POST['limit'] : 30);
    if ($limit <= 0) {
        $limit = 30;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    $hostFilter = (string) ($_GET['host'] ?? ($_POST['host'] ?? ''));
    $debug = build_recent_debug_payload($pdo, $limit, $hostFilter);

    echo json_encode([
        'success' => true,
        'debug' => $debug,
    ]);
    exit;
}

if ($action === 'list_all') {
    $targets = [
        'blacklist' => 'blacklist.txt',
        'knowdomain' => 'knowdomain.txt',
    ];
    $out = [];
    foreach ($targets as $key => $file) {
        $path = resolve_list_path($file);
        $entries = [];
        if (is_readable($path)) {
            foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim(preg_replace('/#.*$/', '', $line));
                if ($line === '') continue;
                $d = normalize_domain($line);
                if ($d !== '') $entries[] = $d;
            }
            $entries = array_values(array_unique($entries));
            sort($entries);
        }
        $out[$key] = [
            'path' => $path,
            'count' => count($entries),
            'entries' => $entries,
        ];
    }
    echo json_encode(['success' => true, 'lists' => $out]);
    exit;
}

if ($action === 'get_raw_lists') {
    $blPath = resolve_list_path('blacklist.txt');
    $kdPath = resolve_list_path('knowdomain.txt');

    echo json_encode([
        'success' => true,
        'blacklist' => [
            'path' => $blPath,
            'content' => read_raw_list_file($blPath),
        ],
        'knowdomain' => [
            'path' => $kdPath,
            'content' => read_raw_list_file($kdPath),
        ],
    ]);
    exit;
}

if ($action === 'set_raw_lists') {
    $blRaw = (string) ($_POST['blacklist_text'] ?? ($_GET['blacklist_text'] ?? ''));
    $kdRaw = (string) ($_POST['knowdomain_text'] ?? ($_GET['knowdomain_text'] ?? ''));

    $blPath = resolve_list_path('blacklist.txt');
    $kdPath = resolve_list_path('knowdomain.txt');

    write_raw_list_file($blPath, $blRaw);
    write_raw_list_file($kdPath, $kdRaw);

    $normalize = normalize_domain_lists_files($blPath, $kdPath);
    $sync = sync_blacklist_rpz();

    echo json_encode([
        'success' => true,
        'blacklist_path' => $blPath,
        'knowdomain_path' => $kdPath,
        'normalize' => $normalize,
        'sync' => $sync,
    ]);
    exit;
}

if ($action === 'history_all') {
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : (isset($_POST['limit']) ? (int) $_POST['limit'] : 100);
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : (isset($_POST['offset']) ? (int) $_POST['offset'] : 0);
    $q = to_root_domain((string) ($_GET['q'] ?? ($_POST['q'] ?? '')));

    if ($limit <= 0) {
        $limit = 100;
    }
    if ($limit > 500) {
        $limit = 500;
    }
    if ($offset < 0) {
        $offset = 0;
    }

    $rows = [];
    $total = 0;

    if ($pdo !== null) {
        $logPath = '/etc/mbox/dns/blocked_visits.jsonl';
        if (file_exists($logPath)) {
            ingest_blocked_jsonl_into_db($pdo, $logPath);
        }
        ingest_rpz_logs_into_db($pdo, 2000);

        // On lit large puis on applique la regle metier: 1 domaine / personne / jour.
        $scanLimit = max(2000, ($offset + $limit) * 50);
        if ($scanLimit > 50000) {
            $scanLimit = 50000;
        }

        $all = [];
        foreach (fetch_dns_events($pdo, 'blocked', $scanLimit) as $r) {
            $ip = (string) ($r['ip_source'] ?? '');
            $reason = (string) ($r['reason_code'] ?? 'blocked');
            $all[] = [
                'ts' => (string) ($r['created_at'] ?? ''),
                'host' => to_root_domain((string) ($r['domain'] ?? '')),
                'reason' => $reason,
                'reason_label' => reason_label($reason),
                'matched' => to_root_domain((string) ($r['matched_domain'] ?? '')),
                'ip' => $ip,
                'mac' => (string) ($r['mac_source'] ?? ''),
                'device_name' => device_name_from_ip($ip),
                'distance' => $r['distance'] ?? null,
            ];
        }

        if ($q !== '') {
            $all = array_values(array_filter($all, function ($row) use ($q) {
                return strpos((string) ($row['host'] ?? ''), $q) !== false;
            }));
        }

        $all = merge_recent_rows([$all], 0);
        $all = enrich_rows_with_block_reasons($all);
        $total = count($all);
        $rows = array_slice($all, $offset, $limit);
    } else {
        $recentData = collect_recent_blocked_sources($pdo, max(1200, ($offset + $limit) * 20));
        $all = $recentData['rows_before_reasons_merge'];

        if ($q !== '') {
            $all = array_values(array_filter($all, function ($row) use ($q) {
                return strpos((string) ($row['host'] ?? ''), $q) !== false;
            }));
        }

        $all = merge_recent_rows([$all], 0);
        $all = enrich_rows_with_block_reasons($all);
        $total = count($all);
        $rows = array_slice($all, $offset, $limit);
    }

    echo json_encode([
        'success' => true,
        'rows' => $rows,
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'count' => count($rows),
            'total' => $total,
            'has_more' => ($offset + count($rows)) < $total,
        ],
    ]);
    exit;
}

if ($action === 'remove') {
    $rawDomain = $_POST['domain'] ?? ($_GET['domain'] ?? '');
    $target = $_POST['target'] ?? ($_GET['target'] ?? '');
    $domain = normalize_domain((string)$rawDomain);

    if ($domain === '' || !in_array($target, ['blacklist', 'knowdomain'], true)) {
        echo json_encode(['success' => false, 'error' => 'Parametres invalides']);
        exit;
    }

    $fileName = $target === 'blacklist' ? 'blacklist.txt' : 'knowdomain.txt';
    $path = resolve_list_path($fileName);

    if (!file_exists($path)) {
        echo json_encode(['success' => true, 'removed' => false, 'note' => 'Fichier absent']);
        exit;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $kept = [];
    $removed = false;
    foreach ($lines as $line) {
        $stripped = trim(preg_replace('/#.*$/', '', $line));
        if ($stripped === '') {
            $kept[] = $line;
            continue;
        }
        $d = normalize_domain($stripped);
        if ($d === $domain) {
            $removed = true;
            continue;
        }
        $kept[] = $line;
    }

    if ($removed) {
        file_put_contents($path, implode("\n", $kept) . "\n", LOCK_EX);
    }

    $sync = ['ok' => true, 'message' => ''];
    if ($target === 'blacklist' && $removed) {
        $sync = sync_blacklist_rpz();
    }

    echo json_encode([
        'success' => true,
        'removed' => $removed,
        'target' => $target,
        'domain' => $domain,
        'sync' => $sync,
    ]);
    exit;
}

if ($action === 'clear') {
    $target = $_POST['target'] ?? ($_GET['target'] ?? '');
    if (!in_array($target, ['blacklist', 'knowdomain'], true)) {
        echo json_encode(['success' => false, 'error' => 'Cible invalide']);
        exit;
    }
    $fileName = $target === 'blacklist' ? 'blacklist.txt' : 'knowdomain.txt';
    $path = resolve_list_path($fileName);
    @file_put_contents($path, "", LOCK_EX);

    $sync = ['ok' => true];
    if ($target === 'blacklist') {
        $sync = sync_blacklist_rpz();
    }
    echo json_encode(['success' => true, 'cleared' => $target, 'sync' => $sync]);
    exit;
}

if ($action === 'add') {
    $rawDomain = $_POST['domain'] ?? '';
    $target = $_POST['target'] ?? '';
    $domain = to_root_domain((string)$rawDomain);

    if ($domain === '' || !in_array($target, ['blacklist', 'knowdomain'], true)) {
        echo json_encode(['success' => false, 'error' => 'Parametres invalides']);
        exit;
    }

    $fileName = $target === 'blacklist' ? 'blacklist.txt' : 'knowdomain.txt';
    $path = resolve_list_path($fileName);

    $added = append_unique_domain($path, $domain);

    $sync = ['ok' => true, 'message' => ''];
    if ($target === 'blacklist') {
        ensure_block_reasons_file();
        $sync = sync_blacklist_rpz();
    }

    echo json_encode([
        'success' => true,
        'added' => $added,
        'target' => $target,
        'domain' => $domain,
        'path' => $path,
        'sync' => $sync,
    ]);
    exit;
}

if ($action === 'recent_visited') {
    $rows = gather_recent_visited_domains(120);
    if ($pdo !== null) {
        ingest_seen_into_db($pdo, $rows);
        $dbRows = fetch_dns_events($pdo, 'seen', 200);
        $blocked = load_blocked_hosts_set();
        $knowdomain = load_knowdomain_hosts_set();
        $out = [];
        foreach ($dbRows as $r) {
            $host = $r['domain'] ?? '';
            if ($host === '' || isset($blocked[$host]) || isset($knowdomain[$host])) {
                continue;
            }
            $ip = $r['ip_source'] ?? '';
            $out[] = [
                'host' => $host,
                'ip' => $ip,
                'mac' => $r['mac_source'] ?? '',
                'device_name' => device_name_from_ip($ip),
                'reason' => $r['reason_code'] ?? 'allow',
                'reason_label' => reason_label($r['reason_code'] ?? 'allow'),
            ];
            if (count($out) >= 40) {
                break;
            }
        }
        echo json_encode([
            'success' => true,
            'rows' => $out,
            'note' => 'Source BDD dns_events (ingestion logs DNS)',
        ]);
        exit;
    }

    foreach ($rows as &$r) {
        $r['device_name'] = device_name_from_ip($r['ip'] ?? '');
    }
    unset($r);

    echo json_encode([
        'success' => true,
        'rows' => $rows,
        'note' => 'Source DNS logs (si disponible sur la box)',
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Action inconnue']);
