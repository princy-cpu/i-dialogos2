<?php
/**
 * Notifications de l'espace adhérent : après chaque action (enregistrement, publication, suppression…), un message
 * confirme le résultat sur la page suivante. Le message voyage dans un cookie de courte durée, lu une seule fois.
 */
declare(strict_types=1);

const NOTICE_COOKIE = 'idlg_notice';

/** Message à afficher sur la prochaine page de l'espace adhérent ($type : success ou error). */
function flash(string $message, string $type = 'success', string $link = '', string $linkLabel = ''): void
{
    App::$noticeSet = true;
    $payload = rtrim(strtr(base64_encode((string) json_encode(['m' => mb_cut($message, 300), 't' => $type === 'error' ? 'error' : 'success',
        'l' => str_starts_with($link, '/') && !str_starts_with($link, '//') ? $link : '', 'k' => mb_cut($linkLabel, 60)], JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    header('Set-Cookie: ' . NOTICE_COOKIE . '=' . $payload . '; HttpOnly; SameSite=Lax; Path=/; Max-Age=120' . cookie_secure(), false);
}

/** Notification en attente, sous forme de HTML ; le cookie est effacé pour qu'elle ne s'affiche qu'une fois. */
function take_notice_html(): string
{
    $raw = (string) ($_COOKIE[NOTICE_COOKIE] ?? '');
    if ($raw === '') {
        return '';
    }
    unset($_COOKIE[NOTICE_COOKIE]);
    if (!headers_sent()) {
        header('Set-Cookie: ' . NOTICE_COOKIE . '=; HttpOnly; SameSite=Lax; Path=/; Max-Age=0' . cookie_secure(), false);
    }
    $data = json_decode((string) base64_decode(strtr($raw, '-_', '+/'), true), true);
    if (!is_array($data) || !is_string($data['m'] ?? null) || $data['m'] === '') {
        return '';
    }
    $link = is_string($data['l'] ?? null) && $data['l'] !== '' ? ' <a href="' . esc($data['l']) . '">' . esc((string) ($data['k'] ?? '') ?: 'Voir') . '</a>' : '';
    return notice_html((string) $data['m'], ($data['t'] ?? '') === 'error' ? 'error' : 'success', $link);
}

function notice_html(string $message, string $type, string $linkHtml = ''): string
{
    return '<div class="admin-toast is-' . $type . '" role="' . ($type === 'error' ? 'alert' : 'status') . '" data-toast><span class="admin-toast-icon" aria-hidden="true">' . ($type === 'error' ? '!' : '✓') . '</span>'
        . '<p>' . esc($message) . $linkHtml . '</p><button type="button" class="admin-toast-close" aria-label="Fermer la notification" data-toast-close>×</button></div>';
}

/**
 * Message par défaut quand une action de l'espace adhérent se termine par une redirection sans message explicite.
 * Les erreurs passées dans l'adresse (?erreur=…) deviennent une notification d'erreur.
 */
function default_notice(string $path, string $location): void
{
    if (App::$noticeSet || $location === '' || !str_starts_with($path, '/admin/') || in_array($path, ['/admin/logout', '/admin/login', '/admin/setup'], true)) {
        return;
    }
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    if (is_string($query['erreur'] ?? null) && $query['erreur'] !== '') {
        flash($query['erreur'], 'error');
        return;
    }
    if (isset($query['error'])) {
        return;
    }
    $action = request()->form('action');
    $segments = explode('/', trim($path, '/'));
    $last = end($segments);
    $message = match (true) {
        $path === '/admin/events/save' => 'Actualité enregistrée.',
        $path === '/admin/categories' => 'Catégorie enregistrée.',
        $path === '/admin/categories/delete' => 'Catégorie supprimée.',
        $path === '/admin/tags' => 'Étiquette enregistrée.',
        $path === '/admin/tags/delete' => 'Étiquette supprimée.',
        $path === '/admin/templates' => 'Modèles mis à jour.',
        $path === '/admin/languages' => match ($action) {
            'detect' => 'Langues des articles détectées.',
            'pretranslate' => 'Traductions lancées : elles se préparent en arrière-plan.',
            default => 'Langues mises à jour.',
        },
        $path === '/admin/newsletter/process' => 'File d’envoi de la lettre traitée.',
        $path === '/admin/users/save' => 'Compte enregistré.',
        $path === '/admin/profile' => 'Profil enregistré.',
        $path === '/admin/accueil' => 'Page d’accueil enregistrée : les changements sont en ligne.',
        $path === '/admin/images' => request()->form('reset') === '1' ? 'Image d’origine rétablie.' : 'Image du site remplacée : elle est en ligne.',
        $segments[1] === 'events' && $last === 'delete' => 'Actualité supprimée.',
        $segments[1] === 'articles' && $last === 'delete' => 'Article supprimé.',
        $segments[1] === 'newsletter' && $last === 'delete' => 'Abonné supprimé.',
        $segments[1] === 'users' && $last === 'toggle' => 'Accès du compte modifié.',
        $segments[1] === 'comments' => $action === 'approve' ? 'Commentaire approuvé : il est visible sur le site.' : 'Commentaire supprimé.',
        $last === 'save' => 'Fiche enregistrée.',
        $last === 'delete' => 'Fiche supprimée.',
        default => '',
    };
    if ($message !== '') {
        flash($message);
    }
}
