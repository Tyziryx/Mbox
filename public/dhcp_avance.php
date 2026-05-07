<?php
// Config DHCP avancée : on choisit manuellement début et fin de plage
// Contrairement a dhcp.php où c'est automatique

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/server_info.php';
require_once __DIR__ . '/../includes/paths.php';

$message = '';
$output = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start = intval($_POST['dhcp_start_last'] ?? 0);
    $end = intval($_POST['dhcp_end_last'] ?? 0);

    if ($start <= $server_octet) {
        $message = "Erreur : La plage doit commencer après l'IP du serveur (.$server_octet).";
    } elseif ($start >= $end || $start < 1 || $end > 254) {
        $message = "Erreur : La plage est invalide.";
    } else {
        $dhcp_start = "$network.$start";
        $dhcp_end = "$network.$end";
        $cmd = "sudo " . escapeshellarg(MBOX_BIN_BASH . "/dhcp_avance.sh") . " " . escapeshellarg($network) . " " . escapeshellarg($dhcp_start) . " " . escapeshellarg($dhcp_end) . " " . escapeshellarg($current_ip) . " 2>&1";
        
        $output = shell_exec($cmd);
        $message = "Config appliquée !";
        
        // Recharger les informations après modification
        $server_info = get_server_info();
        extract($server_info);
    }
}

$page_title = "DHCP Avancé | MBox Admin";
$current_page = "dhcp_avance";
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <div class="container">
        <div class="page-header">
            <div>
                <h1 class="page-title">Configuration DHCP Avancée</h1>
                <p class="text-muted">Mode manuel pour experts réseau.</p>
            </div>
            <a href="dhcp.php" class="btn btn-primary">&larr; Retour Mode Simple</a>
        </div>

        <div class="card border-green">
            <div class="card-title">Définition de la plage IP</div>
            <div class="info-box my-20">
                <p><strong>Réseau :</strong> <?php echo htmlspecialchars($network); ?>.0/24</p>
                <p><strong>Serveur exclu :</strong> <?php echo htmlspecialchars($current_ip); ?></p>
            </div>

            <form method="post">
                <div class="grid-2-24">
                    <div>
                        <label class="input-label mb-8">Début Plage</label>
                        <div class="flex flex-align-center">
                            <span class="input-prefix is-left"><?php echo htmlspecialchars($network); ?>.</span>
                            <input type="number" name="dhcp_start_last" value="<?php echo $server_octet + 1; ?>" min="1" max="254" required class="input-with-prefix">
                        </div>
                    </div>
                    <div>
                        <label class="input-label mb-8">Fin Plage</label>
                        <div class="flex flex-align-center">
                            <span class="input-prefix is-left"><?php echo htmlspecialchars($network); ?>.</span>
                            <input type="number" name="dhcp_end_last" value="<?php echo min(254, $server_octet + 20); ?>" min="1" max="254" required class="input-with-prefix">
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-24">Appliquer la configuration</button>
            </form>
        </div>

        <?php if ($message): ?>
            <div class="card border-green mt-20">
                <h3 class="text-primary-color"><?php echo htmlspecialchars($message); ?></h3>
                <?php if ($output): ?>
                    <pre class="code-box mt-12"><?php echo htmlspecialchars($output); ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

</body>
</html>