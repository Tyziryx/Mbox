<?php
// Connexion au webmail - session separee de l'admin
// Verifie les credentials dans mail_users

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

$error = '';

// Si déjà connecté, rediriger vers mail.php
if (isset($_SESSION['mail_user'])) {
    header('Location: mail.php');
    exit;
}

// Traitement du formulaire
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if ($username && $password) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM mail_users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Login OK
                $_SESSION['mail_user'] = $user['username'];
                header('Location: mail.php');
                exit;
            } else {
                $error = "Identifiant ou mot de passe incorrect";
            }
        } catch (Exception $e) {
            $error = "Erreur de connexion";
        }
    } else {
        $error = "Veuillez remplir tous les champs";
    }
}

$page_title = "Connexion Messagerie | MBox";
$current_page = "mail";
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Messagerie</h1>
        </div>

        <div class="card border-blue max-w-500 p-20 my-40">
            <div class="card-header flex-center mb-32">
                <div class="card-icon icon-large">
                    <i class="fas fa-envelope"></i>
                </div>
            </div>

            <h2 class="text-center mb-32 text-primary-color">Connexion</h2>

            <?php if ($error): ?>
                <div class="bg-error p-12 rounded mb-20">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="input-group mb-20">
                    <label class="input-label">
                        <i class="fas fa-user"></i> Nom d'utilisateur
                    </label>
                    <input type="text" name="username" required autofocus class="input-field" placeholder="alexi, rachid ou stud">
                </div>

                <div class="input-group mb-32">
                    <label class="input-label">
                        <i class="fas fa-lock"></i> Mot de passe
                    </label>
                    <input type="password" name="password" required class="input-field" placeholder="Entrez votre mot de passe">
                </div>

                <button type="submit" name="login" class="btn btn-primary w-100 btn-submit-tall">
                    <i class="fas fa-sign-in-alt"></i> Se connecter
                </button>
            </form>

            <div class="text-center mt-20 border-top">
                <small class="text-muted">
                    <i class="fas fa-info-circle"></i> Utilisez vos identifiants de messagerie
                </small>
            </div>
        </div>
    </div>

</body>
</html>
