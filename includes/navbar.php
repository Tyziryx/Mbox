<?php
// Navbar principale (style sobre, inspiree du mock valide)
$current_page_value = isset($current_page) ? (string)$current_page : '';
$current_script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

$home_scripts = ['index.php', 'speedtest.php', 'historique.php'];
$network_scripts = ['dhcp.php', 'dhcp_avance.php', 'config_dns.php', 'change_ip.php'];
$security_scripts = ['parental.php'];
$apps_scripts = ['forum.php', 'topic.php', 'forum_categories.php', 'mail.php', 'login_mail.php'];

$is_home_group = in_array($current_script, $home_scripts, true) || in_array($current_page_value, ['index', 'speedtest'], true);
$is_network_group = in_array($current_script, $network_scripts, true) || in_array($current_page_value, ['dhcp', 'dhcp_avance', 'dns', 'ip'], true);
$is_security_group = in_array($current_script, $security_scripts, true) || in_array($current_page_value, ['parental'], true);
$is_apps_group = in_array($current_script, $apps_scripts, true) || in_array($current_page_value, ['mail', 'forum'], true);
?>
<!-- NAVBAR -->
<nav class="navbar">
    <div class="nav-left">
        <a href="index.php" class="brand">MBox</a>

        <div class="nav-group-links" aria-label="Navigation principale">
            <a href="index.php" class="nav-group-link <?php echo $is_home_group ? 'active' : ''; ?>">Accueil</a>
            <a
                href="dhcp.php"
                id="nav-group-network"
                data-simple-href="dhcp.php"
                data-expert-href="dhcp_avance.php"
                class="nav-group-link <?php echo $is_network_group ? 'active' : ''; ?>"
            >Reseau</a>
            <a href="parental.php" class="nav-group-link <?php echo $is_security_group ? 'active' : ''; ?>">Securite</a>
            <a href="forum.php" class="nav-group-link <?php echo $is_apps_group ? 'active' : ''; ?>">Apps</a>
        </div>

        <div class="nav-context-links" aria-label="Liens rapides de section">
            <?php if ($is_network_group): ?>
                <a href="dhcp.php" class="nav-context-link simple-only <?php echo $current_script === 'dhcp.php' ? 'active' : ''; ?>">DHCP</a>
                <a href="dhcp_avance.php" class="nav-context-link expert-only <?php echo $current_script === 'dhcp_avance.php' ? 'active' : ''; ?>">DHCP Avance</a>
                <a href="config_dns.php" class="nav-context-link expert-only <?php echo $current_script === 'config_dns.php' ? 'active' : ''; ?>">DNS</a>
                <a href="change_ip.php" class="nav-context-link expert-only <?php echo $current_script === 'change_ip.php' ? 'active' : ''; ?>">IP</a>
            <?php elseif ($is_security_group): ?>
                <a href="parental.php" class="nav-context-link <?php echo $current_script === 'parental.php' ? 'active' : ''; ?>">Controle parental</a>
            <?php elseif ($is_apps_group): ?>
                <a href="forum.php" class="nav-context-link <?php echo in_array($current_script, ['forum.php', 'topic.php'], true) ? 'active' : ''; ?>">Forum</a>
                <a href="login_mail.php" class="nav-context-link <?php echo in_array($current_script, ['login_mail.php', 'mail.php'], true) ? 'active' : ''; ?>">Mail</a>
            <?php else: ?>
                <a href="index.php" class="nav-context-link <?php echo $current_script === 'index.php' ? 'active' : ''; ?>">Dashboard</a>
                <a href="speedtest.php" class="nav-context-link <?php echo in_array($current_script, ['speedtest.php', 'historique.php'], true) ? 'active' : ''; ?>">Speedtest</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="nav-right">
        <div class="expert-switch-container">
            <span class="switch-label">EXPERT</span>
            <label class="switch">
                <input type="checkbox" id="expertToggle">
                <span class="slider"></span>
            </label>
        </div>

        <a href="logout.php" class="logout-btn" title="Deconnexion"><i class="fas fa-power-off"></i></a>
    </div>
</nav>

<!-- SCRIPT EXPERT MODE -->
<script>
    const toggle = document.getElementById('expertToggle');
    const body = document.body;
    const networkGroupLink = document.getElementById('nav-group-network');

    function syncNetworkGroupLinkHref() {
        if (!networkGroupLink) return;
        const isExpert = body.classList.contains('expert-mode');
        networkGroupLink.href = isExpert
            ? (networkGroupLink.dataset.expertHref || 'dhcp_avance.php')
            : (networkGroupLink.dataset.simpleHref || 'dhcp.php');
    }

    if(localStorage.getItem('expertMode') === 'true') {
        toggle.checked = true;
        body.classList.add('expert-mode');
    }
    syncNetworkGroupLinkHref();

    toggle.addEventListener('change', function() {
        if(this.checked) {
            body.classList.add('expert-mode');
            localStorage.setItem('expertMode', 'true');
        } else {
            body.classList.remove('expert-mode');
            localStorage.setItem('expertMode', 'false');
        }
        syncNetworkGroupLinkHref();
    });
</script>
