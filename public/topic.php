<?php
// Affichage d'un topic du forum avec ses reponses
// Parametre GET: id = ID du sujet

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

function fetch_forum_categories(PDO $pdo): array {
    try {
        return $pdo->query('SELECT id, slug, name, sort_order FROM forum_categories ORDER BY sort_order ASC, name ASC')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

if (!isset($_GET['id'])) {
    header("Location: forum.php");
    exit;
}
$topic_id = intval($_GET['id']);

$forum_categories = [];
$forum_categories_by_id = [];
$category_feature_ready = forum_table_exists($pdo, 'forum_categories') && forum_column_exists($pdo, 'forum_topics', 'category_id');

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
    $forum_categories = fetch_forum_categories($pdo);
    foreach ($forum_categories as $category) {
        $forum_categories_by_id[(int)$category['id']] = $category;
    }
}

// CHANGER LA CATEGORIE DU SUJET
if (isset($_POST['set_topic_category']) && $category_feature_ready) {
    $category_id = (int)($_POST['category_id'] ?? 0);
    if (isset($forum_categories_by_id[$category_id])) {
        $stmt = $pdo->prepare('UPDATE forum_topics SET category_id = ? WHERE id = ?');
        $stmt->execute([$category_id, $topic_id]);
    }
    header("Location: topic.php?id=$topic_id");
    exit;
}

// RÉPONDRE AU SUJET
if (isset($_POST['reply'])) {
    $author = trim((string)($_POST['author'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));

    if ($author !== '' && $message !== '') {
        $stmt = $pdo->prepare("INSERT INTO forum_posts (topic_id, author, message) VALUES (?, ?, ?)");
        $stmt->execute([$topic_id, $author, $message]);
    }
}

// Infos du sujet
$stmt = $pdo->prepare(
    $category_feature_ready
        ? 'SELECT t.*, c.name AS category_name, c.slug AS category_slug
           FROM forum_topics t
           LEFT JOIN forum_categories c ON t.category_id = c.id
           WHERE t.id = ?'
        : 'SELECT t.*, NULL AS category_name, NULL AS category_slug
           FROM forum_topics t
           WHERE t.id = ?'
);
$stmt->execute([$topic_id]);
$topic = $stmt->fetch();

if (!$topic) {
    header('Location: forum.php');
    exit;
}

// Messages du sujet
$stmt = $pdo->prepare("SELECT * FROM forum_posts WHERE topic_id = ? ORDER BY created_at ASC");
$stmt->execute([$topic_id]);
$posts = $stmt->fetchAll();

$page_title = htmlspecialchars($topic['title']) . " | MBox Admin";
$current_page = "forum";
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container">
    <div class="support-wrap">
        <div class="support-head">
            <div>
                <a href="forum.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Retour aux sujets</a>
                <h1 class="support-title mt-8"><?php echo htmlspecialchars($topic['title']); ?></h1>
                <p class="support-subtitle">
                    <?php if (!empty($topic['category_name'])): ?>
                        Categorie: <strong><?php echo htmlspecialchars($topic['category_name']); ?></strong>
                        &nbsp;|&nbsp;
                    <?php endif; ?>
                    Discussion demarree par <?php echo htmlspecialchars($topic['author']); ?>
                </p>
            </div>
        </div>

        <div class="support-grid">
            <div class="support-topic-stream">
                <?php foreach ($posts as $p): ?>
                    <div class="support-msg-card <?php echo ($p['author'] === 'Admin') ? 'is-admin' : ''; ?>">
                        <div class="support-msg-head">
                            <strong><?php echo htmlspecialchars($p['author']); ?></strong>
                            <span><?php echo date('d/m/Y H:i', strtotime($p['created_at'])); ?></span>
                        </div>
                        <div class="msg-content">
                            <?php echo nl2br(htmlspecialchars($p['message'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div>
                <div class="support-form-card sticky-top-100">
                    <div class="support-form-title">Repondre</div>
                    <form method="post">
                        <div class="input-group mb-12">
                            <label class="input-label text-small">Pseudo</label>
                            <input type="text" name="author" required class="input-field" placeholder="Votre pseudo">
                        </div>
                        <div class="input-group mb-12">
                            <label class="input-label text-small">Message</label>
                            <textarea name="message" required rows="5" class="textarea-field" placeholder="Votre reponse..."></textarea>
                        </div>
                        <button type="submit" name="reply" class="btn btn-yellow w-100">Envoyer</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>