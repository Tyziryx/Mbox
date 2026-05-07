<?php
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$host = preg_replace('/:\d+$/', '', $host);
$host = trim($host, '.');

$reasonsFile = '/etc/mbox/dns/block_reasons.json';
$entry = null;

if (is_readable($reasonsFile)) {
    $json = json_decode((string) file_get_contents($reasonsFile), true);
    if (is_array($json) && isset($json[$host])) {
        $entry = $json[$host];
    }
}

$reason = $entry['reason'] ?? 'blocked';
$matched = $entry['matched'] ?? '';

function append_block_visit_log($host, $reason, $matched) {
    if ($host === '') {
        return;
    }

    $logDir = '/etc/mbox/dns';
    $logFile = $logDir . '/blocked_visits.jsonl';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $line = [
        'ts' => date('c'),
        'host' => $host,
        'reason' => $reason,
        'matched' => $matched,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    @file_put_contents($logFile, json_encode($line, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

append_block_visit_log($host, $reason, $matched);

$title = 'Accès bloqué';
$message = 'Ce site est bloqué par le contrôle parental.';

if ($reason === 'blacklist_exact' || $reason === 'blacklist_fuzzy') {
    $title = 'Site interdit (Blacklist)';
    $message = 'Ce domaine est classé dans la liste des sites interdits.';
} elseif ($reason === 'leet_typosquat_known_domain' || $reason === 'typosquat_known_domain') {
    $title = 'Site suspect (Phishing possible)';
    $message = 'Ce domaine ressemble à un site connu. Vous vouliez peut-être aller sur le site officiel.';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blocage DNS</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dns-blocked-page">
    <div class="dns-blocked-wrap">
        <h1><?= htmlspecialchars($title) ?></h1>
        <p><?= htmlspecialchars($message) ?></p>
        <div class="dns-blocked-box">
            <div><span class="dns-blocked-key">Domaine demandé :</span> <?= htmlspecialchars($host ?: 'inconnu') ?></div>
            <?php if (!empty($matched)): ?>
                <div><span class="dns-blocked-key">Domaine de référence :</span> <?= htmlspecialchars($matched) ?></div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
