<?php
// Charge les variables depuis .env
// Parse ligne par ligne et met dans $_ENV

function loadEnv($path = __DIR__ . '/.env') {
    if (!file_exists($path)) {
        die("Erreur: Le fichier .env est introuvable.");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Ignorer les commentaires (lignes qui commencent par #)
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parser KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Stocker dans $_ENV pour y acceder apres
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

loadEnv();