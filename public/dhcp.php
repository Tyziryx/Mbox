<?php
// Config DHCP simple : on entre juste le nombre de machines
// Le script calcule automatiquement la plage IP

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/server_info.php';
require_once __DIR__ . '/../includes/paths.php';

if (isset($_POST['nb_machines'])) {
    $nb_machines = intval($_POST['nb_machines']);

    if (empty($current_ip)) {
        echo "<p class='msg-error'>Erreur: IP eth1 introuvable</p>";
    } else {
        if ($nb_machines < 1 || $nb_machines > $max_machines) {
            echo "<p class='msg-error'>Nombre invalide (1-$max_machines)</p>";
        } else {
            // Calcul de la plage : début juste après l'IP du serveur
            // Ex: si serveur = .10 → plage = .11 à .30 (pour 20 machines)
            $start = $server_octet + 1;
            $end = $start + $nb_machines - 1;

            // Sécurité : pas au-delà de .254 (.255 = broadcast)
            if ($end > 254) {
                $end = 254;
                $nb_machines = $end - $start + 1;
            }

            $dhcp_start = "$network.$start";
            $dhcp_end = "$network.$end";

            // Appel du script shell pour config isc-dhcp-server
            $cmd = "sudo " . escapeshellarg(MBOX_BIN_BASH . "/dhcp.sh") . " " . escapeshellarg($network) . " " . escapeshellarg($dhcp_start) . " " . escapeshellarg($dhcp_end) . " " . escapeshellarg($current_ip) . " 2>&1";
            $output = shell_exec($cmd);

            echo "<p class='msg-success'>Configuration OK</p>";
            echo "<pre>Reseau: $network.0/24
Plage: $dhcp_start - $dhcp_end
Serveur: $current_ip
Machines: $nb_machines</pre>";
            echo "<pre>" . htmlspecialchars($output) . "</pre>";

            // Recharger les informations après modification
            $server_info = get_server_info();
            extract($server_info);
        }
    }
}

$page_title = "DHCP | MBox Admin";
$current_page = "dhcp";
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Configuration DHCP</h1>
            <p class="text-muted">Gérez l'attribution automatique des adresses IP.</p>
        </div>

        <div class="dashboard-grid cols-2">
            
            <div class="card border-green">
                <div class="card-title">Configuration Simplifiée</div>
                <form method="post" class="mt-20">
                    <label class="input-label mb-8">Combien d'appareils souhaitez-vous connecter ?</label>
                    <div class="flex-gap-10">
                        <input type="number" name="nb_machines" min="1" max="<?php echo $max_machines; ?>" placeholder="Ex: 20" required class="input-field flex-grow-1">
                        <button type="submit" class="btn btn-primary">Valider</button>
                    </div>
                    <small class="text-muted d-block mt-8">Maximum possible : <?php echo htmlspecialchars($max_machines); ?></small>
                </form>
            </div>

            <div class="card border-green">
                <div class="card-title">État Actuel</div>
                <div class="mt-20">
                    <div class="flex-between mb-12 border-bottom">
                        <span>Serveur (Gateway)</span>
                        <strong><?php echo htmlspecialchars($current_ip ?: "N/A"); ?></strong>
                    </div>
                    <div class="flex-between mb-12 border-bottom">
                        <span>Réseau</span>
                        <strong><?php echo htmlspecialchars($network); ?>.0/24</strong>
                    </div>
                    <div class="flex-between">
                        <span>Plage Active</span>
                        <span class="badge <?php echo $dhcp_range ? 'badge-ok' : 'badge-warn'; ?>">
                            <?php echo htmlspecialchars($dhcp_range ?: "Inactif"); ?>
                        </span>
                    </div>
                </div>
            </div>

        </div>
        
        <?php if (isset($_POST['nb_machines'])): ?>
        <div class="card border-green code-box mt-24 p-20 no-top-border">
            <pre><?php echo htmlspecialchars($output); ?></pre>
        </div>
        <?php endif; ?>

    </div>

</body>
</html>