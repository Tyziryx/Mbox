<?php
// Webmail basique - lecture et envoi de mails
// Les mails sont dans /home/$user/Maildir/new/

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/server_info.php';

// Vérif connexion
if (!isset($_SESSION['mail_user'])) {
    header('Location: login_mail.php');
    exit;
}

$current_user = $_SESSION['mail_user'];

// Déconnexion
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login_mail.php');
    exit;
}

// Fonction pour lire les mails depuis le Maildir
// Parse Subject, From, Date...
function get_mails($user, $limit = 20) {
    $maildir = "/home/$user/Maildir/new/";
    $mails = [];

    if (!is_dir($maildir)) return $mails;

    $files = @scandir($maildir);
    if (!$files) return $mails;

    $files = array_diff($files, ['.', '..']);
    rsort($files);  // Plus récent en premier
    $files = array_slice($files, 0, $limit);

    foreach ($files as $file) {
        $filepath = $maildir . $file;
        if (!is_readable($filepath)) continue;

        $content = file_get_contents($filepath);

        // Extraction des headers avec regex
        preg_match('/^Subject:\s*(.*)$/m', $content, $subject);
        preg_match('/^From:\s*(.*)$/m', $content, $from);
        preg_match('/^Date:\s*(.*)$/m', $content, $date);

        // Extraction du corps (après la ligne vide)
        if (strpos($content, "\r\n\r\n") !== false) {
            $parts = explode("\r\n\r\n", $content, 2);
        } else {
            $parts = explode("\n\n", $content, 2);
        }
        $body = isset($parts[1]) ? trim($parts[1]) : '';

        $mails[] = [
            'subject' => isset($subject[1]) ? trim($subject[1]) : 'Sans sujet',
            'from' => isset($from[1]) ? trim($from[1]) : 'Inconnu',
            'date' => isset($date[1]) ? trim($date[1]) : 'Date inconnue',
            'body' => $body,
            'preview' => substr($body, 0, 150),
            'is_long' => strlen($body) > 150,
            'file' => $file
        ];
    }

    return $mails;
}

$sent = false;

// Envoi de mail
if (isset($_POST['send_mail'])) {
    $to = trim($_POST['to']);
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);

    if ($to && $subject && $message) {
        $headers = "From: $current_user@" . ($dns_domain ?: "localhost") . "\r\n";

        if (mail($to, $subject, $message, $headers)) {
            $sent = true;

            // Correction permissions Maildir pour le destinataire
            // Extrait le username du destinataire (avant @)
            $recipient_user = explode('@', $to)[0];
            if (in_array($recipient_user, ['alexi', 'rachid', 'stud'])) {
                // Attends 2 secondes que Postfix livre le mail, puis corrige les permissions
                shell_exec("(sleep 2 && sudo chmod -R g+rX /home/" . escapeshellarg($recipient_user) . "/Maildir 2>/dev/null) &");
            }
        }
    }
}

$mails = get_mails($current_user);

$page_title = "Messagerie | MBox Admin";
$current_page = "mail";
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <div class="container">
        <div class="page-header">
            <div>
                <h1 class="page-title">Messagerie - <?php echo htmlspecialchars($current_user); ?></h1>
                <p class="text-muted mt-8">
                    <i class="fas fa-user-circle"></i> Connecté en tant que <strong><?php echo htmlspecialchars($current_user); ?></strong>
                </p>
            </div>
            <a href="mail.php?logout=1" class="btn btn-secondary" onclick="return confirm('Se déconnecter ?')">
                <i class="fas fa-sign-out-alt"></i> Déconnexion
            </a>
        </div>

        <div class="dashboard-grid cols-2">

            <!-- ENVOI DE MAIL -->
            <div class="card border-green">
                <div class="card-header">
                    <div class="card-title">Envoyer un mail</div>
                    <div class="card-icon"><i class="fas fa-paper-plane"></i></div>
                </div>

                <?php if ($sent): ?>
                    <div class="mail-alert-success">
                        <i class="fas fa-check-circle"></i> Mail envoyé avec succès !
                    </div>
                <?php endif; ?>

                <form method="post" class="mt-16">
                    <div class="input-group">
                        <label class="mail-form-label">
                            Destinataire
                        </label>
                        <input type="text" name="to" placeholder="rachid@<?php echo htmlspecialchars($dns_domain ?: 'example.com'); ?>" required
                               class="input-field">
                        <small class="mail-form-help">
                            <i class="fas fa-info-circle"></i> Utilisateurs locaux : alexi, rachid, stud @<?php echo htmlspecialchars($dns_domain ?: 'localhost'); ?>
                        </small>
                    </div>

                    <div class="input-group">
                        <label class="mail-form-label">
                            Sujet
                        </label>
                        <input type="text" name="subject" required
                               class="input-field">
                    </div>

                    <div class="input-group mb-16">
                        <label class="mail-form-label">
                            Message
                        </label>
                        <textarea name="message" rows="6" required
                                  class="textarea-field"></textarea>
                    </div>

                    <button type="submit" name="send_mail" class="btn btn-primary w-100">
                        <i class="fas fa-paper-plane"></i> Envoyer
                    </button>
                </form>
            </div>

            <!-- BOÎTE DE RÉCEPTION -->
            <div class="card border-blue">
                <div class="card-header">
                    <div class="card-title">Boîte de réception</div>
                    <div class="card-icon"><i class="fas fa-inbox"></i></div>
                </div>

                <div class="mt-16">
                    <div class="mail-list-header">
                        <span class="mail-from-bold">
                            <?php echo count($mails); ?> message(s)
                        </span>
                        <a href="mail.php" class="btn-link"><i class="fas fa-sync-alt"></i> Actualiser</a>
                    </div>

                    <?php if (empty($mails)): ?>
                        <div class="mail-empty">
                            <i class="fas fa-inbox mail-empty-icon"></i>
                            <p>Aucun mail reçu</p>
                        </div>
                    <?php else: ?>
                        <div class="mail-list-scroll">
                            <?php foreach ($mails as $index => $mail): ?>
                            <div class="mail-item">
                                <div class="mail-item-head">
                                    <strong class="text-primary-color text-14">
                                        <?php echo htmlspecialchars($mail['subject']); ?>
                                    </strong>
                                </div>
                                <div class="mail-meta">
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($mail['from']); ?>
                                    <span class="mail-meta-right">
                                        <i class="fas fa-clock"></i> <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($mail['date']))); ?>
                                    </span>
                                </div>
                                <div class="mail-body">
                                    <span id="mail-preview-<?php echo $index; ?>">
                                        <?php echo htmlspecialchars($mail['preview']); ?><?php if ($mail['is_long']): ?>...<br>
                                        <a href="#" class="btn-link" onclick="toggleMail(<?php echo $index; ?>); return false;">
                                            <i class="fas fa-chevron-down"></i> Lire plus
                                        </a>
                                        <?php endif; ?>
                                    </span>
                                    <span id="mail-full-<?php echo $index; ?>" class="d-none">
                                        <?php echo nl2br(htmlspecialchars($mail['body'])); ?><?php if ($mail['is_long']): ?><br>
                                        <a href="#" class="btn-link" onclick="toggleMail(<?php echo $index; ?>); return false;">
                                            <i class="fas fa-chevron-up"></i> Lire moins
                                        </a>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- SCRIPT TOGGLE MAIL -->
    <script>
        // Toggle mail expand/collapse
        function toggleMail(index) {
            const preview = document.getElementById('mail-preview-' + index);
            const full = document.getElementById('mail-full-' + index);

            if (!preview || !full) return;

            const showFull = full.classList.contains('d-none');
            preview.classList.toggle('d-none', showFull);
            full.classList.toggle('d-none', !showFull);
        }
    </script>

</body>
</html>
