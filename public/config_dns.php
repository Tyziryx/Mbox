<?php
// Config DNS - ajout de sous-domaines .ceri.com
// Utilise BIND9 via le script config_dns.sh

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/server_info.php';
require_once __DIR__ . '/../includes/paths.php';

if (isset($_POST['subdomain'])) {
    $subdomain = trim(strtolower($_POST['subdomain'])); // Force minuscules
    $domain = $subdomain . ".ceri.com";

    // Sous-domaines réservés
    $reserved = ['www', 'ftp', 'mail', 'ns', 'admin', 'localhost', 'root'];
    
    // Validation et contraintes
    if (empty($current_ip)) {
        echo "<p class='msg-error'>Erreur: IP eth1 introuvable</p>";
    } else if (in_array($subdomain, $reserved)) {
        echo "<p class='msg-error'>Sous-domaine réservé : " . htmlspecialchars($subdomain) . "</p>";
    } else if (!preg_match('/^[a-z0-9-]+$/', $subdomain)) {
        echo "<p class='msg-error'>Sous-domaine invalide (lettres, chiffres, tirets uniquement)</p>";
    } else if (strlen($subdomain) > 63) {
        echo "<p class='msg-error'>Sous-domaine trop long (max 63 caractères)</p>";
    } else if (substr($subdomain, 0, 1) === '-' || substr($subdomain, -1) === '-') {
        echo "<p class='msg-error'>Sous-domaine invalide (pas de tiret au début/fin)</p>";
    } else {
        $cmd = "sudo " . escapeshellarg(MBOX_BIN_BASH . "/config_dns.sh") . " " . escapeshellarg($domain) . " " . escapeshellarg($current_ip) . " 2>&1";
        $output = shell_exec($cmd);

        echo "<p class='msg-success'>Configuration DNS OK</p>";
        echo "<pre>Domaine: $domain
Serveur: $current_ip
WAN: $wan_ip</pre>";
        echo "<pre>" . htmlspecialchars($output) . "</pre>";

        // Test DNS local
        echo "<h3>Test DNS local:</h3>";
        $test_root = shell_exec("dig @$current_ip $domain +short 2>&1");
        $test_www = shell_exec("dig @$current_ip www.$domain +short 2>&1");
        echo "<pre>dig @$current_ip $domain
" . htmlspecialchars($test_root) . "
dig @$current_ip www.$domain
" . htmlspecialchars($test_www) . "</pre>";

        // Test DNS depuis opérateur
        echo "<h3>Test DNS via opérateur:</h3>";
        $test_from_operator = shell_exec("dig @192.168.50.1 $domain +short 2>&1");
        echo "<pre>dig @192.168.50.1 $domain
" . htmlspecialchars($test_from_operator) . "</pre>";

        // Recharger les informations après modification
        $server_info = get_server_info();
        extract($server_info);
    }
}

$page_title = "Configuration DNS | MBox Admin";
$current_page = "dns";
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <div class="container config-dns-page">
        <div class="page-header">
            <h1 class="page-title">Configuration DNS</h1>
        </div>

        <div class="dashboard-grid cols-2">

            <div class="card border-blue">
                <div class="card-title">Ajouter un Sous-Domaine</div>
                <form method="post">
                    <label class="input-label mb-8">Nom du sous-domaine</label>
                    <div class="flex flex-align-center">
                        <input type="text" name="subdomain" placeholder="Ex: rachid" required pattern="[a-z0-9-]+" class="input-with-suffix">
                        <span class="input-prefix is-right">.ceri.com</span>
                    </div>
                    <small class="text-muted mt-8 d-block">Minuscules, chiffres et tirets uniquement.</small>
                    <button type="submit" class="btn btn-primary mt-16">Configurer</button>
                </form>
            </div>

            <div class="card border-blue">
                <div class="card-title">État DNS</div>
                <div class="mt-12">
                    <div class="mb-12">
                        Domaine Actif: <strong><?php echo htmlspecialchars($dns_domain ?: "Aucun"); ?></strong>
                    </div>
                    <div class="code-box">
                        <?php echo htmlspecialchars($dns_records ?: "Aucun enregistrement"); ?>
                    </div>
                </div>
            </div>

        </div>

        <?php if (isset($_POST['subdomain'])): ?>
            <div class="card border-blue mt-24">
                <h3>Résultat de configuration</h3>
                <pre class="code-box mt-12"><?php echo htmlspecialchars($output); ?></pre>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>
