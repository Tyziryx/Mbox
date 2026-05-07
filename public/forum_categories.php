<?php
// Gestion des categories du forum

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

function slugify_forum_category(string $value): string {
    if (function_exists('mb_strtolower')) {
        $value = trim(mb_strtolower($value, 'UTF-8'));
    } else {
        $value = trim(strtolower($value));
    }
    if ($value === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value;
}

function fetch_forum_categories(PDO $pdo): array {
    $sql = 'SELECT c.id, c.slug, c.name, c.sort_order, COUNT(t.id) AS topics_count
            FROM forum_categories c
            LEFT JOIN forum_topics t ON t.category_id = c.id
            GROUP BY c.id
            ORDER BY c.sort_order ASC, c.name ASC';
    return $pdo->query($sql)->fetchAll();
}

function fetch_forum_topics_for_admin(PDO $pdo): array {
    $sql = 'SELECT
                t.id,
                t.title,
                t.author,
                t.created_at,
                t.category_id,
                c.name AS category_name,
                GREATEST((SELECT COUNT(*) FROM forum_posts fp WHERE fp.topic_id = t.id) - 1, 0) AS replies
            FROM forum_topics t
            LEFT JOIN forum_categories c ON c.id = t.category_id
            ORDER BY t.created_at DESC
            LIMIT 200';

    return $pdo->query($sql)->fetchAll();
}

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

$message = '';
$message_type = 'success';

if ($category_feature_ready && isset($_POST['add_category'])) {
    $name = trim((string)($_POST['name'] ?? ''));
    $slug_input = trim((string)($_POST['slug'] ?? ''));
    $sort_order = (int)($_POST['sort_order'] ?? 100);

    $slug = $slug_input !== '' ? slugify_forum_category($slug_input) : slugify_forum_category($name);

    if ($name === '' || $slug === '') {
        $message = 'Nom ou slug invalide.';
        $message_type = 'error';
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM forum_categories WHERE slug = ?');
        $stmt->execute([$slug]);
        $exists = (int)$stmt->fetchColumn() > 0;

        if ($exists) {
            $message = 'Une categorie avec ce slug existe deja.';
            $message_type = 'error';
        } else {
            $stmt = $pdo->prepare('INSERT INTO forum_categories (slug, name, sort_order) VALUES (?, ?, ?)');
            $stmt->execute([$slug, $name, $sort_order]);
            $message = 'Categorie ajoutee.';
        }
    }
}

if ($category_feature_ready && isset($_POST['save_category'])) {
    $category_id = (int)($_POST['category_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $slug = slugify_forum_category((string)($_POST['slug'] ?? ''));
    $sort_order = (int)($_POST['sort_order'] ?? 100);

    if ($category_id <= 0 || $name === '' || $slug === '') {
        $message = 'Mise a jour invalide.';
        $message_type = 'error';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM forum_categories WHERE slug = ? AND id <> ? LIMIT 1');
        $stmt->execute([$slug, $category_id]);
        $duplicate = $stmt->fetch();

        if ($duplicate) {
            $message = 'Ce slug est deja utilise par une autre categorie.';
            $message_type = 'error';
        } else {
            $stmt = $pdo->prepare('UPDATE forum_categories SET name = ?, slug = ?, sort_order = ? WHERE id = ?');
            $stmt->execute([$name, $slug, $sort_order, $category_id]);
            $message = 'Categorie mise a jour.';
        }
    }
}

if ($category_feature_ready && isset($_POST['delete_category'])) {
    $category_id = (int)($_POST['category_id'] ?? 0);

    if ($category_id <= 0) {
        $message = 'Suppression invalide.';
        $message_type = 'error';
    } else {
        $stmt = $pdo->prepare('SELECT id, slug FROM forum_categories WHERE id = ?');
        $stmt->execute([$category_id]);
        $category = $stmt->fetch();

        if (!$category) {
            $message = 'Categorie introuvable.';
            $message_type = 'error';
        } elseif ($category['slug'] === 'general') {
            $message = 'La categorie General ne peut pas etre supprimee.';
            $message_type = 'error';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM forum_categories WHERE slug = ? LIMIT 1');
            $stmt->execute(['general']);
            $general = $stmt->fetch();

            $pdo->beginTransaction();
            try {
                if ($general) {
                    $update = $pdo->prepare('UPDATE forum_topics SET category_id = ? WHERE category_id = ?');
                    $update->execute([(int)$general['id'], $category_id]);
                } else {
                    $update = $pdo->prepare('UPDATE forum_topics SET category_id = NULL WHERE category_id = ?');
                    $update->execute([$category_id]);
                }

                $delete = $pdo->prepare('DELETE FROM forum_categories WHERE id = ?');
                $delete->execute([$category_id]);

                $pdo->commit();
                $message = 'Categorie supprimee.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $message = 'Erreur pendant la suppression.';
                $message_type = 'error';
            }
        }
    }
}

if ($category_feature_ready && isset($_POST['set_topic_category_admin'])) {
    $topic_id = (int)($_POST['topic_id'] ?? 0);
    $target_category_id = (int)($_POST['category_id'] ?? 0);

    if ($topic_id <= 0 || $target_category_id <= 0) {
        $message = 'Reclassement invalide.';
        $message_type = 'error';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM forum_categories WHERE id = ?');
        $stmt->execute([$target_category_id]);
        $target_exists = (bool)$stmt->fetchColumn();

        if (!$target_exists) {
            $message = 'Categorie cible introuvable.';
            $message_type = 'error';
        } else {
            $stmt = $pdo->prepare('UPDATE forum_topics SET category_id = ? WHERE id = ?');
            $stmt->execute([$target_category_id, $topic_id]);
            $message = 'Sujet reclasse.';
        }
    }
}

if ($category_feature_ready && isset($_POST['bulk_move_topics'])) {
    $source_category_id = (int)($_POST['source_category_id'] ?? -1);
    $target_category_id = (int)($_POST['target_category_id'] ?? 0);

    if ($target_category_id <= 0) {
        $message = 'Categorie cible invalide.';
        $message_type = 'error';
    } elseif ($source_category_id === $target_category_id) {
        $message = 'La source et la cible sont identiques.';
        $message_type = 'error';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM forum_categories WHERE id = ?');
        $stmt->execute([$target_category_id]);
        $target_exists = (bool)$stmt->fetchColumn();

        if (!$target_exists) {
            $message = 'Categorie cible introuvable.';
            $message_type = 'error';
        } else {
            if ($source_category_id > 0) {
                $stmt = $pdo->prepare('SELECT id FROM forum_categories WHERE id = ?');
                $stmt->execute([$source_category_id]);
                $source_exists = (bool)$stmt->fetchColumn();

                if (!$source_exists) {
                    $message = 'Categorie source introuvable.';
                    $message_type = 'error';
                } else {
                    $update = $pdo->prepare('UPDATE forum_topics SET category_id = ? WHERE category_id = ?');
                    $update->execute([$target_category_id, $source_category_id]);
                    $message = (int)$update->rowCount() . ' sujet(s) deplaces.';
                }
            } else {
                $update = $pdo->prepare('UPDATE forum_topics SET category_id = ? WHERE category_id IS NULL');
                $update->execute([$target_category_id]);
                $message = (int)$update->rowCount() . ' sujet(s) sans categorie deplaces.';
            }
        }
    }
}

$categories = $category_feature_ready ? fetch_forum_categories($pdo) : [];
$topics = $category_feature_ready ? fetch_forum_topics_for_admin($pdo) : [];

$page_title = 'Categories forum | MBox Admin';
$current_page = 'forum';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="container">
    <div class="support-wrap">
        <div class="support-head">
            <div>
                <h1 class="support-title">Administration du support</h1>
                <p class="support-subtitle">Ajout de categories et reclassement des sujets en quelques clics.</p>
            </div>
            <a href="forum.php" class="btn btn-secondary">Retour forum</a>
        </div>

        <?php if (!$category_feature_ready): ?>
            <div class="support-form-card">
                <div class="support-form-title">Migration requise</div>
                <p class="text-muted mt-8">
                    Les categories ne sont pas encore actives dans la base. Executez la migration SQL:
                </p>
                <pre class="code-box mt-12">sudo mysql box_db < /var/www/html/bin/sql/add_forum_categories.sql</pre>
            </div>
        <?php else: ?>

            <?php if ($message !== ''): ?>
                <div class="support-topic-card <?php echo $message_type === 'error' ? 'border-left-orange' : 'border-left-green'; ?>">
                    <p class="text-muted text-no-margin"><?php echo htmlspecialchars($message); ?></p>
                </div>
            <?php endif; ?>

            <div class="support-admin-layout">
                <div class="flex-col flex-gap-16">
                    <div class="support-form-card">
                        <div class="support-form-title">Nouvelle categorie</div>
                        <form method="post">
                            <div class="input-group mb-12">
                                <label class="input-label text-small">Nom</label>
                                <input type="text" name="name" required class="input-field" placeholder="Ex: Assistance TV">
                            </div>
                            <div class="input-group mb-12">
                                <label class="input-label text-small">Slug (optionnel)</label>
                                <input type="text" name="slug" class="input-field" placeholder="Ex: assistance-tv">
                            </div>
                            <div class="input-group mb-12">
                                <label class="input-label text-small">Ordre</label>
                                <input type="number" name="sort_order" value="100" class="input-field">
                            </div>
                            <button type="submit" name="add_category" class="btn btn-yellow w-100">Ajouter</button>
                        </form>
                    </div>

                    <div class="support-form-card">
                        <div class="support-form-title">Deplacement massif des sujets</div>
                        <form method="post">
                            <div class="input-group mb-12">
                                <label class="input-label text-small">Categorie source</label>
                                <select name="source_category_id" class="input-field">
                                    <option value="0">Sans categorie</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo (int)$category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="input-group mb-12">
                                <label class="input-label text-small">Categorie cible</label>
                                <select name="target_category_id" class="input-field" required>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo (int)$category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="bulk_move_topics" class="btn btn-secondary w-100">Deplacer les sujets</button>
                        </form>
                    </div>
                </div>

                <div class="support-form-card">
                    <div class="support-form-title">Categories existantes</div>

                    <?php if (empty($categories)): ?>
                        <p class="text-muted">Aucune categorie.</p>
                    <?php else: ?>
                        <div class="support-admin-cats">
                            <?php foreach ($categories as $category): ?>
                                <form method="post" class="support-admin-cat-item">
                                    <input type="hidden" name="category_id" value="<?php echo (int)$category['id']; ?>">

                                    <div class="input-group mb-12">
                                        <label class="input-label text-small">Nom</label>
                                        <input type="text" name="name" value="<?php echo htmlspecialchars($category['name']); ?>" required class="input-field">
                                    </div>

                                    <div class="input-group mb-12">
                                        <label class="input-label text-small">Slug</label>
                                        <input type="text" name="slug" value="<?php echo htmlspecialchars($category['slug']); ?>" required class="input-field">
                                    </div>

                                    <div class="support-inline-form mt-0">
                                        <div class="min-w-110">
                                            <label class="input-label text-small">Ordre</label>
                                            <input type="number" name="sort_order" value="<?php echo (int)$category['sort_order']; ?>" class="input-field">
                                        </div>
                                        <button type="submit" name="save_category" class="btn btn-secondary btn-sm">Enregistrer</button>
                                        <button
                                            type="submit"
                                            name="delete_category"
                                            class="btn btn-secondary btn-sm btn-warn-border"
                                            onclick="return confirm('Supprimer cette categorie ? Les sujets seront bascules vers General.');"
                                        >
                                            Supprimer
                                        </button>
                                    </div>

                                    <div class="text-muted text-small mt-8"><?php echo (int)$category['topics_count']; ?> sujet(s)</div>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="support-form-card">
                <div class="support-form-title">Rearranger les sujets par categorie</div>

                <?php if (empty($topics)): ?>
                    <p class="text-muted">Aucun sujet trouve.</p>
                <?php else: ?>
                    <div class="support-admin-topics">
                        <?php foreach ($topics as $topic): ?>
                            <div class="support-admin-topic-row">
                                <h4><?php echo htmlspecialchars($topic['title']); ?></h4>
                                <div class="support-admin-topic-meta">
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($topic['author']); ?>
                                    &nbsp;|&nbsp;
                                    <i class="far fa-clock"></i> <?php echo date('d/m H:i', strtotime($topic['created_at'])); ?>
                                    &nbsp;|&nbsp;
                                    <strong><?php echo htmlspecialchars($topic['category_name'] ?? 'Sans categorie'); ?></strong>
                                    &nbsp;|&nbsp;
                                    <?php echo (int)$topic['replies']; ?> rep.
                                </div>

                                <form method="post" class="support-inline-form">
                                    <input type="hidden" name="topic_id" value="<?php echo (int)$topic['id']; ?>">
                                    <select name="category_id" class="input-field min-w-220">
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo (int)$category['id']; ?>" <?php echo ((int)($topic['category_id'] ?? 0) === (int)$category['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($category['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="set_topic_category_admin" class="btn btn-secondary btn-sm">Reclasser</button>
                                    <a href="topic.php?id=<?php echo (int)$topic['id']; ?>" class="btn-link">Voir</a>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
