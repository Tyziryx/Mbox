<?php
// Page d'accueil - dashboard avec les infos principales de la box

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/server_info.php';
require_once __DIR__ . '/../includes/db_connect.php';

// Récupération du dernier speedtest en BDD
$last_dl = 0;
$last_up = 0;
try {
    $stmt = $pdo->query("SELECT download_mbps, upload_mbps FROM speedtest_logs ORDER BY date DESC LIMIT 1");
    $res = $stmt->fetch();
    if($res) {
        $last_dl = round($res['download_mbps']);
        $last_up = round($res['upload_mbps']);
    }
} catch (Exception $e) {}

// Récupération du dernier ticket support
$last_topic_title = "Aucun ticket en cours";
try {
    $stmt = $pdo->query("SELECT title FROM forum_topics ORDER BY created_at DESC LIMIT 1");
    $res = $stmt->fetch();
    if($res) $last_topic_title = $res['title'];
} catch (Exception $e) {}

// Appareils connectés - vrais (DHCP) en priorité, fictifs en complément, max 4
$devices_fictifs = [
    ['name' => 'iPhone d\'Alex', 'type' => 'fas fa-mobile-alt', 'detail' => '5 GHz', 'signal' => 4],
    ['name' => 'iPad Salon',     'type' => 'fas fa-tablet-alt', 'detail' => '2.4 GHz', 'signal' => 2],
    ['name' => 'TV Samsung',     'type' => 'fas fa-tv',         'detail' => 'Ethernet', 'signal' => 5]
];
$devices = array_slice(array_merge($dhcp_devices_online, $devices_fictifs), 0, 4);
$wifi_count = count($devices);
$phone_number = "04 90 84 00 00";

// Formatage des vitesses pour l'affichage (Mb/s ou Gb/s selon la valeur)
$display_dl = $last_dl > 1000 ? round($last_dl / 1000, 1) . ' Gb/s' : $last_dl . ' Mb/s';
$display_up = $last_up > 1000 ? round($last_up / 1000, 1) . ' Gb/s' : $last_up . ' Mb/s';

// Calcul des pourcentages pour les jauges circulaires (max = 1 Gbps)
$max_speed = 1000;
$percent_dl = min(100, ($last_dl / $max_speed) * 100);
$percent_up = min(100, ($last_up / $max_speed) * 100);

// Config pour header.php et navbar.php
$page_title = "Dashboard | MBox Admin";
$current_page = "index";
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <!-- DASHBOARD CONTENT -->
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Accueil</h1>
        </div>

        <div class="dashboard-grid">
            
            <!-- LIGNE 1 -->

            <!-- 1. INTERNET (WAN) - BLEU -->
            <div class="card border-blue">
                <div class="card-header">
                    <div class="card-title">Internet</div>
                    <div class="card-icon"><i class="fas fa-globe"></i></div>
                </div>
                <div class="data-huge"><?php echo htmlspecialchars($wan_ip ?: "---.---.---.---"); ?></div>
                <div class="data-sub mb-12">IP Publique (WAN)</div>
                <span class="badge <?php echo $wan_ip ? 'badge-ok' : 'badge-warn'; ?>">
                    <?php echo $wan_ip ? 'CONNECTÉ' : 'DÉCONNECTÉ'; ?>
                </span>
            </div>

            <!-- 2. WI-FI (LAN) - CYAN (LISTE APPAREILS) -->
            <div class="card border-cyan">
                <div class="card-header">
                    <div class="card-title">Appareils Connectés (<?php echo $wifi_count; ?>)</div>
                    <div class="card-icon"><i class="fas fa-wifi"></i></div>
                </div>
                
                <div class="device-list">
                    <?php foreach($devices as $dev): ?>
                    <div class="device-item">
                        <div class="device-left">
                            <div class="device-icon-box"><i class="<?php echo $dev['type']; ?>"></i></div>
                            <div class="device-info">
                                <span class="device-name"><?php echo $dev['name']; ?></span>
                                <span class="device-type"><?php echo $dev['detail']; ?></span>
                            </div>
                        </div>
                        
                        <?php if($dev['signal'] == 5): ?>
                            <!-- Icone Câble Ethernet -->
                            <div class="eth-icon" title="Câblé Ethernet"><i class="fas fa-ethernet"></i></div>
                        <?php else: ?>
                            <!-- Barres Wi-Fi si sans fil -->
                            <div class="wifi-signal" title="Signal: <?php echo $dev['signal']; ?>/4">
                                <div class="wifi-bar bar-1 <?php echo $dev['signal'] >= 1 ? 'active' : ''; ?>"></div>
                                <div class="wifi-bar bar-2 <?php echo $dev['signal'] >= 2 ? 'active' : ''; ?>"></div>
                                <div class="wifi-bar bar-3 <?php echo $dev['signal'] >= 3 ? 'active' : ''; ?>"></div>
                                <div class="wifi-bar bar-4 <?php echo $dev['signal'] >= 4 ? 'active' : ''; ?>"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 3. SPEEDTEST - VIOLET -->
            <div class="card border-purple">
                <div class="card-header">
                    <div class="card-title">Débit Internet</div>
                    <div class="card-icon"><i class="fas fa-tachometer-alt"></i></div>
                </div>

                <div class="flex-gap-20 flex-justify-around mt-20">

                    <!-- Download -->
                    <div class="text-center flex-grow-1">
                        <div class="speed-circle speed-circle--dl" data-percent="<?php echo $percent_dl; ?>">
                            <div class="speed-inner">
                                <div class="speed-value"><?php echo $display_dl; ?></div>
                                <div class="speed-label">Download</div>
                            </div>
                        </div>
                        <div class="text-11 text-muted mt-4">Téléchargement</div>
                    </div>

                    <!-- Upload -->
                    <div class="text-center flex-grow-1">
                        <div class="speed-circle speed-circle--up" data-percent="<?php echo $percent_up; ?>">
                            <div class="speed-inner">
                                <div class="speed-value"><?php echo $display_up; ?></div>
                                <div class="speed-label">Upload</div>
                            </div>
                        </div>
                        <div class="text-11 text-muted mt-4">Envoi</div>
                    </div>

                </div>
            </div>

            <!-- LIGNE 2 -->

            <!-- 4. TÉLÉPHONIE - ORANGE -->
            <div class="card border-orange">
                <div class="card-header">
                    <div class="card-title">Téléphonie</div>
                    <div class="card-icon"><i class="fas fa-phone-alt"></i></div>
                </div>
                <div class="data-huge text-22"><?php echo $phone_number; ?></div>
                <div class="data-sub mb-12">Ligne Fixe</div>
                <div class="flex-between">
                    <span class="badge badge-ok">LIGNE ACTIVE</span>
                    <span class="text-12 text-muted">0 Msg vocal</span>
                </div>
            </div>

            <!-- 5. SANTÉ SYSTÈME - VERT -->
            <div class="card border-green">
                <div class="card-header">
                    <div class="card-title">Santé Système</div>
                    <div class="card-icon"><i class="fas fa-server"></i></div>
                </div>
                <ul class="health-list">
                    <li class="health-item">
                        <span>Serveur DHCP</span>
                        <div class="led <?php echo $dhcp_range ? 'on' : ''; ?>"></div>
                    </li>
                    <li class="health-item">
                        <span>Service DNS</span>
                        <div class="led <?php echo (!empty($dns_service_up) || !empty($dns_domain)) ? 'on' : ''; ?>"></div>
                    </li>
                    <li class="health-item">
                        <span>Base de données</span>
                        <div class="led on"></div>
                    </li>
                </ul>
            </div>

            <!-- 6. SUPPORT - JAUNE -->
            <div class="card border-yellow">
                <div class="card-header">
                    <div class="card-title">Support</div>
                    <div class="card-icon"><i class="fas fa-comments"></i></div>
                </div>
                <div class="data-sub mb-8">Derniers messages</div>
                <div class="forum-bubble">
                    <?php echo htmlspecialchars($last_topic_title); ?>
                </div>
                <a href="forum.php" class="btn btn-primary">Acceder au forum</a>
            </div>

        </div>
    </div>

    <script src="assets/js/index_speed.js"></script>
</body>
</html>
