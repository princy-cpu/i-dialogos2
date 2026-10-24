<?php
/**
 * Chargeur installé à la racine du site (htdocs). Il exécute la version en ligne, désignée par le fichier
 * .app/current : changer ce fichier fait basculer tout le site d'un coup (déploiement et retour arrière).
 */
declare(strict_types=1);

$base = __DIR__ . '/.app';
$release = trim((string) @file_get_contents($base . '/current'));
$front = $base . '/releases/' . $release . '/src/front.php';
if ($release === '' || !preg_match('/^[A-Za-z0-9._-]+$/D', $release) || !is_file($front)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 60');
    echo "Site en cours de mise à jour. Réessayez dans une minute.\n";
    exit;
}
require $front;
