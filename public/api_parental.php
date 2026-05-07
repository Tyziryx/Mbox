<?php
// api_parental.php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/paths.php';

$config_path = '/etc/mbox/parental_config.json';
$stats_path = '/etc/mbox/parental_stats.json';
$stats_table = 'parental_category_stats';
$quota_table = 'parental_quota_usage';
$stats_modes = ['child', 'teen', 'adult'];
$stats_categories = ['adult', 'games', 'streaming', 'social', 'ads'];
$allowed_modes = ['child', 'teen', 'adult'];
$quota_runtime_script = MBOX_BIN_BASH . '/quota_runtime.sh';

function json_response(array $payload) {
    echo json_encode($payload);
    exit;
}

function base_parental_stats(array $modes, array $categories): array {
    $seed_values = [
        'child' => ['adult' => 38, 'games' => 22, 'streaming' => 18, 'social' => 34, 'ads' => 27],
        'teen'  => ['adult' => 31, 'games' => 24, 'streaming' => 21, 'social' => 29, 'ads' => 23],
        'adult' => ['adult' => 0, 'games' => 8, 'streaming' => 7, 'social' => 0, 'ads' => 11],
    ];

    $normalized_modes = [];
    foreach ($modes as $mode) {
        $normalized_modes[$mode] = [];
        foreach ($categories as $category) {
            $normalized_modes[$mode][$category] = (int)($seed_values[$mode][$category] ?? 1);
        }
    }

    return [
        'seeded' => true,
        'updated_at' => date(DATE_ATOM),
        'modes' => $normalized_modes,
    ];
}

function normalize_parental_stats(array $stats, array $modes, array $categories): array {
    $normalized = base_parental_stats($modes, $categories);

    if (isset($stats['seeded'])) {
        $normalized['seeded'] = (bool)$stats['seeded'];
    }
    if (!empty($stats['updated_at']) && is_string($stats['updated_at'])) {
        $normalized['updated_at'] = $stats['updated_at'];
    }

    if (isset($stats['modes']) && is_array($stats['modes'])) {
        foreach ($modes as $mode) {
            $mode_values = $stats['modes'][$mode] ?? [];
            foreach ($categories as $category) {
                $value = $mode_values[$category] ?? $normalized['modes'][$mode][$category];
                $normalized['modes'][$mode][$category] = max(0, (int)$value);
            }
        }
    }

    return $normalized;
}

function write_parental_stats(string $stats_path, array $stats): bool {
    return file_put_contents($stats_path, json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function load_env_file_values(string $envPath): array {
    if (!is_readable($envPath)) {
        return [];
    }

    $vars = [];
    $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        list($key, $value) = explode('=', $line, 2);
        $vars[trim($key)] = trim($value);
    }
    return $vars;
}

function get_optional_stats_pdo() {
    static $initialized = false;
    static $pdo = null;

    if ($initialized) {
        return $pdo;
    }
    $initialized = true;

    $envFileVars = load_env_file_values(__DIR__ . '/../config/.env');

    $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: ($envFileVars['DB_HOST'] ?? 'localhost');
    $db = $_ENV['PARENTAL_STATS_DB_NAME'] ?? getenv('PARENTAL_STATS_DB_NAME')
        ?: ($envFileVars['PARENTAL_STATS_DB_NAME'] ?? ($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: ($envFileVars['DB_NAME'] ?? 'box_db')));
    $user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: ($envFileVars['DB_USER'] ?? 'box_user');
    $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: ($envFileVars['DB_PASS'] ?? '');

    try {
        $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

function ensure_parental_stats_table($pdo, string $table): bool {
    if ($pdo === null) {
        return false;
    }

    try {
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            mode VARCHAR(16) NOT NULL,
            category VARCHAR(32) NOT NULL,
            count_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mode_category (mode, category),
            KEY idx_mode (mode),
            KEY idx_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec($sql);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function seed_parental_stats_db(PDO $pdo, string $table, array $seedStats, array $modes, array $categories): bool {
    try {
        $stmt = $pdo->prepare("INSERT INTO {$table} (mode, category, count_value) VALUES (:mode, :category, :count_value)
            ON DUPLICATE KEY UPDATE count_value = IF(count_value < VALUES(count_value), VALUES(count_value), count_value)");

        foreach ($modes as $mode) {
            foreach ($categories as $category) {
                $seedValue = (int) ($seedStats['modes'][$mode][$category] ?? 0);
                $stmt->execute([
                    ':mode' => $mode,
                    ':category' => $category,
                    ':count_value' => $seedValue,
                ]);
            }
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function load_parental_stats_db($pdo, string $table, array $modes, array $categories): array {
    if ($pdo === null || !ensure_parental_stats_table($pdo, $table)) {
        return [null, false, false];
    }

    try {
        $seed = base_parental_stats($modes, $categories);
        seed_parental_stats_db($pdo, $table, $seed, $modes, $categories);

        $stats = base_parental_stats($modes, $categories);
        $maxUpdated = null;

        $rows = $pdo->query("SELECT mode, category, count_value, updated_at FROM {$table}")->fetchAll();
        foreach ($rows as $row) {
            $mode = (string) ($row['mode'] ?? '');
            $category = (string) ($row['category'] ?? '');
            if (!in_array($mode, $modes, true) || !in_array($category, $categories, true)) {
                continue;
            }

            $stats['modes'][$mode][$category] = max(0, (int) ($row['count_value'] ?? 0));
            $updated = (string) ($row['updated_at'] ?? '');
            if ($updated !== '' && ($maxUpdated === null || strcmp($updated, $maxUpdated) > 0)) {
                $maxUpdated = $updated;
            }
        }

        if ($maxUpdated !== null) {
            $stats['updated_at'] = date(DATE_ATOM, strtotime($maxUpdated));
        }

        return [$stats, false, true];
    } catch (Throwable $e) {
        return [null, false, false];
    }
}

function increment_parental_stats_db($pdo, string $table, string $mode, array $categories): bool {
    if ($pdo === null || empty($categories) || !ensure_parental_stats_table($pdo, $table)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO {$table} (mode, category, count_value)
            VALUES (:mode, :category, 1)
            ON DUPLICATE KEY UPDATE count_value = count_value + 1, updated_at = CURRENT_TIMESTAMP");
        foreach ($categories as $category) {
            $stmt->execute([
                ':mode' => $mode,
                ':category' => $category,
            ]);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function load_parental_stats(string $stats_path, array $modes, array $categories): array {
    $recreated = false;

    if (!file_exists($stats_path)) {
        $stats = base_parental_stats($modes, $categories);
        write_parental_stats($stats_path, $stats);
        return [$stats, true];
    }

    $raw = @file_get_contents($stats_path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        $stats = base_parental_stats($modes, $categories);
        write_parental_stats($stats_path, $stats);
        return [$stats, true];
    }

    $stats = normalize_parental_stats($decoded, $modes, $categories);
    if ($stats !== $decoded) {
        write_parental_stats($stats_path, $stats);
        $recreated = true;
    }

    return [$stats, $recreated];
}

function load_parental_configs(string $config_path): array {
    if (!file_exists($config_path)) {
        return [];
    }

    $raw = @file_get_contents($config_path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function write_parental_configs(string $config_path, array $configs): bool {
    return file_put_contents($config_path, json_encode($configs, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function ensure_parental_quota_table($pdo, string $table): bool {
    if ($pdo === null) {
        return false;
    }

    try {
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            mac VARCHAR(17) NOT NULL,
            device_name VARCHAR(255) NOT NULL DEFAULT '',
            ip_address VARCHAR(45) DEFAULT NULL,
            quota_gb DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            quota_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            usage_date DATE NOT NULL DEFAULT '1970-01-01',
            used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            remaining_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_counter_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            blocked_by_quota TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (mac),
            KEY idx_usage_date (usage_date),
            KEY idx_blocked_by_quota (blocked_by_quota),
            KEY idx_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec($sql);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function quota_bytes_from_gb(float $quota_gb): int {
    if ($quota_gb <= 0) {
        return 0;
    }
    $bytes = (float)$quota_gb * 1024 * 1024 * 1024;
    return (int)max(0, round($bytes));
}

function bytes_to_gb_rounded(int $bytes): float {
    if ($bytes <= 0) {
        return 0.0;
    }
    return round(((float)$bytes) / (1024 * 1024 * 1024), 3);
}

function next_daily_reset_at_iso(): string {
    $tomorrow = strtotime('tomorrow midnight');
    if ($tomorrow === false) {
        $tomorrow = time() + 86400;
    }
    return date(DATE_ATOM, $tomorrow);
}

function decode_json_from_command_output(string $raw_output) {
    $trimmed = trim($raw_output);
    if ($trimmed === '') {
        return null;
    }

    $decoded = json_decode($trimmed, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $lines = preg_split('/\r\n|\r|\n/', $trimmed) ?: [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim((string)$lines[$i]);
        if ($line === '') {
            continue;
        }
        $decodedLine = json_decode($line, true);
        if (is_array($decodedLine)) {
            return $decodedLine;
        }
    }

    return null;
}

function run_quota_runtime_command(string $runtime_script, array $args): array {
    if (!is_file($runtime_script)) {
        return [false, null, 'Script quota introuvable: ' . $runtime_script, ''];
    }

    $parts = ['sudo', '-n', escapeshellarg($runtime_script)];
    foreach ($args as $arg) {
        $parts[] = escapeshellarg((string)$arg);
    }

    $command = implode(' ', $parts);
    $output_lines = [];
    $exit_code = 1;
    exec($command . ' 2>&1', $output_lines, $exit_code);
    $raw_output = implode("\n", $output_lines);

    $decoded = decode_json_from_command_output($raw_output);

    if ($exit_code !== 0) {
        $error = 'quota_runtime.sh erreur';
        if (is_array($decoded) && !empty($decoded['error'])) {
            $error = (string)$decoded['error'];
        } elseif (trim($raw_output) !== '') {
            $error = trim($raw_output);
        }
        return [false, $decoded, $error, $raw_output];
    }

    if (!is_array($decoded)) {
        return [false, null, 'R??ponse quota_runtime invalide', $raw_output];
    }

    if (isset($decoded['success']) && !$decoded['success']) {
        $error = (string)($decoded['error'] ?? 'quota_runtime a ??chou??');
        return [false, $decoded, $error, $raw_output];
    }

    return [true, $decoded, '', $raw_output];
}

function find_quota_runtime_entry(array $payload, string $mac) {
    if (!isset($payload['entries']) || !is_array($payload['entries'])) {
        return null;
    }
    foreach ($payload['entries'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entry_mac = strtoupper(trim((string)($entry['mac'] ?? '')));
        if ($entry_mac === $mac) {
            return $entry;
        }
    }
    return null;
}

function compute_quota_status_for_mac($pdo, string $quota_table, string $runtime_script, array $all_configs, string $mac): array {
    $existing = (isset($all_configs[$mac]) && is_array($all_configs[$mac])) ? $all_configs[$mac] : null;
    if ($existing === null) {
        return [
            'success' => false,
            'error' => 'Appareil introuvable dans la configuration parentale.',
        ];
    }

    $name = trim((string)($existing['name'] ?? $mac));
    if ($name === '') {
        $name = $mac;
    }

    $quota_gb = isset($existing['download_quota_gb']) ? round((float)$existing['download_quota_gb'], 2) : 0.0;
    if ($quota_gb < 0) {
        $quota_gb = 0.0;
    }
    $quota_enabled = $quota_gb > 0;
    $quota_bytes = quota_bytes_from_gb($quota_gb);

    if (!$quota_enabled) {
        list($blockOk, $blockPayload, $blockError) = run_quota_runtime_command($runtime_script, ['block', $mac, 'off']);

        return [
            'success' => true,
            'mac' => $mac,
            'name' => $name,
            'ip' => null,
            'quota_enabled' => false,
            'quota_gb' => 0.0,
            'quota_bytes' => 0,
            'used_bytes' => 0,
            'used_gb' => 0.0,
            'remaining_bytes' => 0,
            'remaining_gb' => 0.0,
            'percent_used' => 0,
            'blocked_by_quota' => false,
            'collector_state' => 'disabled',
            'reset_at' => next_daily_reset_at_iso(),
            'updated_at' => date(DATE_ATOM),
            'block_sync' => [
                'ok' => $blockOk,
                'error' => $blockOk ? null : $blockError,
            ],
        ];
    }

    if ($pdo === null || !ensure_parental_quota_table($pdo, $quota_table)) {
        return [
            'success' => false,
            'error' => 'Base quota indisponible (table parental_quota_usage).',
        ];
    }

    list($syncOk, $syncPayload, $syncError) = run_quota_runtime_command($runtime_script, ['sync', $mac]);
    if (!$syncOk || !is_array($syncPayload)) {
        return [
            'success' => false,
            'error' => 'Collecte quota impossible: ' . $syncError,
        ];
    }

    $runtime_entry = find_quota_runtime_entry($syncPayload, $mac);
    if (!is_array($runtime_entry)) {
        return [
            'success' => false,
            'error' => 'Compteur quota introuvable pour cet appareil.',
        ];
    }

    $counter_bytes = max(0, (int)($runtime_entry['counter_bytes'] ?? 0));
    $ip_address = isset($runtime_entry['ip']) && is_string($runtime_entry['ip']) && $runtime_entry['ip'] !== ''
        ? $runtime_entry['ip']
        : null;
    $counter_ready = !empty($runtime_entry['counter_rule_ready']);

    $today = date('Y-m-d');
    $used_bytes = 0;
    $last_counter_bytes = $counter_bytes;

    try {
        $stmtSelect = $pdo->prepare("SELECT usage_date, used_bytes, last_counter_bytes FROM {$quota_table} WHERE mac = :mac LIMIT 1");
        $stmtSelect->execute([':mac' => $mac]);
        $row = $stmtSelect->fetch();

        if (is_array($row) && isset($row['usage_date']) && (string)$row['usage_date'] === $today) {
            $previous_used = max(0, (int)($row['used_bytes'] ?? 0));
            $previous_counter = max(0, (int)($row['last_counter_bytes'] ?? 0));
            $delta = ($counter_bytes >= $previous_counter) ? ($counter_bytes - $previous_counter) : $counter_bytes;
            $used_bytes = $previous_used + max(0, $delta);
        }

        $last_counter_bytes = $counter_bytes;
        $blocked = $quota_bytes > 0 && $used_bytes >= $quota_bytes;
        $remaining_bytes = $quota_bytes > 0 ? max(0, $quota_bytes - $used_bytes) : 0;

        $stmtUpsert = $pdo->prepare("INSERT INTO {$quota_table}
            (mac, device_name, ip_address, quota_gb, quota_bytes, usage_date, used_bytes, remaining_bytes, last_counter_bytes, blocked_by_quota)
            VALUES
            (:mac, :device_name, :ip_address, :quota_gb, :quota_bytes, :usage_date, :used_bytes, :remaining_bytes, :last_counter_bytes, :blocked_by_quota)
            ON DUPLICATE KEY UPDATE
                device_name = VALUES(device_name),
                ip_address = VALUES(ip_address),
                quota_gb = VALUES(quota_gb),
                quota_bytes = VALUES(quota_bytes),
                usage_date = VALUES(usage_date),
                used_bytes = VALUES(used_bytes),
                remaining_bytes = VALUES(remaining_bytes),
                last_counter_bytes = VALUES(last_counter_bytes),
                blocked_by_quota = VALUES(blocked_by_quota),
                updated_at = CURRENT_TIMESTAMP");

        $stmtUpsert->execute([
            ':mac' => $mac,
            ':device_name' => $name,
            ':ip_address' => $ip_address,
            ':quota_gb' => $quota_gb,
            ':quota_bytes' => $quota_bytes,
            ':usage_date' => $today,
            ':used_bytes' => $used_bytes,
            ':remaining_bytes' => $remaining_bytes,
            ':last_counter_bytes' => $last_counter_bytes,
            ':blocked_by_quota' => $blocked ? 1 : 0,
        ]);

        list($blockOk, $blockPayload, $blockError) = run_quota_runtime_command($runtime_script, ['block', $mac, $blocked ? 'on' : 'off']);

        $percent_used = 0;
        if ($quota_bytes > 0) {
            $percent_used = round(min(100, (($used_bytes / $quota_bytes) * 100)), 2);
        }

        return [
            'success' => true,
            'mac' => $mac,
            'name' => $name,
            'ip' => $ip_address,
            'quota_enabled' => true,
            'quota_gb' => $quota_gb,
            'quota_bytes' => $quota_bytes,
            'used_bytes' => $used_bytes,
            'used_gb' => bytes_to_gb_rounded($used_bytes),
            'remaining_bytes' => $remaining_bytes,
            'remaining_gb' => bytes_to_gb_rounded($remaining_bytes),
            'percent_used' => $percent_used,
            'blocked_by_quota' => $blocked,
            'collector_state' => $counter_ready ? 'ok' : 'waiting_ip',
            'reset_at' => next_daily_reset_at_iso(),
            'updated_at' => date(DATE_ATOM),
            'block_sync' => [
                'ok' => $blockOk,
                'error' => $blockOk ? null : $blockError,
            ],
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'error' => 'Erreur BDD quota: ' . $e->getMessage(),
        ];
    }
}

function normalize_filters_for_stats(array $filters, array $categories): array {
    $normalized = [];
    foreach ($categories as $category) {
        $normalized[$category] = !empty($filters[$category]);
    }
    return $normalized;
}

function compute_newly_enabled_filters(array $before, array $after, array $categories): array {
    $enabled = [];
    foreach ($categories as $category) {
        $was_enabled = !empty($before[$category]);
        $is_enabled = !empty($after[$category]);
        if ($is_enabled && !$was_enabled) {
            $enabled[] = $category;
        }
    }
    return $enabled;
}

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';

$stats_pdo = get_optional_stats_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'stats_summary') {
    list($statsFromDb, $recreatedDb, $okDb) = load_parental_stats_db($stats_pdo, $stats_table, $stats_modes, $stats_categories);
    $source = 'db';
    if ($okDb && is_array($statsFromDb)) {
        $stats = $statsFromDb;
        $recreated = $recreatedDb;
    } else {
        list($stats, $recreated) = load_parental_stats($stats_path, $stats_modes, $stats_categories);
        $source = 'json';
    }

    json_response([
        'success' => true,
        'modes' => $stats['modes'],
        'updated_at' => $stats['updated_at'],
        'recreated' => $recreated,
        'source' => $source,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'quota_status') {
    $macParam = isset($_GET['mac']) ? strtoupper(trim((string)$_GET['mac'])) : '';
    if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $macParam)) {
        json_response([
            'success' => false,
            'error' => 'Param??tre mac invalide.',
        ]);
    }

    $allConfigs = load_parental_configs($config_path);
    $quotaStatus = compute_quota_status_for_mac($stats_pdo, $quota_table, $quota_runtime_script, $allConfigs, $macParam);
    json_response($quotaStatus);
}

// 1. R??cup??ration du JSON envoy?? par le JS
$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';
if (!$is_post) {
    json_response(['success' => false, 'error' => 'M??thode non support??e.']);
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!is_array($data) || !isset($data['mac'])) {
    json_response(['success' => false, 'error' => 'Donn??es invalides.']);
}

// 2. S??curit?? : Validation stricte de l'adresse MAC (pour ??viter les injections)
$mac = strtoupper(trim($data['mac']));
if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
    json_response(['success' => false, 'error' => 'Format MAC invalide.']);
}

$quota_provided = array_key_exists('download_quota_gb', $data);
$download_quota_gb = null;
if ($quota_provided) {
    $quota_raw = $data['download_quota_gb'];
    if ($quota_raw === '' || $quota_raw === null) {
        $download_quota_gb = 0.0;
    } elseif (!is_numeric($quota_raw)) {
        json_response(['success' => false, 'error' => 'Quota journalier invalide.']);
    } else {
        $download_quota_gb = (float)$quota_raw;
        if ($download_quota_gb < 0) {
            json_response(['success' => false, 'error' => 'Le quota journalier doit ??tre >= 0.']);
        }
    }
    $download_quota_gb = round((float)$download_quota_gb, 2);
}

$all_configs = load_parental_configs($config_path);
$existing_config = (isset($all_configs[$mac]) && is_array($all_configs[$mac])) ? $all_configs[$mac] : [];

$mode_raw = trim((string)($data['mode'] ?? ($existing_config['mode'] ?? 'child')));
$mode = in_array($mode_raw, $allowed_modes, true) ? $mode_raw : 'child';

$name = trim((string)($data['name'] ?? ($existing_config['name'] ?? $mac)));
if ($name === '') {
    $name = $mac;
}

$filters = $data['filters'] ?? ($existing_config['filters'] ?? []);
if (!is_array($filters)) {
    $filters = [];
}
$filters = normalize_filters_for_stats($filters, $stats_categories);
$existing_filters = normalize_filters_for_stats(
    (isset($existing_config['filters']) && is_array($existing_config['filters'])) ? $existing_config['filters'] : [],
    $stats_categories
);

$schedule = $data['schedule'] ?? ($existing_config['schedule'] ?? []);
if (!is_array($schedule)) {
    $schedule = [];
}

$merged_config = $existing_config;
$merged_config['name'] = $name;
$merged_config['mode'] = $mode;
$merged_config['filters'] = $filters;
$merged_config['schedule'] = $schedule;
if ($quota_provided) {
    $merged_config['download_quota_gb'] = $download_quota_gb;
} elseif (isset($existing_config['download_quota_gb'])) {
    $merged_config['download_quota_gb'] = round((float)$existing_config['download_quota_gb'], 2);
}

$all_configs[$mac] = $merged_config;
if (!write_parental_configs($config_path, $all_configs)) {
    json_response(['success' => false, 'error' => 'Impossible d\'??crire parental_config.json']);
}

$effective_quota_gb = isset($merged_config['download_quota_gb']) ? round((float)$merged_config['download_quota_gb'], 2) : 0.0;
$quota_status = null;
$quota_status_error = null;
$needs_quota_status = ($action === 'save_quota') || ($effective_quota_gb > 0);
if ($needs_quota_status) {
    $quota_status = compute_quota_status_for_mac($stats_pdo, $quota_table, $quota_runtime_script, $all_configs, $mac);
    if (!empty($quota_status['success'])) {
        $quota_status_error = null;
    } else {
        $quota_status_error = (string)($quota_status['error'] ?? 'Statut quota indisponible');
    }
}

$is_quota_only = ($action === 'save_quota') || (
    $quota_provided
    && !array_key_exists('mode', $data)
    && !array_key_exists('filters', $data)
    && !array_key_exists('schedule', $data)
);

$stats_source = 'db';
list($statsFromDb, $stats_recreated, $okDb) = load_parental_stats_db($stats_pdo, $stats_table, $stats_modes, $stats_categories);
if ($okDb && is_array($statsFromDb)) {
    $stats = $statsFromDb;
} else {
    list($stats, $stats_recreated) = load_parental_stats($stats_path, $stats_modes, $stats_categories);
    $stats_source = 'json';
}
$stats_mode = $mode;
if (!in_array($stats_mode, $stats_modes, true)) {
    $stats_mode = 'child';
}

$active_filters = [];
foreach ($stats_categories as $category) {
    if (!empty($filters[$category])) {
        $active_filters[] = $category;
    }
}

$incremented_filters = [];
if (!$is_quota_only) {
    $incremented_filters = compute_newly_enabled_filters($existing_filters, $filters, $stats_categories);

    if ($stats_source === 'db' && !empty($incremented_filters)) {
        $dbWriteOk = increment_parental_stats_db($stats_pdo, $stats_table, $stats_mode, $incremented_filters);
        if ($dbWriteOk) {
            list($statsAfterDb, $stats_recreated_db, $okDbAfter) = load_parental_stats_db($stats_pdo, $stats_table, $stats_modes, $stats_categories);
            if ($okDbAfter && is_array($statsAfterDb)) {
                $stats = $statsAfterDb;
                $stats_recreated = $stats_recreated_db;
            }
        }
    }

    if ($stats_source === 'json') {
        foreach ($incremented_filters as $category) {
            $stats['modes'][$stats_mode][$category] = (int)$stats['modes'][$stats_mode][$category] + 1;
        }
        $stats['updated_at'] = date(DATE_ATOM);
        if (!write_parental_stats($stats_path, $stats)) {
            $stats_recreated = true;
        }
    }
}

if ($is_quota_only) {
    if ($action === 'save_quota' && $quota_status_error !== null) {
        json_response([
            'success' => false,
            'error' => $quota_status_error,
        ]);
    }

    json_response([
        'success' => true,
        'message' => 'Quota journalier sauvegard??.',
        'download_quota_gb' => $merged_config['download_quota_gb'] ?? 0,
        'quota_status' => ($quota_status && !empty($quota_status['success'])) ? $quota_status : null,
        'quota_status_error' => $quota_status_error,
        'stats_summary' => [
            'modes' => $stats['modes'],
            'updated_at' => $stats['updated_at'],
            'recreated' => $stats_recreated,
            'source' => $stats_source,
        ],
    ]);
}

// 3. L'ALGORITHME DE COMPRESSION DES HORAIRES (Le Cerveau)
// On transforme la matrice 7x24 en plages horaires propres pour iptables
$days_map = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$time_blocks = [];

if (is_array($schedule)) {
    foreach ($schedule as $day_idx => $hours) {
        if (!isset($days_map[$day_idx]) || !is_array($hours)) {
            continue;
        }
        $day_name = $days_map[$day_idx];
        $in_block = false;
        $start_hour = 0;

        // On boucle jusqu'?? 24 pour fermer un bloc qui irait jusqu'?? minuit
        for ($h = 0; $h <= 24; $h++) {
            $is_blocked = ($h < 24 && isset($hours[$h]) && $hours[$h] === 'blocked');
            
            if ($is_blocked && !$in_block) {
                // D??but d'un blocage
                $in_block = true;
                $start_hour = $h;
            } elseif (!$is_blocked && $in_block) {
                // Fin du blocage
                $in_block = false;
                $end_hour = $h;
                
                // Formatage de l'heure pour iptables (ex: 08:00, 23:59)
                $start_str = sprintf("%02d:00", $start_hour);
                $end_str = ($end_hour == 24) ? "23:59" : sprintf("%02d:00", $end_hour);
                
                $time_blocks[] = [
                    'day' => $day_name,
                    'start' => $start_str,
                    'end' => $end_str
                ];
            }
        }
    }
}

// 5. Pr??paration du fichier de configuration pour le script Bash
$config_export = [
    'mac' => $mac,
    'mode' => $mode,
    'blocks' => $time_blocks,
    'download_quota_gb' => isset($merged_config['download_quota_gb']) ? round((float)$merged_config['download_quota_gb'], 2) : 0.0,
];

// On ??crit ??a dans un fichier temporaire
$clean_mac = str_replace(':', '', $mac);
$tmp_file = "/tmp/parental_{$clean_mac}.json";
$tmp_written = file_put_contents($tmp_file, json_encode($config_export));
if ($tmp_written === false) {
    json_response(['success' => false, 'error' => 'Impossible de pr??parer le fichier temporaire parental.']);
}

// 6. Ex??cution du script syst??me (Les Muscles)
$script = MBOX_BIN_BASH . '/apply_parental.sh';
$command = "sudo -n " . escapeshellarg($script) . " " . escapeshellarg($tmp_file);
exec($command . " 2>&1", $output_lines, $exit_code);
$output = implode("\n", $output_lines);

if ($quota_status && !empty($quota_status['success']) && !empty($quota_status['quota_enabled'])) {
    $quota_block_state = !empty($quota_status['blocked_by_quota']) ? 'on' : 'off';
    list($quotaBlockOkAfterApply, $quotaBlockPayloadAfterApply, $quotaBlockErrorAfterApply) = run_quota_runtime_command(
        $quota_runtime_script,
        ['block', $mac, $quota_block_state]
    );
    if (!$quotaBlockOkAfterApply) {
        $quota_status_error = $quotaBlockErrorAfterApply;
    }
}

$response_payload = [
    'stats_summary' => [
        'modes' => $stats['modes'],
        'updated_at' => $stats['updated_at'],
        'recreated' => $stats_recreated,
        'source' => $stats_source,
        'mode_counted' => $stats_mode,
        'active_filters' => $active_filters,
        'incremented_filters' => $incremented_filters,
    ],
];

if ($quota_status && !empty($quota_status['success'])) {
    $response_payload['quota_status'] = $quota_status;
} elseif ($quota_status_error !== null) {
    $response_payload['quota_status_error'] = $quota_status_error;
}
if (isset($merged_config['download_quota_gb'])) {
    $response_payload['download_quota_gb'] = $merged_config['download_quota_gb'];
}

// 7. Retour au Frontend
if ($exit_code !== 0) {
    json_response(array_merge([
        'success' => false,
        'error' => 'Script erreur : ' . $output,
    ], $response_payload));
} else {
    json_response(array_merge([
        'success' => true,
        'message' => 'R??gles appliqu??es',
        'debug' => $output,
    ], $response_payload));
}
?>