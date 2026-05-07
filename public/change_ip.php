<?php
// Changement d'IP pour eth0 (WAN) et eth1 (LAN)
// Appelle change_ip.sh pour modifier /etc/network/interfaces

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/server_info.php';
require_once __DIR__ . '/../includes/paths.php';

if (isset($_POST['interface']) && isset($_POST['ip_octet'])) {
    $interface = $_POST['interface'];
    $ip_octet = intval($_POST['ip_octet']);
    
    // Déterminer le réseau selon l'interface
    $base_network = ($interface === 'eth0') ? '192.168.50' : $network;
    $new_ip = $base_network . '.' . $ip_octet;
    
    // Validation
    if ($ip_octet < 1 || $ip_octet > 254) {
        echo "<p class='msg-error'>Octet invalide (1-254)</p>";
    } else if (!filter_var($new_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        echo "<p class='msg-error'>Format IP invalide</p>";
    } else {
        $current = ($interface === 'eth0') ? $wan_ip : $lan_ip;

        if (empty($current)) {
            echo "<p class='msg-error'>Erreur: IP $interface introuvable</p>";
        } else if ($new_ip === $current) {
            echo "<p class='msg-warn'>IP déjà configurée</p>";
        } else {
            $dns_arg = empty($dns_domain) ? "no-domain" : $dns_domain;

            $cmd = "sudo " . escapeshellarg(MBOX_BIN_BASH . "/change_ip.sh") . " " . escapeshellarg($interface) . " " . escapeshellarg($new_ip) . " " . escapeshellarg($dns_arg) . " 2>&1";
            $output = shell_exec($cmd);

            echo "<p class='msg-success'>IP $interface changée: $current → $new_ip</p>";
            echo "<pre>" . htmlspecialchars($output) . "</pre>";
            
            // Recharger les informations après modification
            $server_info = get_server_info();
            extract($server_info);
        }
    }
}

$page_title = "Configuration IP | MBox Admin";
$current_page = "ip";
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Configuration IP Interfaces</h1>
        </div>

        <div class="dashboard-grid cols-2">
            
            <!-- WAN -->
            <div class="card border-blue">
                <div class="card-header">
                    <div class="card-icon"><i class="fas fa-globe"></i></div>
                    <span class="badge badge-ok">WAN (eth0)</span>
                </div>
                <p class="text-muted mb-16">Lien vers l'opérateur (Internet).</p>
                <div class="info-box mb-16">
                    IP Actuelle: <strong><?php echo htmlspecialchars($wan_ip ?: "Non détectée"); ?></strong>
                </div>
                <form method="post">
                    <input type="hidden" name="interface" value="eth0">
                    <div class="flex flex-align-center">
                        <span class="input-prefix is-left">192.168.50.</span>
                        <input type="number" name="ip_octet" min="1" max="254" value="<?php echo $wan_octet ?: 10; ?>" required class="input-with-prefix">
                    </div>
                    <button type="submit" class="btn btn-primary mt-16">Mettre à jour WAN</button>
                </form>
            </div>

            <!-- LAN -->
            <div class="card border-blue">
                <div class="card-header">
                    <div class="card-icon"><i class="fas fa-network-wired"></i></div>
                    <span class="badge badge-ok">LAN (eth1)</span>
                </div>
                <p class="text-muted mb-16">Réseau local (Clients).</p>
                <div class="info-box mb-16">
                    IP Actuelle: <strong><?php echo htmlspecialchars($lan_ip ?: "Non détectée"); ?></strong>
                </div>
                <form method="post">
                    <input type="hidden" name="interface" value="eth1">
                    <div class="flex flex-align-center">
                        <span class="input-prefix is-left"><?php echo htmlspecialchars($network); ?>.</span>
                        <input type="number" name="ip_octet" min="1" max="254" value="<?php echo $server_octet; ?>" required class="input-with-prefix">
                    </div>
                    <button type="submit" class="btn btn-primary mt-16">Mettre à jour LAN</button>
                </form>
            </div>
        </div>

        <?php if (isset($_POST['interface'])): ?>
            <div class="card border-blue mt-24">
                <h3>Résultat</h3>
                <pre class="code-box mt-12"><?php echo htmlspecialchars($output); ?></pre>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>