<?php
// Historique des speedtest avec graphique
// Compare 2 dates et affiche les moyennes + graphique Chart.js

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

// RÉCUPÉRER LES DATES DISPONIBLES
$stmt_dates = $pdo->query("SELECT DISTINCT DATE(date) as jour FROM speedtest_logs ORDER BY jour DESC");
$available_dates = $stmt_dates->fetchAll(PDO::FETCH_COLUMN);

// Si aucune donnée
if (empty($available_dates)) {
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d');
} else {
    // Par défaut : du plus vieux au plus récent
    $start_date = $_GET['start'] ?? end($available_dates);
    $end_date = $_GET['end'] ?? $available_dates[0];
}

// --- 2. RÉCUPÉRER LA MOYENNE POUR LA DATE 1 ---
$sql_date1 = "SELECT
                AVG(download_mbps) as avg_dl,
                AVG(upload_mbps) as avg_up,
                AVG(ping_ms) as avg_ping,
                COUNT(*) as nb_tests
              FROM speedtest_logs
              WHERE DATE(date) = :date1";
$stmt1 = $pdo->prepare($sql_date1);
$stmt1->execute(['date1' => $start_date]);
$date1_stats = $stmt1->fetch();

// --- 3. RÉCUPÉRER LA MOYENNE POUR LA DATE 2 ---
$sql_date2 = "SELECT
                AVG(download_mbps) as avg_dl,
                AVG(upload_mbps) as avg_up,
                AVG(ping_ms) as avg_ping,
                COUNT(*) as nb_tests
              FROM speedtest_logs
              WHERE DATE(date) = :date2";
$stmt2 = $pdo->prepare($sql_date2);
$stmt2->execute(['date2' => $end_date]);
$date2_stats = $stmt2->fetch();

// --- 4. TOUS LES LOGS POUR LE TABLEAU ET GRAPHIQUE ---
$sql = "SELECT * FROM speedtest_logs
        WHERE DATE(date) BETWEEN :start AND :end
        ORDER BY date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute(['start' => $start_date, 'end' => $end_date]);
$all_logs = $stmt->fetchAll();

// Pour le tableau (plus récent en haut)
$table_logs = array_reverse($all_logs);

// Pour le graphique (chronologique)
$labels = [];
$dl_data = [];
$up_data = [];
foreach ($all_logs as $log) {
    $labels[] = date('d/m H:i', strtotime($log['date']));
    $dl_data[] = round($log['download_mbps']);
    $up_data[] = round($log['upload_mbps']);
}

$page_title = "Historique | MBox Admin";
$current_page = "speedtest";
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <div class="container">
        <div class="page-header flex-between">
            <h1 class="page-title">Historique des Débits</h1>
            <a href="speedtest.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Retour Speedtest</a>
        </div>

        <!-- Filtre -->
        <div class="card border-purple mb-24">
            <div class="card-title">Sélectionner deux dates à comparer</div>
            <form method="get" class="hist-filter-form">
                <div class="hist-filter-field">
                    <label class="hist-filter-label">Date 1</label>
                    <select name="start" class="hist-filter-select">
                        <?php foreach ($available_dates as $date): ?>
                            <option value="<?php echo $date; ?>" <?php echo ($date == $start_date) ? 'selected' : ''; ?>>
                                <?php echo date('d/m/Y', strtotime($date)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hist-filter-field">
                    <label class="hist-filter-label">Date 2</label>
                    <select name="end" class="hist-filter-select">
                        <?php foreach ($available_dates as $date): ?>
                            <option value="<?php echo $date; ?>" <?php echo ($date == $end_date) ? 'selected' : ''; ?>>
                                <?php echo date('d/m/Y', strtotime($date)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-chart-line"></i> Comparer
                </button>
            </form>
        </div>

        <?php if (empty($table_logs)): ?>
            <div class="card border-purple card-no-data">
                <p class="text-muted">Aucune donnée pour cette période.</p>
            </div>
        <?php else: ?>

            <!-- Comparaison -->
            <?php
            $diff_dl = $date2_stats['avg_dl'] - $date1_stats['avg_dl'];
            $diff_up = $date2_stats['avg_up'] - $date1_stats['avg_up'];
            ?>
            <div class="dashboard-grid cols-2 mb-24">
                <div class="card border-purple">
                    <div class="card-title">
                        <i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($start_date)); ?>
                    </div>
                    <div class="hist-stat-meta">
                        <i class="fas fa-info-circle"></i> Moyenne de <?php echo $date1_stats['nb_tests']; ?> test(s)
                    </div>
                    <div class="hist-stats">
                        <div class="hist-stat">
                            <div class="hist-stat-label">Download</div>
                            <div class="hist-stat-value is-dl">
                                <?php echo round($date1_stats['avg_dl']); ?> <span class="hist-stat-unit">Mbps</span>
                            </div>
                        </div>
                        <div class="hist-stat">
                            <div class="hist-stat-label">Upload</div>
                            <div class="hist-stat-value is-up">
                                <?php echo round($date1_stats['avg_up']); ?> <span class="hist-stat-unit">Mbps</span>
                            </div>
                        </div>
                        <div class="hist-stat">
                            <div class="hist-stat-label">Ping</div>
                            <div class="hist-stat-value is-ping">
                                <?php echo round($date1_stats['avg_ping']); ?> ms
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-purple">
                    <div class="card-title">
                        <i class="fas fa-calendar-check"></i> <?php echo date('d/m/Y', strtotime($end_date)); ?>
                    </div>
                    <div class="hist-stat-meta">
                        <i class="fas fa-info-circle"></i> Moyenne de <?php echo $date2_stats['nb_tests']; ?> test(s)
                    </div>
                    <div class="hist-stats">
                        <div class="hist-stat">
                            <div class="hist-stat-label">Download</div>
                            <div class="hist-stat-value is-dl">
                                <?php echo round($date2_stats['avg_dl']); ?> <span class="hist-stat-unit">Mbps</span>
                            </div>
                        </div>
                        <div class="hist-stat">
                            <div class="hist-stat-label">Upload</div>
                            <div class="hist-stat-value is-up">
                                <?php echo round($date2_stats['avg_up']); ?> <span class="hist-stat-unit">Mbps</span>
                            </div>
                        </div>
                        <div class="hist-stat">
                            <div class="hist-stat-label">Ping</div>
                            <div class="hist-stat-value is-ping">
                                <?php echo round($date2_stats['avg_ping']); ?> ms
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-purple mb-24">
                <div class="card-title">Évolution du <?php echo date('d/m/Y', strtotime($start_date)); ?> au <?php echo date('d/m/Y', strtotime($end_date)); ?></div>
                <div class="hist-chart-wrap">
                    <canvas id="myChart"></canvas>
                </div>
            </div>

            <div class="card border-purple">
                <div class="card-title">Tous les Tests de la Période</div>
                <div class="hist-table-wrap">
                    <table class="hist-table-full">
                        <tr class="hist-header">
                            <th>Date</th>
                            <th>Ping</th>
                            <th>Download</th>
                            <th>Upload</th>
                        </tr>
                        <?php foreach ($table_logs as $log): ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($log['date'])); ?></td>
                            <td><?php echo round($log['ping_ms']); ?> ms</td>
                            <td class="is-dl"><?php echo round($log['download_mbps']); ?> Mbps</td>
                            <td class="is-up"><?php echo round($log['upload_mbps']); ?> Mbps</td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

            <script>
            const ctx = document.getElementById('myChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($labels); ?>,
                    datasets: [{
                        label: 'Download (Mbps)',
                        data: <?php echo json_encode($dl_data); ?>,
                        borderColor: '#8e44ad',
                        backgroundColor: 'rgba(142, 68, 173, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 4,
                        pointBackgroundColor: '#8e44ad',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }, {
                        label: 'Upload (Mbps)',
                        data: <?php echo json_encode($up_data); ?>,
                        borderColor: '#2ecc71',
                        backgroundColor: 'rgba(46, 204, 113, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 4,
                        pointBackgroundColor: '#2ecc71',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value + ' Mbps';
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            padding: 12,
                            titleFont: { size: 14 },
                            bodyFont: { size: 13 }
                        }
                    }
                }
            });
            </script>
        <?php endif; ?>
    </div>

</body>
</html>
