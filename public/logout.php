<?php
// Deconnexion - detruit la session et renvoie au login

session_start();
session_destroy();
header('Location: login.php');
exit;
