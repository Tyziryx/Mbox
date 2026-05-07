<?php
// Speedtest - test de débit (ping, download, upload)
// Les résultats sont enregistrés dans la BDD

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/server_info.php';
require_once __DIR__ . '/../includes/db_connect.php';

$results = null;

// Si le formulaire est soumis
if (isset($_POST['run_test'])) {
    // 1. PING
    $start = microtime(true);
    $fp = @fsockopen("127.0.0.1", 21, $errno, $errstr, 1);
    $ping = round((microtime(true) - $start) * 1000);
    if ($fp) fclose($fp);

    // 2. DOWNLOAD
    $dl_bps = floatval(shell_exec("curl -s -w '%{speed_download}' -o /dev/null ftp://127.0.0.1/testfile_100M.bin"));
    $dl_mbps = round(($dl_bps * 8) / 1000000);

    // 3. UPLOAD
    shell_exec("dd if=/dev/zero of=/tmp/test_up.bin bs=1M count=10 2>/dev/null");
    $up_bps = floatval(shell_exec("curl -s -w '%{speed_upload}' -T /tmp/test_up.bin ftp://127.0.0.1/upload/test_php.bin 2>/dev/null"));
    $up_mbps = round(($up_bps * 8) / 1000000);
    shell_exec("rm /tmp/test_up.bin");

    // Enregistrement en BDD
    try {
        $stmt = $pdo->prepare("INSERT INTO speedtest_logs (ping_ms, download_mbps, upload_mbps) VALUES (?, ?, ?)");
        $stmt->execute([$ping, $dl_mbps, $up_mbps]);
    } catch (Exception $e) {}

    $results = [
        'ping' => $ping,
        'dl' => $dl_mbps,
        'up' => $up_mbps
    ];
}

$page_title = "Speedtest | MBox Admin";
$current_page = "speedtest";
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Test de Débit</h1>
            <div class="mt-12">
                <a href="historique.php" class="btn btn-secondary"><i class="fas fa-history"></i> Voir l'historique</a>
            </div>
        </div>

        <div class="card border-purple max-w-800 p-20 text-center m-auto">
            <?php if (!$results): ?>
                <form method="post">
                    <button type="submit" name="run_test" class="btn btn-primary btn-xl">
                        LANCER LE TEST
                    </button>
                </form>
                <p class="mt-24 text-muted">Mesurez la performance de votre connexion fibre.</p>
            <?php else: ?>
                <div class="dashboard-grid cols-3 mb-32">
                    <div>
                        <div class="card-title">Ping</div>
                        <div class="data-huge"><?php echo $results['ping']; ?></div>
                        <div class="data-sub">ms</div>
                    </div>
                    <div>
                        <div class="card-title">Descendant</div>
                        <div class="data-huge text-purple"><?php echo $results['dl']; ?></div>
                        <div class="data-sub">Mbps</div>
                    </div>
                    <div>
                        <div class="card-title">Montant</div>
                        <div class="data-huge text-green"><?php echo $results['up']; ?></div>
                        <div class="data-sub">Mbps</div>
                    </div>
                </div>
                
                <form method="post">
                    <button type="submit" name="run_test" class="btn btn-primary">
                        <i class="fas fa-redo"></i> Relancer
                    </button>
                </form>
            <?php endif; ?>
        </div>
        
        <?php if (isset($_POST['run_test']) && !$results): ?>
            <div class="card border-orange mt-20">
                <p class="text-danger">Erreur lors du test.</p>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>