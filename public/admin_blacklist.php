<?php
$current_page = 'parental';
$page_title = 'Admin Listes DNS (Test) | MBox Admin';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paths.php';

function read_env_value_local($key) {
    $raw = $_ENV[$key] ?? getenv($key);
    if ($raw !== false && $raw !== null && trim((string)$raw) !== '') {
        return trim((string)$raw);
    }

    $envPath = __DIR__ . '/../config/.env';
    if (!is_readable($envPath)) {
        return '';
    }

    $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        list($k, $v) = explode('=', $line, 2);
        if (trim((string)$k) === $key) {
            return trim((string)$v);
        }
    }

    return '';
}

function resolve_list_path_local($name) {
    $etcPath = '/etc/mbox/dns/' . $name;
    if (file_exists($etcPath) || (is_dir('/etc/mbox/dns') && is_writable('/etc/mbox/dns'))) {
        return $etcPath;
    }
    return MBOX_DATA_DIR . DIRECTORY_SEPARATOR . $name;
}

function read_file_safe($path) {
    if (!is_readable($path)) {
        return '';
    }
    $raw = @file_get_contents($path);
    return $raw === false ? '' : (string)$raw;
}

function write_file_safe($path, $content) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    if (!file_exists($path)) {
        @touch($path);
    }

    $normalized = str_replace(["\r\n", "\r"], "\n", (string)$content);
    if ($normalized !== '' && substr($normalized, -1) !== "\n") {
        $normalized .= "\n";
    }

    return @file_put_contents($path, $normalized, LOCK_EX) !== false;
}

function run_normalize_lists($blacklistPath, $knowdomainPath) {
    $script = MBOX_BIN_PYTHON . '/normalize_domain_lists.py';
    if (!is_file($script)) {
        return ['ok' => false, 'message' => 'normalize_domain_lists.py introuvable'];
    }

    $summaryPath = '/etc/mbox/dns/domain_lists_summary.json';
    $cmd = 'python3 ' . escapeshellarg($script)
        . ' --blacklist ' . escapeshellarg($blacklistPath)
        . ' --known ' . escapeshellarg($knowdomainPath)
        . ' --summary ' . escapeshellarg($summaryPath)
        . ' 2>&1';

    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);

    return [
        'ok' => ($code === 0),
        'message' => implode("\n", $out),
        'code' => $code,
    ];
}

function run_sync_rpz() {
    $script = MBOX_BIN_BASH . '/sync_blacklist_rpz.sh';
    if (!is_file($script)) {
        return ['ok' => false, 'message' => 'sync_blacklist_rpz.sh introuvable'];
    }

    $cmd = 'sudo ' . escapeshellarg($script) . ' 2>&1';
    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);

    return [
        'ok' => ($code === 0),
        'message' => implode("\n", $out),
        'code' => $code,
    ];
}

$blacklistPath = resolve_list_path_local('blacklist.txt');
$knowdomainPath = resolve_list_path_local('knowdomain.txt');
$editorSecret = read_env_value_local('BLACKLIST_EDITOR_KEY');
$isSecretProtected = $editorSecret !== '';

$message = '';
$messageType = 'ok';
$normalizeResult = null;
$syncResult = null;

if (isset($_GET['lock']) && $_GET['lock'] === '1') {
    unset($_SESSION['blacklist_editor_unlocked']);
    header('Location: admin_blacklist.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_editor'])) {
    $providedSecret = (string)($_POST['editor_secret'] ?? '');
    if ($isSecretProtected && hash_equals($editorSecret, $providedSecret)) {
        $_SESSION['blacklist_editor_unlocked'] = true;
        header('Location: admin_blacklist.php');
        exit;
    }

    $message = 'Code secret invalide.';
    $messageType = 'error';
}

$canEdit = !$isSecretProtected || (!empty($_SESSION['blacklist_editor_unlocked']) && $_SESSION['blacklist_editor_unlocked'] === true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_lists']) || isset($_POST['save_blacklist']))) {
    if (!$canEdit) {
        $message = 'Acces refuse: deverrouillez la page avec le code secret.';
        $messageType = 'error';
    } else {
        $newBlacklistContent = (string)($_POST['blacklist_text'] ?? '');
        $newKnowdomainContent = (string)($_POST['knowdomain_text'] ?? '');
        $doNormalizeSync = isset($_POST['normalize_sync']) && $_POST['normalize_sync'] === '1';

        $savedBlacklist = write_file_safe($blacklistPath, $newBlacklistContent);
        $savedKnowdomain = write_file_safe($knowdomainPath, $newKnowdomainContent);

        if ($savedBlacklist && $savedKnowdomain) {
            $message = 'Listes DNS sauvegardees.';
            $messageType = 'ok';

            if ($doNormalizeSync) {
                $normalizeResult = run_normalize_lists($blacklistPath, $knowdomainPath);
                $syncResult = run_sync_rpz();

                if (($normalizeResult['ok'] ?? false) && ($syncResult['ok'] ?? false)) {
                    $message = 'Listes DNS sauvegardees + normalisation + sync RPZ OK.';
                } else {
                    $messageType = 'warn';
                    $message = 'Listes DNS sauvegardees, mais normalisation/sync a verifier.';
                }
            }
        } else {
            $errors = [];
            if (!$savedBlacklist) {
                $errors[] = 'blacklist';
            }
            if (!$savedKnowdomain) {
                $errors[] = 'knowdomain';
            }
            $message = 'Echec ecriture fichier(s): ' . implode(', ', $errors) . ' (permissions).';
            $messageType = 'error';
        }
    }
}

$blacklistContent = read_file_safe($blacklistPath);
$knowdomainContent = read_file_safe($knowdomainPath);
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-user-shield text-blue inline-icon"></i>
            Admin Listes DNS (test)
        </h1>
        <a href="parental.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Retour parental</a>
    </div>

    <div class="card border-blue mb-16">
        <div class="card-title">Edition brute des fichiers blacklist + knowdomain</div>
        <div class="admin-info-block">
            <div><strong>Blacklist:</strong> <?php echo htmlspecialchars($blacklistPath); ?></div>
            <div><strong>Knowdomain:</strong> <?php echo htmlspecialchars($knowdomainPath); ?></div>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="card <?php echo $messageType !== 'ok' ? 'border-orange' : 'border-green'; ?> mb-16">
            <div class="fw-700 <?php echo $messageType !== 'ok' ? 'text-danger' : 'text-primary-color'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php if (is_array($normalizeResult)): ?>
                <div class="admin-result"><strong>Normalisation:</strong> <?php echo ($normalizeResult['ok'] ?? false) ? 'OK' : 'KO'; ?></div>
                <pre class="code-box"><?php echo htmlspecialchars((string)($normalizeResult['message'] ?? '')); ?></pre>
            <?php endif; ?>
            <?php if (is_array($syncResult)): ?>
                <div class="admin-result"><strong>Sync RPZ:</strong> <?php echo ($syncResult['ok'] ?? false) ? 'OK' : 'KO'; ?></div>
                <pre class="code-box"><?php echo htmlspecialchars((string)($syncResult['message'] ?? '')); ?></pre>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$canEdit): ?>
        <div class="card border-blue">
            <div class="card-title mb-14">Deverrouillage requis</div>
            <form method="post" class="form-inline-row">
                <input type="password" name="editor_secret" class="input-field max-w-340" placeholder="Code secret" required>
                <button type="submit" name="unlock_editor" value="1" class="btn btn-primary"><i class="fas fa-unlock"></i> Deverrouiller</button>
            </form>
        </div>
    <?php else: ?>
        <div class="card border-blue">
            <form method="post">
                <div class="mb-12 text-12 text-muted">Un domaine par ligne, # pour les commentaires.</div>

                <div class="grid-auto-320">
                    <div>
                        <div class="input-label mb-8">Blacklist</div>
                        <textarea
                            name="blacklist_text"
                            class="textarea-field textarea-code"
                        ><?php echo htmlspecialchars($blacklistContent); ?></textarea>
                    </div>

                    <div>
                        <div class="input-label mb-8">Knowdomain (whitelist)</div>
                        <textarea
                            name="knowdomain_text"
                            class="textarea-field textarea-code"
                        ><?php echo htmlspecialchars($knowdomainContent); ?></textarea>
                    </div>
                </div>

                <div class="admin-save-controls">
                    <div class="admin-checkbox-row">
                        <label class="admin-checkbox-label">
                            <input type="checkbox" name="normalize_sync" value="1" checked>
                            Normaliser la liste + sync RPZ apres sauvegarde
                        </label>
                        <?php if ($isSecretProtected): ?>
                            <a href="admin_blacklist.php?lock=1" class="btn btn-secondary"><i class="fas fa-lock"></i> Verrouiller</a>
                        <?php endif; ?>
                    </div>
                    <button type="submit" name="save_lists" value="1" class="btn btn-primary"><i class="fas fa-save"></i> Sauvegarder</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
