<?php
/** Requête, réponse, session, protection contre la falsification de formulaires (jeton CSRF). */
declare(strict_types=1);

const HEAD_ASSETS = '<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 64 64\'%3E%3Crect width=\'64\' height=\'64\' rx=\'14\' fill=\'%231f6b3c\'/%3E%3Ctext x=\'32\' y=\'43\' font-family=\'Arial\' font-weight=\'700\' font-size=\'28\' text-anchor=\'middle\' fill=\'white\'%3EI%C2%B7D%3C/text%3E%3C/svg%3E"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&display=swap" rel="stylesheet">';
const CSP = "default-src 'self'; img-src 'self' https: data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

final class Request
{
    public string $method;
    public string $path;
    public string $rawPath;
    public string $queryString;
    public array $query;
    public array $fields = [];
    public array $multi = [];
    public array $files = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        [$this->rawPath, $this->queryString] = array_pad(explode('?', $uri, 2), 2, '');
        $this->path = rawurldecode($this->rawPath) ?: '/';
        $this->query = self::parseQuery($this->queryString);
    }

    /** Comme parse_qs de Python : chaque clé garde la liste de ses valeurs (sans les crochets « [] »). */
    public static function parseQuery(string $raw): array
    {
        $result = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            $key = str_ends_with($key, '[]') ? substr($key, 0, -2) : $key;
            $result[$key][] = urldecode($value);
        }
        return $result;
    }

    public function q(string $name): string
    {
        $values = $this->query[$name] ?? [];
        return $values ? (string) end($values) : '';
    }

    public function readForm(): void
    {
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_starts_with($type, 'multipart/form-data')) {
            foreach ($_POST as $key => $value) {
                $values = is_array($value) ? array_values(array_map('strval', $value)) : [(string) $value];
                $this->multi[$key] = $values;
                $this->fields[$key] = $values ? end($values) : '';
            }
            $this->files = $_FILES;
        } else {
            $this->multi = self::parseQuery((string) file_get_contents('php://input'));
            foreach ($this->multi as $key => $values) {
                $this->fields[$key] = (string) end($values);
            }
        }
    }

    public function form(string $name, string $default = ''): string
    {
        return $this->fields[$name] ?? $default;
    }

    public function formList(string $name): array
    {
        return $this->multi[$name] ?? [];
    }

    public function cookie(string $name): string
    {
        return (string) ($_COOKIE[$name] ?? '');
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}

final class App
{
    public static Request $request;
    public static ?string $pendingCookie = null;
    public static array $afterResponse = [];
    public static ?array $sessionCache = null;
}

function request(): Request
{
    return App::$request;
}

function send_body(string $body, int $status = 200, string $type = 'text/html; charset=utf-8', array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: ' . $type);
    header('Content-Length: ' . strlen($body));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Content-Security-Policy: ' . CSP);
    foreach ($headers as $name => $value) {
        header("$name: $value");
    }
    if (App::$pendingCookie !== null) {
        header('Set-Cookie: ' . App::$pendingCookie, false);
        App::$pendingCookie = null;
    }
    if (request()->method !== 'HEAD') {
        echo $body;
    }
}

function redirect(string $location, array $cookies = [], int $status = 303): void
{
    http_response_code($status);
    header('Location: ' . $location);
    header('Content-Length: 0');
    if (App::$pendingCookie !== null && !$cookies) {
        $cookies[] = App::$pendingCookie;
    }
    App::$pendingCookie = null;
    foreach ($cookies as $cookie) {
        header('Set-Cookie: ' . $cookie, false);
    }
}

/** Travail à faire après l'envoi de la réponse (préparation des traductions). */
function after_response(callable $task): void
{
    App::$afterResponse[] = $task;
}

function run_after_response(): void
{
    if (!App::$afterResponse) {
        return;
    }
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        @ob_end_flush();
        flush();
    }
    @set_time_limit(120);
    foreach (App::$afterResponse as $task) {
        try {
            $task();
        } catch (Throwable $error) {
            error_log('Tâche différée interrompue : ' . $error->getMessage());
        }
    }
}

function random_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function session_cookie(string $token): string
{
    return COOKIE_NAME . '=' . $token . '; HttpOnly; SameSite=Lax; Path=/; Max-Age=' . SESSION_TTL . cookie_secure();
}

function current_session(bool $create = false): ?array
{
    if (App::$sessionCache !== null) {
        return App::$sessionCache;
    }
    $token = request()->cookie(COOKIE_NAME);
    if ($token !== '') {
        $row = db()->one('SELECT sessions.*, users.username FROM sessions LEFT JOIN users ON users.id=sessions.user_id WHERE token_hash=? AND expires_at>?',
            [hash('sha256', $token), time()]);
        if ($row) {
            return App::$sessionCache = $row;
        }
    }
    if (!$create) {
        return null;
    }
    $token = random_token();
    $csrf = random_token();
    db()->execute('INSERT INTO sessions(token_hash,user_id,csrf,expires_at) VALUES(?,?,?,?)', [hash('sha256', $token), null, $csrf, time() + SESSION_TTL]);
    App::$pendingCookie = session_cookie($token);
    return App::$sessionCache = ['token_hash' => hash('sha256', $token), 'user_id' => null, 'csrf' => $csrf, 'expires_at' => time() + SESSION_TTL, 'username' => null];
}

function auth(): ?array
{
    $session = current_session();
    return ($session && $session['user_id'] && $session['username']) ? $session : null;
}

function csrf_ok(): bool
{
    $session = current_session();
    return (bool) ($session && hash_equals((string) $session['csrf'], request()->form('_csrf')));
}

function csrf_field(): string
{
    $session = current_session(true);
    return '<input type="hidden" name="_csrf" value="' . esc($session['csrf']) . '">';
}

function create_authenticated_session(Db $db, int $userId): string
{
    $token = random_token();
    $db->execute('INSERT INTO sessions(token_hash,user_id,csrf,expires_at) VALUES(?,?,?,?)', [hash('sha256', $token), $userId, random_token(), time() + SESSION_TTL]);
    App::$sessionCache = null;
    return session_cookie($token);
}

/** Mot de passe haché avec PBKDF2-SHA256 (310 000 itérations), compatible avec les comptes créés par la version Python. */
function password_digest(string $password, string $saltHex): string
{
    return hash_pbkdf2('sha256', $password, (string) hex2bin($saltHex), 310000, 64);
}
