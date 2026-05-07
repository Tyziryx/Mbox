<?php
session_start();
require_once __DIR__ . '/../includes/db_connect.php';

// Si deja connecte, pas besoin de se reloguer
if (isset($_SESSION['mbox_logged']) && $_SESSION['mbox_logged'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';

    if ($password) {
        try {
            // Recuperer le mot de passe hashé dans la BDD
            $stmt = $pdo->query("SELECT password FROM admin_users LIMIT 1");
            $admin = $stmt->fetch();

            // Verifier le mot de passe avec password_verify
            if ($admin && password_verify($password, $admin['password'])) {
                $_SESSION['mbox_logged'] = true;
                header('Location: index.php');
                exit;
            } else {
                $error = 'Mot de passe incorrect';
            }
        } catch (Exception $e) {
            $error = 'Erreur de connexion';
        }
    } else {
        $error = 'Mot de passe incorrect';
    }
}

$page_title = "Connexion | MBox";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
<div class="login-logo">MBox</div>
            <div class="login-subtitle">Interface d'administration</div>

            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <input type="password" id="password" name="password" placeholder="Entrez le mot de passe" required autofocus>
                </div>
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i>
                    Se connecter
                </button>
            </form>
        </div>
    </div>
</body>
</html>
