<?php
// Forum de support - créer des tickets et voir les sujets
// Affiche la liste des topics avec le nombre de reponses

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

function forum_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function forum_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function sync_default_forum_categories(PDO $pdo, array $defaults) {
    $stmt = $pdo->prepare(
        'INSERT INTO forum_categories (slug, name, sort_order) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order)'
    );
    foreach ($defaults as $category) {
        $stmt->execute([
            $category['slug'],
            $category['name'],
            (int)$category['sort_order']
        ]);
    }
}

function fetch_forum_categories(PDO $pdo): array {
    try {
        return $pdo->query('SELECT id, slug, name, sort_order FROM forum_categories ORDER BY sort_order ASC, name ASC')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function forum_category_description(string $slug): string {
    $descriptions = [
        'general' => 'Discussions globales sur la box et les reglages.',
        'controle-parental' => 'Filtres, profils enfant/ado et quotas.',
        'reseau' => 'Wi-Fi, Ethernet, latence et connexions locales.',
        'telephonie' => 'Problemes de ligne fixe, appels et SIP.',
        'autre' => 'Tout sujet support qui ne rentre pas ailleurs.',
    ];

    return $descriptions[$slug] ?? 'Sujets et entraide de cette categorie.';
}

function forum_category_icon(string $slug, string $name = ''): string {
    $slug_l = strtolower($slug);
    $name_l = strtolower($name);

    if (strpos($slug_l, 'parent') !== false || strpos($name_l, 'parent') !== false) {
        return 'fa-shield-alt';
    }

    $icons = [
        'general' => 'fa-comments',
        'controle-parental' => 'fa-shield-alt',
        'reseau' => 'fa-wifi',
        'telephonie' => 'fa-phone',
        'autre' => 'fa-ellipsis',
    ];

    return $icons[$slug_l] ?? 'fa-folder';
}

function build_forum_title_from_message(string $message, int $max_words = 8): string {
    $clean = trim((string)preg_replace('/\s+/u', ' ', strip_tags($message)));
    if ($clean === '') {
        return 'Nouveau sujet';
    }

    $words = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($words) || empty($words)) {
        return 'Nouveau sujet';
    }

    $slice = array_slice($words, 0, $max_words);
    $title = implode(' ', $slice);
    if (count($words) > $max_words) {
        $title .= '...';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($title, 0, 90, 'UTF-8');
    }

    return substr($title, 0, 90);
}

// Ajouter ici de nouvelles categories pour le forum.
$default_forum_categories = [
    ['slug' => 'general', 'name' => 'General', 'sort_order' => 10],
    ['slug' => 'controle-parental', 'name' => 'Controle parental', 'sort_order' => 20],
    ['slug' => 'reseau', 'name' => 'Reseau', 'sort_order' => 30],
    ['slug' => 'telephonie', 'name' => 'Telephonie', 'sort_order' => 40],
    ['slug' => 'autre', 'name' => 'Autre', 'sort_order' => 50],
];

$forum_categories = [];
$forum_categories_by_id = [];
$default_category_id = null;

$has_category_table = forum_table_exists($pdo, 'forum_categories');
$has_topic_category_column = forum_column_exists($pdo, 'forum_topics', 'category_id');
$category_feature_ready = $has_category_table && $has_topic_category_column;

if (!$category_feature_ready) {
    try {
        $pdo->query('SELECT id FROM forum_categories LIMIT 1');
        $pdo->query('SELECT category_id FROM forum_topics LIMIT 1');
        $category_feature_ready = true;
    } catch (Throwable $e) {
        $category_feature_ready = false;
    }
}

if ($category_feature_ready) {
    sync_default_forum_categories($pdo, $default_forum_categories);
    $forum_categories = fetch_forum_categories($pdo);

    foreach ($forum_categories as $category) {
        $cid = (int)$category['id'];
        $forum_categories_by_id[$cid] = $category;
        if ($category['slug'] === 'general') {
            $default_category_id = $cid;
        }
    }

    if ($default_category_id === null && !empty($forum_categories)) {
        $default_category_id = (int)$forum_categories[0]['id'];
    }
}

$active_category_id = null;
if ($category_feature_ready && isset($_GET['cat']) && ctype_digit((string)$_GET['cat'])) {
    $candidate = (int)$_GET['cat'];
    if ($candidate > 0 && isset($forum_categories_by_id[$candidate])) {
        $active_category_id = $candidate;
    }
}

// RECLASSER UN SUJET EXISTANT
if (isset($_POST['set_topic_category']) && $category_feature_ready) {
    $topic_id = (int)($_POST['topic_id'] ?? 0);
    $category_id = (int)($_POST['category_id'] ?? 0);

    if ($topic_id > 0 && isset($forum_categories_by_id[$category_id])) {
        $stmt = $pdo->prepare('UPDATE forum_topics SET category_id = ? WHERE id = ?');
        $stmt->execute([$category_id, $topic_id]);
    }

    $redirect_cat = (int)($_POST['redirect_cat'] ?? 0);
    if ($redirect_cat > 0 && isset($forum_categories_by_id[$redirect_cat])) {
        header('Location: forum.php?cat=' . $redirect_cat);
    } elseif ($active_category_id !== null) {
        header('Location: forum.php?cat=' . $active_category_id);
    } else {
        header('Location: forum.php');
    }
    exit;
}

// CREER UN NOUVEAU SUJET
if (isset($_POST['new_topic'])) {
    $author = trim((string)($_POST['author'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));

    if ($author !== '' && $message !== '' && $title !== '') {
        if ($category_feature_ready) {
            $category_id = (int)($_POST['category_id'] ?? 0);
            if ($category_id <= 0 && $active_category_id !== null) {
                $category_id = $active_category_id;
            }
            if (!isset($forum_categories_by_id[$category_id])) {
                $category_id = $default_category_id ?? 0;
            }

            $stmt = $pdo->prepare('INSERT INTO forum_topics (title, author, category_id) VALUES (?, ?, ?)');
            $stmt->execute([$title, $author, $category_id > 0 ? $category_id : null]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO forum_topics (title, author) VALUES (?, ?)');
            $stmt->execute([$title, $author]);
        }

        $topic_id = $pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO forum_posts (topic_id, author, message) VALUES (?, ?, ?)');
        $stmt->execute([$topic_id, $author, $message]);

        header('Location: topic.php?id=' . $topic_id);
        exit;
    }
}

// LISTE DES SUJETS (uniquement quand une categorie est selectionnee)
$topics = [];
if ($category_feature_ready && $active_category_id !== null) {
    $sql = 'SELECT t.*, MAX(c.name) AS category_name, MAX(c.slug) AS category_slug, COUNT(p.id) - 1 AS replies
            FROM forum_topics t
            LEFT JOIN forum_posts p ON t.id = p.topic_id
            LEFT JOIN forum_categories c ON t.category_id = c.id
            WHERE t.category_id = ?
            GROUP BY t.id
            ORDER BY t.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$active_category_id]);
    $topics = $stmt->fetchAll();
}

$category_stats = [];
$total_topics = 0;
if ($category_feature_ready) {
    $total_topics = (int)$pdo->query('SELECT COUNT(*) FROM forum_topics')->fetchColumn();
    $stats_sql = 'SELECT c.id, c.slug, c.name, c.sort_order, COUNT(t.id) AS topics_count
                  FROM forum_categories c
                  LEFT JOIN forum_topics t ON t.category_id = c.id
                  GROUP BY c.id
                  ORDER BY c.sort_order ASC, c.name ASC';
    $category_stats = $pdo->query($stats_sql)->fetchAll();
}

$selected_category = null;
if ($active_category_id !== null && isset($forum_categories_by_id[$active_category_id])) {
    $selected_category = $forum_categories_by_id[$active_category_id];
}

$page_title = 'Support | MBox Admin';
$current_page = 'forum';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container">
    <div class="support-wrap">
        <div class="support-head">
            <div>
                <h1 class="support-title">Forum d'entraide</h1>
                <?php if ($category_feature_ready && $selected_category === null): ?>
                    <p class="support-subtitle">Selectionnez une categorie pour consulter les sujets.</p>
                <?php elseif ($category_feature_ready && $selected_category !== null): ?>
                    <p class="support-subtitle">Consultez ou participez aux discussions de cette categorie.</p>
                <?php else: ?>
                    <p class="support-subtitle">Forum de support.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($category_feature_ready && $selected_category === null): ?>
            <div class="support-categories">
                <?php foreach ($category_stats as $cat): ?>
                    <?php $cat_id = (int)$cat['id']; ?>
                    <a href="forum.php?cat=<?php echo $cat_id; ?>" class="support-category-card">
                        <div class="support-cat-top">
                            <div class="support-cat-icon"><i class="fas <?php echo forum_category_icon((string)$cat['slug'], (string)$cat['name']); ?>"></i></div>
                            <span class="support-cat-count"><?php echo (int)$cat['topics_count']; ?> sujet<?php echo ((int)$cat['topics_count'] > 1) ? 's' : ''; ?></span>
                        </div>
                        <div class="support-cat-body">
                            <span class="support-cat-name"><?php echo htmlspecialchars($cat['name']); ?></span>
                            <div class="support-cat-sub"><?php echo htmlspecialchars(forum_category_description((string)$cat['slug'])); ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php elseif ($category_feature_ready && $selected_category !== null): ?>
            <div class="support-selected-head">
                <a href="forum.php" class="support-back-btn" aria-label="Retour categories">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h2 class="support-selected-title">Categorie : <?php echo htmlspecialchars($selected_category['name']); ?></h2>
                    <p class="support-selected-sub">Consultez ou participez aux discussions de cette categorie.</p>
                </div>
            </div>

            <div class="support-grid">
                <div class="support-list">
                    <?php if (empty($topics)): ?>
                        <div class="support-topic-card">
                            <p class="text-muted">Aucun sujet dans cette categorie pour le moment.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($topics as $t): ?>
                            <div class="support-topic-card">
                                <div class="support-topic-top">
                                    <div>
                                        <h3 class="support-topic-title">
                                            <a class="support-topic-title-link" href="topic.php?id=<?php echo (int)$t['id']; ?>">
                                                <?php echo htmlspecialchars($t['title']); ?>
                                            </a>
                                        </h3>
                                        <div class="support-topic-meta">
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($t['author']); ?>
                                            &nbsp;|&nbsp;
                                            <i class="far fa-clock"></i> <?php echo date('d/m H:i', strtotime($t['created_at'])); ?>
                                        </div>
                                    </div>
                                    <span class="support-replies"><?php echo max(0, (int)$t['replies']); ?> rep.</span>
                                </div>

                                <div class="support-topic-actions">
                                    <a href="topic.php?id=<?php echo (int)$t['id']; ?>" class="btn btn-primary">Voir la discussion</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div>
                    <div class="support-form-card sticky-top-100">
                        <div class="support-form-title">Creer un sujet</div>
                        <form method="post">
                            <input type="hidden" name="category_id" value="<?php echo (int)$selected_category['id']; ?>">

                            <div class="input-group mb-12">
                                <label class="input-label text-small">Titre du sujet</label>
                                <input type="text" name="title" required class="input-field" placeholder="Resume du probleme">
                            </div>

                            <div class="input-group mb-12">
                                <label class="input-label text-small">Pseudo</label>
                                <input type="text" name="author" required class="input-field" placeholder="Votre pseudo">
                            </div>

                            <div class="input-group mb-12">
                                <label class="input-label text-small">Message</label>
                                <textarea name="message" required rows="5" class="textarea-field" placeholder="Detaillez votre probleme ici..."></textarea>
                            </div>

                            <button type="submit" name="new_topic" class="btn btn-yellow w-100">Publier</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="support-form-card">
                <div class="support-form-title">Migration requise</div>
                <p class="text-muted">Les categories ne sont pas encore actives dans la base.</p>
                <pre class="code-box mt-12">sudo mysql box_db < /var/www/html/bin/sql/add_forum_categories.sql</pre>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>