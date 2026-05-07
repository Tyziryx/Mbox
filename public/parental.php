<?php
$current_page = 'parental';
$page_title = 'Contrôle Parental — MBox Admin';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/server_info.php';

function dhcp_to_parental(array $d, int $idx): array {
    $icon = $d['type'];
    $name = strtolower((string)($d['name'] ?? ''));
    $type = 'other';
    if (strpos($icon, 'laptop') !== false)     $type = 'laptop';
    elseif (strpos($icon, 'mobile') !== false || strpos($icon, 'phone') !== false) $type = 'phone';
    elseif (strpos($icon, 'tablet') !== false) $type = 'tablet';
    elseif (strpos($icon, 'tv') !== false)     $type = 'tv';
    elseif (strpos($icon, 'server') !== false) $type = 'other';

    // Priorité au nom appareil pour éviter les mauvaises icônes DHCP.
    if (preg_match('/iphone|telephone|smartphone|android|galaxy|pixel|bob|theo/', $name)) {
        $type = 'phone';
    } elseif (preg_match('/ipad|tablet|tablette/', $name)) {
        $type = 'tablet';
    } elseif ($type === 'other' && preg_match('/pc|laptop|macbook|notebook/', $name)) {
        $type = 'laptop';
    }

    return ['id' => 'real_'.$idx, 'name' => $d['name'], 'mac' => $d['mac'] ?? '', 'deviceType' => $type, 'mode' => 'child', 'real' => true, 'online' => $d['online'] ?? false];
}

$initial_devices = [];
foreach ($dhcp_devices as $i => $d) {
    $initial_devices[] = dhcp_to_parental($d, $i);
}
$demo_devices = [
    ['id' => 'demo_0', 'name' => 'iPad Salon',    'mac' => '00:11:22:33:44:55', 'deviceType' => 'tablet',  'mode' => 'child', 'real' => false, 'online' => false],
    ['id' => 'demo_1', 'name' => 'iPhone-Theo',   'mac' => 'AA:BB:CC:DD:EE:FF', 'deviceType' => 'phone',   'mode' => 'teen',  'real' => false, 'online' => false],
    ['id' => 'demo_2', 'name' => 'PC-Hugo',       'mac' => '11:22:33:44:55:66', 'deviceType' => 'laptop',  'mode' => 'child', 'real' => false, 'online' => false],
];
$real_names = array_column($initial_devices, 'name');
foreach ($demo_devices as $dd) {
    if (!in_array($dd['name'], $real_names)) $initial_devices[] = $dd;
}
$js_devices = json_encode($initial_devices);
if ($js_devices === false) {
    $js_devices = '[]';
}

// Chargement des configs persistées
$config_path = '/etc/mbox/parental_config.json';
$saved_configs = [];
if (file_exists($config_path) && is_readable($config_path)) {
    $saved_configs = json_decode(file_get_contents($config_path), true) ?: [];
}
$js_saved = json_encode($saved_configs);
if ($js_saved === false) {
    $js_saved = '{}';
}

$script_dir_url = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/.');
$api_domain_lists_url = ($script_dir_url === '' ? '' : $script_dir_url) . '/api_domain_lists.php';
$api_parental_url = ($script_dir_url === '' ? '' : $script_dir_url) . '/api_parental.php';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="pc-container">

    <!-- Page header -->
    <div class="page-header flex-wrap flex-gap-12">
        <h1 class="page-title">Contrôle Parental</h1>


    </div>

    <!-- CARD 1 : Appareils -->
    <div class="card pc-card-accent-left pc-card-accent-blue mb-24">
        <div class="card-header">
            <div class="pc-card-title">
                <i class="fas fa-laptop text-blue"></i>
                <h2>Appareils</h2>
            </div>
        </div>
        <div class="pc-devices-grid" id="pc-device-list"></div>
    </div>

    <!-- CARD 2 : Planning -->
    <div class="card pc-card-accent-left pc-card-accent-purple mb-24">
        <div class="card-header flex-align-start">
            <div>
                <div class="pc-card-title mb-4">
                    <i class="fas fa-clock text-purple"></i>
                    <h2>Plages horaires</h2>
                </div>
                <div class="pc-sched-subinfo">
                    Appareil : <strong id="pc-sched-device-name" class="text-purple">—</strong>
                    &nbsp;·&nbsp;
                    Mode : <strong id="pc-sched-device-mode" class="text-purple">—</strong>
                </div>
            </div>
        </div>
        <div class="pc-sched-box">
            <div class="pc-sched-wrap">
                <div class="pc-sched-inner" id="pc-schedule"></div>
            </div>
            <div class="pc-sched-footer">
                <button class="pc-btn pc-btn-blue" onclick="saveSchedule()">
                    <i class="fas fa-save"></i> Sauvegarder
                </button>
                <div class="pc-legend">
                    <div class="flex-gap-6 flex-align-center">
                        <div class="pc-legend-dot pc-legend-dot-allow"></div>
                        <span>Autorisé</span>
                    </div>
                    <div class="flex-gap-6 flex-align-center">
                        <div class="pc-legend-dot pc-legend-dot-block"></div>
                        <span>Bloqué</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- CARD 3 : Historique -->
    <div class="card pc-card-accent-left pc-card-accent-danger mb-24">
        <div class="card-header">
            <div class="pc-card-title">
                <i class="fas fa-clock-rotate-left text-danger"></i>
                <h2>Historique des blocages</h2>
            </div>
            <div class="flex-gap-8 flex-align-center">
                <button class="pc-btn pc-btn-ghost" onclick="loadBlockedHistory()">
                    <i class="fas fa-rotate"></i> Rafraichir
                </button>
                <button class="pc-collapse-btn" id="hist-toggle" onclick="toggleHistory()" title="Réduire/Développer">
                    <i class="fas fa-chevron-up" id="hist-chevron"></i>
                </button>
            </div>
        </div>
        <div id="hist-body">
            <table class="hist-table">
                <thead>
                    <tr>
                        <th>Date/Heure</th>
                        <th>Appareil</th>
                        <th>Domaine bloqué</th>
                        <th>Raison</th>
                    </tr>
                </thead>
                <tbody id="blocked-history-body">
                    <tr><td colspan="4" class="list-empty">Chargement...</td></tr>
                </tbody>
            </table>
            <div class="text-right mt-14">
                <a href="blocked_history.php" class="btn btn-primary">Voir tout l'historique</a>
            </div>
        </div>
    </div>

</div>

<!-- MODAL FILTRES -->
<div class="pc-modal-overlay is-hidden" id="pc-modal" onclick="closeFiltersOutside(event)">
    <div class="pc-modal">
        <div class="pc-modal-head">
            <div class="pc-modal-head-left">
                <div class="pc-modal-device-icon"><i class="fas fa-laptop" id="pc-modal-device-icon-i"></i></div>
                <div class="pc-modal-device-info">
                    <h3 id="modal-title">Appareil</h3>
                    <div class="pc-modal-head-sub">
                        <span class="dot"></span>
                        <span id="pc-modal-head-status">Connecté</span>
                    </div>
                </div>
            </div>
            <button class="pc-modal-close" onclick="closeFilters()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="pc-modal-body pc-modal-body-split">
            <aside class="pc-modal-sidebar">
                <button class="pc-nav-btn active" id="pc-nav-filters" onclick="switchFilterPanel('filters')">
                    <i class="fas fa-shield-alt"></i>
                    <span>Filtres Web</span>
                </button>
                <button class="pc-nav-btn" id="pc-nav-quota" onclick="switchFilterPanel('quota')">
                    <i class="fas fa-gauge-high"></i>
                    <span>Quota journalier</span>
                </button>

                <div class="pc-identity-card">
                    <div class="pc-identity-title">Infos appareil</div>
                    <div class="pc-identity-row">
                        <span class="pc-identity-key">MAC</span>
                        <span class="pc-identity-value" id="pc-identity-mac">—</span>
                    </div>
                    <div class="pc-identity-row">
                        <span class="pc-identity-key">Type</span>
                        <span class="pc-identity-value" id="pc-identity-type">—</span>
                    </div>
                </div>
            </aside>

            <div class="pc-modal-content">
                <section class="pc-panel active" id="pc-panel-filters">
                    <div>
                        <div class="pc-section-label">Profil de filtrage</div>
                        <div class="pc-mode-list">
                            <div class="pc-mode-option" id="mode-child" onclick="setMode('child')">
                                <div class="pc-mode-icon"><i class="fas fa-child"></i></div>
                                <div class="pc-mode-label">Enfant</div>
                            </div>
                            <div class="pc-mode-option" id="mode-teen" onclick="setMode('teen')">
                                <div class="pc-mode-icon"><i class="fas fa-user-graduate"></i></div>
                                <div class="pc-mode-label">Ado</div>
                            </div>
                            <div class="pc-mode-option" id="mode-adult" onclick="setMode('adult')">
                                <div class="pc-mode-icon"><i class="fas fa-user-shield"></i></div>
                                <div class="pc-mode-label">Parent</div>
                            </div>
                        </div>
                    </div>

                    <div class="pc-filter-grid">
                        <div>
                            <div class="pc-section-label-row">
                                <i class="fas fa-sliders-h"></i>
                                <div class="pc-section-label">Catégories bloquées</div>
                            </div>
                            <div class="pc-filter-rows">
                                <div class="pc-filter-row">
                                    <div class="pc-filter-label"><span class="pc-filter-icon"><i class="fas fa-ban"></i></span> Contenu adulte</div>
                                    <label class="pc-switch"><input type="checkbox" id="filter-adult" onchange="toggleFilter('adult',this.checked)"><span class="pc-slider"></span></label>
                                </div>
                                <div class="pc-filter-row">
                                    <div class="pc-filter-label"><span class="pc-filter-icon"><i class="fas fa-gamepad"></i></span> Jeux en ligne</div>
                                    <label class="pc-switch"><input type="checkbox" id="filter-games" onchange="toggleFilter('games',this.checked)"><span class="pc-slider"></span></label>
                                </div>
                                <div class="pc-filter-row">
                                    <div class="pc-filter-label"><span class="pc-filter-icon"><i class="fas fa-tv"></i></span> Streaming vidéo</div>
                                    <label class="pc-switch"><input type="checkbox" id="filter-streaming" onchange="toggleFilter('streaming',this.checked)"><span class="pc-slider"></span></label>
                                </div>
                                <div class="pc-filter-row">
                                    <div class="pc-filter-label"><span class="pc-filter-icon"><i class="fas fa-hashtag"></i></span> Réseaux sociaux</div>
                                    <label class="pc-switch"><input type="checkbox" id="filter-social" onchange="toggleFilter('social',this.checked)"><span class="pc-slider"></span></label>
                                </div>
                                <div class="pc-filter-row">
                                    <div class="pc-filter-label"><span class="pc-filter-icon"><i class="fas fa-shield-virus"></i></span> Publicités et trackers</div>
                                    <label class="pc-switch"><input type="checkbox" id="filter-ads" onchange="toggleFilter('ads',this.checked)"><span class="pc-slider"></span></label>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div class="pc-section-label-row">
                                <i class="fas fa-chart-pie"></i>
                                <div class="pc-section-label">Aide catégories</div>
                            </div>
                            <div class="pc-activity-card">
                                <div class="pc-activity-head">
                                    <span>Blocages observés </span>
                                    <i class="fas fa-shield-virus"></i>
                                </div>
                                <div class="pc-activity-rows" id="pc-category-help-body">
                                    <div class="pc-activity-row pc-activity-row-empty">Chargement…</div>
                                </div>
                            </div>
                            <div class="pc-stats-updated">
                                MAJ : <strong id="pc-stats-updated-at">—</strong>
                            </div>
                            <div class="mt-12">
                                <a href="https://192.168.1.174/public/forum.php?cat=2" id="pc-forum-link" class="pc-btn pc-btn-blue btn-full">
                                    <i class="fas fa-comments"></i> Aide forum
                                </a>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="pc-panel" id="pc-panel-quota">
                    <div class="pc-quota-panel">
                        <div class="pc-quota-intro">
                            <div class="pc-quota-intro-title">Quota journalier</div>
                            <div class="pc-quota-intro-sub">Suivi de la consommation et de la limite quotidienne pour <strong id="pc-quota-device-name" class="text-primary-color">—</strong>.</div>
                        </div>

                        <div class="pc-quota-live" id="pc-quota-live">
                            <div class="pc-quota-live-row">
                                <div class="pc-quota-live-block">
                                    <div class="pc-quota-os-label">Utilisation aujourd'hui</div>
                                    <div class="pc-quota-os-used">
                                        <span class="pc-quota-os-used-num" id="pc-quota-used-num">0</span>
                                        <span class="pc-quota-os-used-unit">Go</span>
                                    </div>
                                </div>
                                <div class="pc-quota-live-block right">
                                    <div class="pc-quota-os-label">Limite fixée</div>
                                    <div class="pc-quota-os-limit" id="pc-quota-current">0 Go/jour</div>
                                </div>
                            </div>

                            <div>
                                <div class="pc-quota-live-bar" aria-hidden="true">
                                    <div class="pc-quota-live-bar-fill" id="pc-quota-live-bar-fill"></div>
                                </div>
                                <div class="pc-quota-live-scale mt-6">
                                    <span>0 Go</span>
                                    <span id="pc-quota-live-limit-val">—</span>
                                </div>
                            </div>

                            <div class="pc-quota-live-foot">
                                <span id="pc-quota-live-reset">Reset: —</span>
                                <span id="pc-quota-live-state">État: en attente</span>
                            </div>

                            <span id="pc-quota-used" class="sr-only">0 Go</span>
                            <span id="pc-quota-remaining" class="sr-only">0 Go</span>
                        </div>

                        <div class="pc-quota-presets">
                            <button class="pc-quota-preset" type="button" onclick="setQuotaPreset(1)">1 Go</button>
                            <button class="pc-quota-preset" type="button" onclick="setQuotaPreset(2)">2 Go</button>
                            <button class="pc-quota-preset" type="button" onclick="setQuotaPreset(5)">5 Go</button>
                            <button class="pc-quota-preset" type="button" onclick="setQuotaPreset(10)">10 Go</button>
                            <button class="pc-quota-preset" type="button" onclick="setQuotaPreset(0)">Illimité</button>
                        </div>

                        <div class="pc-quota-controls">
                            <label for="pc-quota-input">Valeur manuelle (Go/jour)</label>
                            <div class="pc-quota-actions">
                                <input id="pc-quota-input" type="number" min="0" step="0.1" value="0" placeholder="Ex: 2.5" class="pc-quota-input" oninput="onQuotaInputChange()">
                                <button class="pc-quota-save-btn" id="pc-quota-save-btn" onclick="saveQuota()">Passer en illimité</button>
                            </div>
                            <div class="pc-quota-helper">0 = pas de limite pour cet appareil.</div>
                            <div class="pc-save-progress" id="pc-save-progress" aria-hidden="true">
                                <div class="pc-save-progress-bar"></div>
                            </div>
                            <div class="pc-quota-status" id="pc-quota-status"></div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
        <div class="pc-modal-foot">
            <button class="pc-btn pc-btn-purple" onclick="finishFilters()">Terminer</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="pc-toast" id="pc-toast">Planning sauvegardé ✓</div>

<script>
const devices = <?php echo $js_devices; ?>;
const savedConfigs = <?php echo $js_saved; ?>;
window.MBOX_API_DOMAIN_LISTS_URL = <?php echo json_encode($api_domain_lists_url); ?>;
window.MBOX_API_PARENTAL_URL = <?php echo json_encode($api_parental_url); ?>;
</script>
<script src="assets/js/parental.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/parental.js') ?: time(); ?>"></script>
</body>
</html>