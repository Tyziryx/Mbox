<?php
// Protection des pages - verifie qu'on est bien connecte
// Sinon redirection vers login.php

if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_cache_limiter('nocache');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    session_start();
}

if (!isset($_SESSION['mbox_logged']) || $_SESSION['mbox_logged'] !== true) {
    header('Location: login.php');
    exit;
}
