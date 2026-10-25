<?php
/**
 * Accès à PostgreSQL (PDO) et structure de la base.
 *
 * Les requêtes utilisent « ? » comme marqueur. Deux aides reprennent les habitudes du code d'origine :
 *   - « INSERT OR IGNORE INTO » devient « INSERT … ON CONFLICT DO NOTHING » ;
 *   - « = ANY(?) » avec un tableau PHP devient « IN (?, ?, …) ».
 */
declare(strict_types=1);

final class Db
{
    public PDO $pdo;

    public function __construct(string $url)
    {
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
            throw new RuntimeException('DATABASE_URL doit être une adresse postgresql://…');
        }
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'] ?? 'localhost', $parts['port'] ?? 5432,
            rawurldecode(ltrim($parts['path'] ?? '', '/')));
        parse_str($parts['query'] ?? '', $query);
        if (!empty($query['sslmode'])) {
            $dsn .= ';sslmode=' . $query['sslmode'];
        }
        $this->pdo = new PDO($dsn, rawurldecode($parts['user'] ?? ''), rawurldecode($parts['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $this->pdo->exec("SET client_encoding TO 'UTF8'");
        if (!empty($query['options']) && preg_match('/search_path=([A-Za-z0-9_,]+)/', $query['options'], $m)) {
            $this->pdo->exec('SET search_path TO ' . $m[1]);
        }
    }

    public function execute(string $sql, array $params = []): PDOStatement
    {
        $ignore = stripos($sql, 'INSERT OR IGNORE INTO') !== false;
        if ($ignore) {
            $sql = str_ireplace('INSERT OR IGNORE INTO', 'INSERT INTO', $sql) . ' ON CONFLICT DO NOTHING';
        }
        $values = [];
        $index = 0;
        $sql = preg_replace_callback('/=\s*ANY\(\?\)|\?/', function (array $match) use (&$index, &$values, $params): string {
            $value = $params[$index++] ?? null;
            if ($match[0] === '?') {
                $values[] = $value;
                return '?';
            }
            $items = array_values((array) $value);
            if (!$items) {
                return 'IN (NULL)';
            }
            array_push($values, ...$items);
            return 'IN (' . implode(',', array_fill(0, count($items), '?')) . ')';
        }, $sql);
        $statement = $this->pdo->prepare($sql);
        foreach ($values as $position => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : (is_bool($value) ? PDO::PARAM_BOOL : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
            $statement->bindValue($position + 1, $value, $type);
        }
        $statement->execute();
        return $statement;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->execute($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = [])
    {
        $value = $this->execute($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Exécute $work dans une transaction (tout ou rien). */
    public function transaction(callable $work)
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}

function db(): Db
{
    static $db = null;
    if ($db === null) {
        $url = setting('DATABASE_URL');
        if ($url === '') {
            throw new RuntimeException('Configurez DATABASE_URL (fichier app.env) avec l’adresse de la base PostgreSQL.');
        }
        $db = new Db($url);
    }
    return $db;
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s') . '+00:00';
}

/** Structure initiale : mêmes tables que la version Python (une base existante est réutilisée telle quelle). */
const BASELINE_SCHEMA = [
    'CREATE TABLE IF NOT EXISTS users (id BIGSERIAL PRIMARY KEY, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, password_salt TEXT NOT NULL, created_at TEXT NOT NULL)',
    'CREATE TABLE IF NOT EXISTS languages (code TEXT PRIMARY KEY, label TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, is_default INTEGER NOT NULL DEFAULT 0)',
    'CREATE TABLE IF NOT EXISTS sessions (token_hash TEXT PRIMARY KEY, user_id BIGINT REFERENCES users(id) ON DELETE CASCADE, csrf TEXT NOT NULL, expires_at INTEGER NOT NULL)',
    'CREATE TABLE IF NOT EXISTS categories (id BIGSERIAL PRIMARY KEY, name TEXT UNIQUE NOT NULL, slug TEXT UNIQUE NOT NULL, created_at TEXT NOT NULL)',
    'CREATE TABLE IF NOT EXISTS tags (id BIGSERIAL PRIMARY KEY, name TEXT UNIQUE NOT NULL, slug TEXT UNIQUE NOT NULL, created_at TEXT NOT NULL)',
    "CREATE TABLE IF NOT EXISTS templates (id BIGSERIAL PRIMARY KEY, name TEXT UNIQUE NOT NULL, body_html TEXT NOT NULL DEFAULT '', is_default INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)",
    "CREATE TABLE IF NOT EXISTS articles (id TEXT PRIMARY KEY, title TEXT NOT NULL, slug TEXT UNIQUE NOT NULL, excerpt TEXT NOT NULL DEFAULT '', body_html TEXT NOT NULL DEFAULT '', featured_image TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'draft', author TEXT NOT NULL, template_id BIGINT REFERENCES templates(id) ON DELETE SET NULL, comments_enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, published_at TEXT, language TEXT NOT NULL DEFAULT 'fr')",
    'CREATE TABLE IF NOT EXISTS article_categories (article_id TEXT NOT NULL REFERENCES articles(id) ON DELETE CASCADE, category_id BIGINT NOT NULL REFERENCES categories(id) ON DELETE CASCADE, PRIMARY KEY(article_id, category_id))',
    'CREATE TABLE IF NOT EXISTS article_tags (article_id TEXT NOT NULL REFERENCES articles(id) ON DELETE CASCADE, tag_id BIGINT NOT NULL REFERENCES tags(id) ON DELETE CASCADE, PRIMARY KEY(article_id, tag_id))',
    "CREATE TABLE IF NOT EXISTS comments (id BIGSERIAL PRIMARY KEY, article_id TEXT NOT NULL REFERENCES articles(id) ON DELETE CASCADE, author TEXT NOT NULL, email TEXT NOT NULL DEFAULT '', body TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', created_at TEXT NOT NULL)",
    "CREATE TABLE IF NOT EXISTS news_events (id BIGSERIAL PRIMARY KEY, kind TEXT NOT NULL DEFAULT 'news', title TEXT NOT NULL, slug TEXT UNIQUE NOT NULL, summary TEXT NOT NULL DEFAULT '', body_html TEXT NOT NULL DEFAULT '', image_url TEXT NOT NULL DEFAULT '', location TEXT NOT NULL DEFAULT '', starts_at TEXT NOT NULL DEFAULT '', ends_at TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'draft', created_at TEXT NOT NULL, updated_at TEXT NOT NULL, language TEXT NOT NULL DEFAULT 'fr')",
    'CREATE TABLE IF NOT EXISTS translations (hash TEXT NOT NULL, target TEXT NOT NULL, translated TEXT NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY(hash, target))',
    "ALTER TABLE articles ADD COLUMN IF NOT EXISTS language TEXT NOT NULL DEFAULT 'fr'",
    "ALTER TABLE news_events ADD COLUMN IF NOT EXISTS language TEXT NOT NULL DEFAULT 'fr'",
    "ALTER TABLE articles ADD COLUMN IF NOT EXISTS source_url TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE news_events ADD COLUMN IF NOT EXISTS source_url TEXT NOT NULL DEFAULT ''",
    'CREATE TABLE IF NOT EXISTS schema_version (version INTEGER PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at VARCHAR(40) NOT NULL)',
];

/**
 * Migrations versionnées : à compléter à la suite, avec un numéro croissant. Jamais modifier une migration déjà en ligne.
 * Rester compatible avec la version précédente du code (ajouter plutôt que supprimer), pour garder le retour arrière possible.
 */
const MIGRATIONS = [
    1 => ['Structure initiale', []],
    2 => ['Tentatives de connexion (limitation des essais)', [
        'CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT NOT NULL, attempted_at BIGINT NOT NULL)',
        'CREATE INDEX IF NOT EXISTS login_attempts_ip ON login_attempts (ip, attempted_at)',
    ]],
    3 => ['Invités de la semaine et experts', [
        // kind : « guest » (invité de la semaine, daté par featured_on) ou « expert » (ordonné par position).
        "CREATE TABLE IF NOT EXISTS people (id BIGSERIAL PRIMARY KEY, kind TEXT NOT NULL CHECK (kind IN ('guest','expert')), name TEXT NOT NULL,"
            . " description TEXT NOT NULL DEFAULT '', expertise TEXT NOT NULL DEFAULT '', region TEXT NOT NULL DEFAULT '', photo TEXT NOT NULL DEFAULT '',"
            . " featured_on TEXT NOT NULL DEFAULT '', position INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
        'CREATE INDEX IF NOT EXISTS people_kind ON people (kind, featured_on, position)',
    ]],
];

function latest_migration(): int
{
    return max(array_keys(MIGRATIONS));
}

function schema_version(Db $db): int
{
    try {
        return (int) $db->value('SELECT MAX(version) FROM schema_version');
    } catch (PDOException $error) {
        return 0;
    }
}

/** Crée les tables manquantes et applique les migrations en attente. Renvoie les numéros appliqués. */
function migrate(Db $db): array
{
    // Verrou PostgreSQL : deux requêtes simultanées n'appliquent jamais les migrations deux fois.
    $db->execute('SELECT pg_advisory_lock(?)', [781203]);
    try {
        foreach (BASELINE_SCHEMA as $statement) {
            $db->execute($statement);
        }
        seed_defaults($db);
        $current = schema_version($db);
        $applied = [];
        foreach (MIGRATIONS as $version => [$description, $statements]) {
            if ($version <= $current) {
                continue;
            }
            $db->transaction(function (Db $db) use ($version, $description, $statements): void {
                foreach ($statements as $statement) {
                    $db->execute($statement);
                }
                $db->execute('INSERT INTO schema_version(version,description,applied_at) VALUES(?,?,?)', [$version, $description, now_iso()]);
            });
            $applied[] = $version;
        }
        return $applied;
    } finally {
        $db->execute('SELECT pg_advisory_unlock(?)', [781203]);
    }
}

/** Applique les migrations au premier passage de chaque version (repère dans le dossier temporaire). */
function schema_marker(): string
{
    return rtrim(sys_get_temp_dir(), '/') . '/idialogos-schema-' . md5(setting('DATABASE_URL')) . '-v' . latest_migration();
}

function ensure_schema(Db $db): void
{
    $marker = schema_marker();
    if (is_file($marker)) {
        return;
    }
    if (schema_version($db) < latest_migration()) {
        migrate($db);
    }
    @file_put_contents($marker, now_iso());
}

function seed_defaults(Db $db): void
{
    foreach ([['fr', 'Français', 1], ['en', 'English', 0], ['es', 'Español', 0], ['it', 'Italiano', 0]] as [$code, $label, $default]) {
        $db->execute('INSERT OR IGNORE INTO languages(code,label,enabled,is_default) VALUES(?,?,1,?)', [$code, $label, $default]);
    }
    $defaults = ['IA – Intelligence artificielle', 'Jeunesse', 'Médias et pluralisme', 'Francophonies', 'Démocraties',
        'Ultramarins', 'Eau, climat et environnement', 'Géopolitique', 'Santé et développement'];
    foreach ($defaults as $name) {
        $db->execute('INSERT OR IGNORE INTO categories(name, slug, created_at) VALUES(?,?,?)', [$name, slugify($name), now_iso()]);
    }
    if ((int) $db->value('SELECT COUNT(*) FROM templates') === 0) {
        $skeleton = '<h2>Introduction</h2><p>Présentez le sujet et la question principale.</p><h2>Développement</h2><p>Développez votre analyse et vos arguments.</p><h2>Conclusion</h2><p>Résumez les idées essentielles.</p>';
        $db->execute('INSERT INTO templates(name, body_html, is_default, created_at) VALUES(?,?,1,?)', ['Modèle par défaut', $skeleton, now_iso()]);
    }
}
