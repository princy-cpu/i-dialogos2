<?php
/**
 * Opérations de déploiement, appelées par GitHub Actions avec le jeton DEPLOY_TOKEN (en-tête X-Deploy-Token) :
 *   POST /_ops/backup   sauvegarde compressée de la base dans .app/backups (les 10 dernières sont gardées) ;
 *   POST /_ops/migrate  applique les migrations en attente.
 * Sans DEPLOY_TOKEN dans app.env, ces adresses n'existent pas (404).
 */
declare(strict_types=1);

const BACKUP_TABLES = ['users', 'languages', 'templates', 'categories', 'tags', 'articles', 'article_categories', 'article_tags',
    'comments', 'news_events', 'translations', 'schema_version', 'login_attempts'];
const SERIAL_TABLES = ['users', 'templates', 'categories', 'tags', 'comments', 'news_events'];

function ops_route(string $path): void
{
    $token = setting('DEPLOY_TOKEN');
    if ($token === '' || strlen($token) < 24) {
        not_found_plain();
        return;
    }
    if (!hash_equals($token, (string) ($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? ''))) {
        send_body('Accès refusé', 403, 'text/plain; charset=utf-8');
        return;
    }
    @set_time_limit(300);
    if ($path === '/_ops/backup') {
        $label = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['label'] ?? '')) ?: 'manuel';
        $file = backup_database(db(), $label);
        send_body(json_encode(['status' => 'ok', 'file' => basename($file), 'bytes' => filesize($file)]), 200, 'application/json');
        return;
    }
    if ($path === '/_ops/migrate') {
        $applied = migrate(db());
        send_body(json_encode(['status' => 'ok', 'applied' => $applied, 'schema' => schema_version(db())]), 200, 'application/json');
        return;
    }
    not_found_plain();
}

function backup_dir(): string
{
    return (app_base() ?? app_root()) . '/backups';
}

/**
 * Structure à recréer avant les données : la structure de base, puis chaque table et chaque colonne réellement présentes
 * dans la base. Une sauvegarde faite par une version plus ancienne du code (après un retour arrière) contient ainsi
 * aussi les colonnes ajoutées par des migrations plus récentes.
 */
function schema_statements_from_database(Db $db): array
{
    $statements = BASELINE_SCHEMA;
    $columns = $db->all("SELECT c.relname AS table_name, a.attname AS column_name, format_type(a.atttypid, a.atttypmod) AS type,
            a.attnotnull AS not_null, pg_get_expr(d.adbin, d.adrelid) AS default_value
        FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace
        LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
        WHERE n.nspname = current_schema() AND c.relkind = 'r' AND a.attnum > 0 AND NOT a.attisdropped AND c.relname <> 'sessions'
        ORDER BY c.relname, a.attnum");
    $byTable = [];
    foreach ($columns as $column) {
        $definition = '"' . $column['column_name'] . '" ' . $column['type'];
        $default = (string) ($column['default_value'] ?? '');
        // NOT NULL seulement avec une valeur par défaut : une colonne ajoutée à une table déjà remplie doit pouvoir être créée.
        if ($default !== '' && !str_starts_with($default, 'nextval(')) {
            $definition .= ' DEFAULT ' . $default . ($column['not_null'] ? ' NOT NULL' : '');
        }
        $byTable[$column['table_name']][] = $definition;
    }
    foreach ($byTable as $table => $definitions) {
        $statements[] = 'CREATE TABLE IF NOT EXISTS "' . $table . '" (' . implode(', ', $definitions) . ')';
        foreach ($definitions as $definition) {
            $statements[] = 'ALTER TABLE "' . $table . '" ADD COLUMN IF NOT EXISTS ' . $definition;
        }
    }
    return $statements;
}

/** Écrit un fichier SQL compressé (structure + données), réimportable dans PhpPgAdmin après décompression. */
function backup_database(Db $db, string $label): string
{
    $directory = backup_dir();
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $file = $directory . '/' . gmdate('Ymd-His') . '-' . $label . '.sql.gz';
    $partial = $file . '.part';
    $out = gzopen($partial, 'wb6');
    gzwrite($out, "-- Sauvegarde I-Dialogos du " . now_iso() . "\nSET client_encoding = 'UTF8';\nBEGIN;\n");
    foreach (schema_statements_from_database($db) as $statement) {
        gzwrite($out, $statement . ";\n");
    }
    $existing = array_column($db->all("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'"), 'table_name');
    // Ordre compatible avec les clés étrangères, puis les éventuelles tables ajoutées par des migrations plus récentes.
    $tables = array_values(array_unique(array_merge(array_intersect(BACKUP_TABLES, $existing), array_diff($existing, BACKUP_TABLES, ['sessions']))));
    gzwrite($out, 'TRUNCATE ' . implode(', ', array_merge(['sessions'], $tables)) . " RESTART IDENTITY CASCADE;\n");
    foreach ($tables as $table) {
        $statement = $db->pdo->query("SELECT * FROM $table");
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $values = array_map(fn($value) => $value === null ? 'NULL' : (is_int($value) || is_float($value) ? (string) $value : $db->pdo->quote((string) $value)), array_values($row));
            gzwrite($out, "INSERT INTO $table (" . implode(', ', array_map(fn($c) => '"' . $c . '"', array_keys($row))) . ') VALUES (' . implode(', ', $values) . ");\n");
        }
    }
    foreach (SERIAL_TABLES as $table) {
        if (in_array($table, $tables, true)) {
            gzwrite($out, "SELECT setval(pg_get_serial_sequence('$table', 'id'), COALESCE((SELECT MAX(id) FROM $table), 0) + 1, false);\n");
        }
    }
    gzwrite($out, "COMMIT;\n");
    gzclose($out);
    rename($partial, $file);
    $backups = glob($directory . '/*.sql.gz') ?: [];
    sort($backups);
    foreach (array_slice($backups, 0, max(0, count($backups) - 10)) as $old) {
        @unlink($old);
    }
    return $file;
}
