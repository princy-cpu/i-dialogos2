<?php
/**
 * Point d'entrée de l'application : chaque requête passe par ici (voir public/index.php et public/.htaccess).
 */
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/html.php';
require __DIR__ . '/db.php';
require __DIR__ . '/translation.php';
require __DIR__ . '/themes.php';
require __DIR__ . '/http.php';
require __DIR__ . '/public_pages.php';
require __DIR__ . '/admin_pages.php';
require __DIR__ . '/ops.php';

mb_internal_encoding('UTF-8');
header_remove('X-Powered-By');
App::$request = new Request();

const STATIC_FILES = ['/style.css', '/app.js', '/admin.css', '/admin.js', '/i18n.js'];
const MIME_TYPES = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'png' => 'image/png', 'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
    'woff2' => 'font/woff2', 'woff' => 'font/woff', 'json' => 'application/json', 'txt' => 'text/plain; charset=utf-8', 'pdf' => 'application/pdf'];

function serve_file(string $file, int $maxAge): void
{
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $etag = '"' . md5($file . filemtime($file) . filesize($file)) . '"';
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        return;
    }
    send_body((string) file_get_contents($file), 200, MIME_TYPES[$extension] ?? 'application/octet-stream',
        ['Cache-Control' => "public, max-age=$maxAge", 'ETag' => $etag]);
}

function not_found_plain(): void
{
    send_body('Introuvable', 404, 'text/plain; charset=utf-8');
}

function static_file(string $path): void
{
    $base = realpath(str_starts_with($path, '/assets/') ? app_root() . '/assets' : app_root());
    $file = realpath(app_root() . '/' . ltrim($path, '/'));
    // realpath() rend des chemins avec « \ » sous Windows : on compare avec le séparateur du système.
    if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
        not_found_plain();
        return;
    }
    serve_file($file, 3600);
}

function upload_file(string $path): void
{
    $name = basename($path);
    $file = upload_dir() . '/' . $name;
    if (!preg_match('/^[a-f0-9-]+\.(?:jpg|jpeg|png|gif|webp)$/D', $name) || !is_file($file)) {
        not_found_plain();
        return;
    }
    serve_file($file, 86400);
}

function healthz(): void
{
    $report = ['status' => 'ok', 'version' => app_version(), 'database' => 'postgresql'];
    // Extensions PHP indispensables (à activer dans l'espace client LWS si l'une manque) ; curl sert à la traduction.
    $missing = array_values(array_filter(['pdo_pgsql', 'mbstring', 'zlib'], fn($name) => !extension_loaded($name)));
    if ($missing || PHP_VERSION_ID < 80000) {
        $report['status'] = 'error';
        $report['error'] = 'PHP ' . PHP_VERSION . ($missing ? ', extensions manquantes : ' . implode(', ', $missing) : ', version 8.0 ou plus requise');
        send_body('{' . implode(', ', array_map(fn($key, $value) => json_encode($key) . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES), array_keys($report), $report)) . '}', 503, 'application/json; charset=utf-8', ['Cache-Control' => 'no-store']);
        return;
    }
    if (!extension_loaded('curl')) {
        $report['warning'] = 'extension curl absente : traduction automatique indisponible';
    }
    try {
        $db = db();
        ensure_schema($db);
        $db->value('SELECT 1');
        if (schema_version($db) < latest_migration()) {
            // Repère périmé (base restaurée ou remplacée) : la structure est remise à niveau.
            @unlink(schema_marker());
            migrate($db);
        }
        $report['schema'] = schema_version($db);
        if ($report['schema'] < latest_migration()) {
            $report['status'] = 'error';
            $report['error'] = 'migrations en attente';
        }
    } catch (Throwable $error) {
        $report['status'] = 'error';
        $report['error'] = get_class($error);
    }
    // Même présentation que la version Python ("clé": valeur), lue par le script de déploiement.
    $json = '{' . implode(', ', array_map(fn($key, $value) => json_encode($key) . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES), array_keys($report), $report)) . '}';
    send_body($json, $report['status'] === 'ok' ? 200 : 503, 'application/json; charset=utf-8', ['Cache-Control' => 'no-store']);
}

function route_get(string $path): void
{
    if ($path === '/healthz') {
        healthz();
        return;
    }
    if (str_starts_with($path, '/assets/') || in_array($path, STATIC_FILES, true)) {
        static_file($path);
        return;
    }
    if (str_starts_with($path, '/uploads/')) {
        upload_file($path);
        return;
    }
    ensure_schema(db());
    if ($path === '/api/languages') {
        $rows = db()->all('SELECT code,label,is_default FROM languages WHERE enabled=1 ORDER BY is_default DESC,label');
        foreach ($rows as &$row) {
            $row['is_default'] = (int) $row['is_default'];
        }
        send_body(json_encode($rows, JSON_UNESCAPED_UNICODE), 200, 'application/json; charset=utf-8');
        return;
    }
    if ($path === '/') {
        public_home();
        return;
    }
    if (str_starts_with($path, '/article/')) {
        public_article(substr($path, 9));
        return;
    }
    if (str_starts_with($path, '/thematique/')) {
        public_theme(substr($path, 12));
        return;
    }
    if ($path === '/actualites') {
        public_news_events();
        return;
    }
    if (str_starts_with($path, '/actualite/')) {
        public_news_event(substr($path, 11));
        return;
    }
    $simple = ['/admin/setup' => 'setup_page', '/admin/login' => 'login_page'];
    if (isset($simple[$path])) {
        $simple[$path]();
        return;
    }
    if ($path === '/admin/logout' || $path === '/admin') {
        $path === '/admin' ? dashboard() : redirect('/admin');
        return;
    }
    if (str_starts_with($path, '/admin')) {
        if (!auth()) {
            redirect('/admin/login');
            return;
        }
        $segments = explode('/', trim($path, '/'));
        $admin = ['/admin/articles' => fn() => dashboard('articles'), '/admin/events' => 'events_page', '/admin/events/new' => fn() => event_form(),
            '/admin/articles/new' => fn() => article_form(), '/admin/categories' => fn() => taxonomy_page('categories'),
            '/admin/tags' => fn() => taxonomy_page('tags'), '/admin/templates' => 'templates_page', '/admin/comments' => 'comments_page',
            '/admin/languages' => 'languages_page'];
        if (isset($admin[$path])) {
            $admin[$path]();
            return;
        }
        if (count($segments) === 4 && $segments[3] === 'edit' && $segments[1] === 'events') {
            event_form($segments[2]);
            return;
        }
        if (count($segments) === 4 && $segments[3] === 'edit' && $segments[1] === 'articles') {
            article_form($segments[2]);
            return;
        }
        send_body(site_page('Page introuvable', '<main class="admin-shell"><h1>Page introuvable</h1><a href="/admin">Retour à l’administration</a></main>'), 404);
        return;
    }
    not_found_public();
}

function route_post(string $path): void
{
    if (str_starts_with($path, '/_ops/')) {
        ops_route($path);
        return;
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_REQUEST) {
        send_body(site_page('Erreur', '<main class="admin-shell"><h1>La requête est trop volumineuse.</h1></main>'), 413);
        return;
    }
    ensure_schema(db());
    request()->readForm();
    $expired = fn(string $back) => send_body(site_page('Session expirée', '<main class="admin-shell"><h1>Session expirée</h1><a href="' . $back . '">Recommencer</a></main>'), 403);
    if ($path === '/admin/setup' || $path === '/admin/login') {
        if (!csrf_ok()) {
            $expired('/admin/login');
            return;
        }
        $path === '/admin/setup' ? setup_post() : login_post();
        return;
    }
    if (str_starts_with($path, '/article/') && str_ends_with($path, '/comment')) {
        if (!csrf_ok()) {
            $expired('/');
            return;
        }
        comment_post(explode('/', trim($path, '/'))[1] ?? '');
        return;
    }
    if (!auth()) {
        redirect('/admin/login');
        return;
    }
    if (!csrf_ok()) {
        send_body(admin_page('Session expirée', '<main class="admin-main"><h1>Session expirée</h1><a href="/admin">Recommencer</a></main>'), 403);
        return;
    }
    $segments = explode('/', trim($path, '/'));
    $routes = ['/admin/logout' => 'logout', '/admin/articles/save' => 'save_article', '/admin/events/save' => 'save_event',
        '/admin/categories' => fn() => taxonomy_post('categories', false), '/admin/categories/delete' => fn() => taxonomy_post('categories', true),
        '/admin/tags' => fn() => taxonomy_post('tags', false), '/admin/tags/delete' => fn() => taxonomy_post('tags', true),
        '/admin/templates' => 'templates_post', '/admin/languages' => 'languages_post'];
    if (isset($routes[$path])) {
        $routes[$path]();
    } elseif (count($segments) === 4 && $segments[1] === 'events' && $segments[3] === 'delete') {
        delete_event($segments[2]);
    } elseif (count($segments) === 4 && $segments[1] === 'articles' && $segments[3] === 'delete') {
        delete_article($segments[2]);
    } elseif (count($segments) === 3 && $segments[1] === 'comments') {
        comment_action($segments[2]);
    } else {
        redirect('/admin');
    }
}

function handle_request(): void
{
    $request = request();
    try {
        if ($request->method === 'POST') {
            route_post($request->path);
        } elseif (in_array($request->method, ['GET', 'HEAD'], true)) {
            route_get($request->path);
        } else {
            send_body('Méthode non autorisée', 405, 'text/plain; charset=utf-8', ['Allow' => 'GET, HEAD, POST']);
        }
    } catch (PDOException $error) {
        // Table absente (base remplacée ou restaurée pendant que le site tournait) : on recrée la structure, une seule fois.
        if (($error->errorInfo[0] ?? '') === '42P01' && !headers_sent()) {
            error_log('I-Dialogos : table absente, structure recréée');
            @unlink(schema_marker());
            migrate(db());
            send_body(site_page('Mise à jour', '<main class="auth-wrap"><section class="auth-card"><h1>Mise à jour terminée</h1><p>Rechargez la page.</p></section></main>'), 503, 'text/html; charset=utf-8', ['Retry-After' => '1']);
        } else {
            report_error($error);
        }
    } catch (Throwable $error) {
        report_error($error);
    }
    run_after_response();
}

function report_error(Throwable $error): void
{
    error_log('I-Dialogos : ' . $error);
    if (!headers_sent()) {
        header_remove();
        $detail = setting('APP_DEBUG') === '1' ? '<pre>' . esc((string) $error) . '</pre>' : '';
        send_body(site_page('Erreur', '<main class="auth-wrap"><section class="auth-card"><h1>Le site rencontre un problème</h1><p>Réessayez dans quelques instants.</p>' . $detail . '</section></main>'), 500);
    }
}

handle_request();
