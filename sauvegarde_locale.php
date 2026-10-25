<?php
/**
 * Sauvegarde de la base LOCALE (celle de app.env ou de DATABASE_URL), au même format que les sauvegardes du serveur.
 * Usage, depuis le dossier du projet :  php sauvegarde_locale.php
 * Le fichier est écrit dans backups/ et son chemin est affiché.
 */
declare(strict_types=1);

foreach (['config', 'html', 'db', 'ops'] as $file) {
    require __DIR__ . '/src/' . $file . '.php';
}
$path = backup_database(db(), 'local');
$counts = [];
foreach (['articles', 'translations', 'users', 'categories'] as $table) {
    $counts[] = $table . ' : ' . db()->value("SELECT COUNT(*) FROM $table");
}
fwrite(STDERR, implode(', ', $counts) . "\n");
echo basename($path), "\n";
