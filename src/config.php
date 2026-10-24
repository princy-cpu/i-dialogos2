<?php
/**
 * Configuration : chemins et réglages lus dans l'environnement ou dans le fichier app.env.
 *
 * Emplacement du fichier de réglages, dans l'ordre :
 *   1. variable d'environnement APP_ENV_FILE ;
 *   2. sur le serveur : htdocs/.app/shared/app.env (la version en ligne est dans htdocs/.app/releases/<version>/) ;
 *   3. en local : app.env à la racine du projet (exclu de Git).
 * Une variable déjà définie dans l'environnement est prioritaire sur le fichier.
 */
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

function app_root(): string
{
    static $root = null;
    return $root ??= realpath(APP_ROOT) ?: APP_ROOT;
}

/** Dossier « .app » du serveur si l'application tourne depuis htdocs/.app/releases/<version>, sinon null. */
function app_base(): ?string
{
    $releases = dirname(app_root());
    return basename($releases) === 'releases' ? dirname($releases) : null;
}

function parse_env_file(string $text): array
{
    $values = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $raw) {
        $line = trim($raw);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (str_starts_with($name, 'export ')) {
            $name = trim(substr($name, 7));
        }
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        $values[$name] = $value;
    }
    return $values;
}

function load_settings(): array
{
    $file = getenv('APP_ENV_FILE') ?: (app_base() !== null ? app_base() . '/shared/app.env' : app_root() . '/app.env');
    $settings = is_file($file) ? parse_env_file((string) file_get_contents($file)) : [];
    foreach (['DATABASE_URL', 'COOKIE_SECURE', 'DEEPL_API_KEY', 'LIBRETRANSLATE_URL', 'LIBRETRANSLATE_API_KEY',
                 'TRANSLATION_PROVIDER', 'TRANSLATION_TIMEOUT', 'UPLOAD_DIR', 'DEPLOY_TOKEN', 'APP_DEBUG'] as $name) {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            $settings[$name] = $value;
        }
    }
    return $settings;
}

function setting(string $name, string $default = ''): string
{
    static $settings = null;
    $settings ??= load_settings();
    return trim((string) ($settings[$name] ?? $default));
}

/** Images téléversées : htdocs/uploads sur le serveur (servies directement par Apache), uploads/ en local. */
function upload_dir(): string
{
    $custom = setting('UPLOAD_DIR');
    if ($custom !== '') {
        return rtrim($custom, '/');
    }
    return app_base() !== null ? dirname(app_base()) . '/uploads' : app_root() . '/uploads';
}

function app_version(): string
{
    $file = app_root() . '/VERSION';
    return is_file($file) ? trim((string) file_get_contents($file)) : 'dev';
}

const COOKIE_NAME = 'idialogos_session';
const SESSION_TTL = 12 * 60 * 60;
const MAX_REQUEST = 12 * 1024 * 1024;

function cookie_secure(): string
{
    return setting('COOKIE_SECURE') === '1' ? '; Secure' : '';
}
