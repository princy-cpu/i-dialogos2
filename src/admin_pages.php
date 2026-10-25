<?php
/** Administration : compte, tableau de bord, articles, actualités, classement, modèles, commentaires, langues. */
declare(strict_types=1);

const ADMIN_PAGE_SIZE = 20;

/** Page demandée (?page=) d'une liste de l'administration. */
function admin_list_page(): int
{
    return requested_page();
}

/** Pagination d'une liste de l'administration (même composant que les listes publiques). */
function admin_pagination(string $base, array $filters, int $page, int $total): string
{
    $pages = max(1, (int) ceil($total / ADMIN_PAGE_SIZE));
    return pagination_nav($base, $filters, min($page, $pages), $pages);
}

function admin_page(string $title, string $content, string $active = ''): string
{
    $session = auth();
    $user = $session ? esc($session['username']) : '';
    $links = [['Accueil', '/admin', 'home'], ['Articles', '/admin/articles', 'articles'], ['Actualités et événements', '/admin/events', 'events'],
        ['Catégories', '/admin/categories', 'categories'], ['Étiquettes', '/admin/tags', 'tags'], ['Modèles', '/admin/templates', 'templates'],
        ['Commentaires', '/admin/comments', 'comments'], ['Invités de la semaine', '/admin/invites', 'invites'], ['Experts', '/admin/experts', 'experts'], ['Newsletter', '/admin/newsletter', 'newsletter'],
        ['Langues', '/admin/languages', 'languages']];
    $nav = '';
    foreach ($links as [$label, $href, $key]) {
        $nav .= '<a class="' . ($key === $active ? 'active' : '') . '" href="' . $href . '">' . $label . '</a>';
    }
    $logout = '<form method="post" action="/admin/logout">' . csrf_field() . '<button class="nav-logout">Déconnexion</button></form>';
    $shell = '<header class="admin-topbar"><a class="admin-brand" href="/">I<span>·</span>Dialogos <small>Administration</small></a><div><a href="/" class="view-site">Voir le site ↗</a><span class="admin-user">' . $user . '</span>' . $logout . '</div></header><div class="admin-layout"><aside class="admin-sidebar"><span class="sidebar-label">ESPACE ÉDITORIAL</span>' . $nav . '</aside>' . $content . '</div>';
    return site_page($title, $shell);
}

function any_user(): bool
{
    return (bool) db()->one('SELECT 1 FROM users LIMIT 1');
}

function setup_page(): void
{
    if (any_user()) {
        redirect('/admin/login');
        return;
    }
    $body = '<main class="auth-wrap"><a class="admin-brand" href="/">I<span>·</span>Dialogos</a><section class="auth-card"><span class="eyebrow">Première configuration</span><h1>Créer le compte administrateur</h1><p>Ce compte sera le seul à pouvoir administrer le site. Choisissez un mot de passe long et unique.</p><form method="post" action="/admin/setup">' . csrf_field() . '<label>Nom d’utilisateur<input name="username" required minlength="3" maxlength="60" autocomplete="username"></label><label>Mot de passe<input type="password" name="password" required minlength="12" autocomplete="new-password"><small>12 caractères minimum</small></label><label>Confirmer le mot de passe<input type="password" name="confirm" required minlength="12" autocomplete="new-password"></label><button class="primary-button">Créer le compte</button></form></section></main>';
    send_body(site_page('Configuration administrateur', $body));
}

function setup_post(): void
{
    $username = trim(request()->form('username'));
    $password = request()->form('password');
    if (mb_strlen($username) < 3 || mb_strlen($password) < 12 || $password !== request()->form('confirm')) {
        send_body(site_page('Configuration', '<main class="auth-wrap"><div class="auth-card"><h1>Vérifiez vos informations</h1><p>Le nom doit contenir 3 caractères et le mot de passe au moins 12 caractères. Les deux mots de passe doivent correspondre.</p><a href="/admin/setup">Retour</a></div></main>'), 400);
        return;
    }
    $salt = bin2hex(random_bytes(16));
    $digest = password_digest($password, $salt);
    // La création n'est possible que tant qu'aucun compte n'existe (verrou : deux envois simultanés ne créent pas deux comptes).
    $cookie = db()->transaction(function (Db $db) use ($username, $digest, $salt): ?string {
        $db->execute('LOCK TABLE users IN EXCLUSIVE MODE');
        if ($db->one('SELECT 1 FROM users LIMIT 1')) {
            return null;
        }
        $db->execute('INSERT INTO users(username,password_hash,password_salt,created_at) VALUES(?,?,?,?)', [$username, $digest, $salt, now_iso()]);
        $userId = (int) $db->value('SELECT id FROM users WHERE username=?', [$username]);
        return create_authenticated_session($db, $userId);
    });
    if ($cookie === null) {
        send_body(site_page('Configuration', '<main class="auth-wrap"><div class="auth-card"><h1>Un compte existe déjà</h1><a href="/admin/login">Se connecter</a></div></main>'), 409);
        return;
    }
    redirect('/admin', [$cookie]);
}

function login_page(): void
{
    if (!any_user()) {
        redirect('/admin/setup');
        return;
    }
    $error = str_contains(request()->queryString, 'error=1') ? '<p class="form-error">Nom d’utilisateur ou mot de passe incorrect.</p>' : '';
    $body = '<main class="auth-wrap"><a class="admin-brand" href="/">I<span>·</span>Dialogos</a><section class="auth-card"><span class="eyebrow">Administration</span><h1>Connexion</h1><p>Connectez-vous pour gérer les articles et les contenus du site.</p>' . $error . '<form method="post" action="/admin/login">' . csrf_field() . '<label>Nom d’utilisateur<input name="username" required autocomplete="username"></label><label>Mot de passe<input type="password" name="password" required autocomplete="current-password"></label><button class="primary-button">Se connecter</button></form><a class="back-public" href="/">← Retour au site</a></section></main>';
    send_body(site_page('Connexion administrateur', $body));
}

function login_post(): void
{
    $db = db();
    $ip = request()->ip();
    $db->execute('DELETE FROM login_attempts WHERE attempted_at < ?', [time() - 900]);
    if ((int) $db->value('SELECT COUNT(*) FROM login_attempts WHERE ip=?', [$ip]) >= 8) {
        send_body(site_page('Trop de tentatives', '<main class="auth-wrap"><section class="auth-card"><h1>Connexion temporairement bloquée</h1><p>Réessayez dans quelques minutes.</p></section></main>'), 429);
        return;
    }
    $user = $db->one('SELECT * FROM users WHERE username=?', [trim(request()->form('username'))]);
    if ($user && hash_equals((string) $user['password_hash'], password_digest(request()->form('password'), (string) $user['password_salt']))) {
        $db->execute('DELETE FROM login_attempts WHERE ip=?', [$ip]);
        $db->execute('DELETE FROM sessions WHERE expires_at<=?', [time()]);
        redirect('/admin', [create_authenticated_session($db, (int) $user['id'])]);
        return;
    }
    $db->execute('INSERT INTO login_attempts(ip, attempted_at) VALUES(?,?)', [$ip, time()]);
    redirect('/admin/login?error=1');
}

function logout(): void
{
    $token = request()->cookie(COOKIE_NAME);
    if ($token !== '') {
        db()->execute('DELETE FROM sessions WHERE token_hash=?', [hash('sha256', $token)]);
    }
    redirect('/', [COOKIE_NAME . '=; HttpOnly; SameSite=Lax; Path=/; Max-Age=0' . cookie_secure()]);
}

function admin_article_row(array $article): string
{
    [$cats, $tags] = article_taxonomy(db(), $article['id']);
    $taxonomy = implode(', ', array_merge(array_column($cats, 'name'), array_map(fn($row) => '#' . $row['name'], $tags)));
    $status = $article['status'] === 'published' ? 'Publié' : 'Brouillon';
    $view = $article['status'] === 'published' ? '<a href="/article/' . q($article['slug']) . '" target="_blank">Voir</a>' : '';
    $search = mb_strtolower($article['title'] . ' ' . $article['author'] . ' ' . $taxonomy, 'UTF-8');
    return '<tr data-search="' . esc($search) . '"><td><strong>' . esc($article['title']) . '</strong><small><code>' . esc(strtoupper($article['language'])) . '</code> ' . esc($taxonomy) . '</small></td><td><span class="status-badge ' . $article['status'] . '">' . $status . '</span></td><td>' . esc($article['author']) . '</td><td>' . esc($article['template_name'] ?: '—') . '</td><td>' . esc(substr($article['updated_at'], 0, 10)) . '</td><td class="table-actions"><a href="/admin/articles/' . q($article['id']) . '/edit">Modifier</a>' . $view . '<form method="post" action="/admin/articles/' . q($article['id']) . '/delete" onsubmit="return confirm(\'Supprimer cet article ?\')">' . csrf_field() . '<button>Supprimer</button></form></td></tr>';
}

function dashboard(string $active = 'home'): void
{
    $session = auth();
    if (!$session) {
        redirect(any_user() ? '/admin/login' : '/admin/setup');
        return;
    }
    $db = db();
    $articles = $db->all('SELECT articles.*, templates.name AS template_name FROM articles LEFT JOIN templates ON templates.id=articles.template_id ORDER BY updated_at DESC');
    $published = (int) $db->value("SELECT COUNT(*) FROM articles WHERE status='published'");
    $drafts = (int) $db->value("SELECT COUNT(*) FROM articles WHERE status='draft'");
    $pending = (int) $db->value("SELECT COUNT(*) FROM comments WHERE status='pending'");
    $rows = implode('', array_map('admin_article_row', $articles)) ?: '<div class="admin-empty">Aucun article. Créez votre première publication.</div>';
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Bonjour ' . esc($session['username']) . '</span><h1>Tableau de bord</h1><p>Gérez vos articles et leur publication.</p></div><a class="primary-button" href="/admin/articles/new">+ Nouvel article</a></div><div class="admin-stats"><div><strong>' . $published . '</strong><span>Publiés</span></div><div><strong>' . $drafts . '</strong><span>Brouillons</span></div><div><strong>' . $pending . '</strong><span>Commentaires à modérer</span></div><div><strong>' . count($articles) . '</strong><span>Total articles</span></div></div><section class="admin-panel"><div class="panel-heading"><h2>Articles</h2><input type="search" id="admin-filter" placeholder="Filtrer les articles"></div><div class="admin-table-wrap"><table><thead><tr><th>Titre</th><th>État</th><th>Auteur</th><th>Modèle</th><th>Dernière modification</th><th>Actions</th></tr></thead><tbody id="admin-articles">' . $rows . '</tbody></table></div></section></main>';
    send_body(admin_page('Tableau de bord', $content, $active));
}

function events_page(): void
{
    $rows = '';
    foreach (db()->all('SELECT * FROM news_events ORDER BY updated_at DESC') as $item) {
        $view = $item['status'] === 'published' ? '<a href="/actualite/' . q($item['slug']) . '" target="_blank">Voir</a>' : '';
        $rows .= '<tr><td><strong>' . esc($item['title']) . '</strong><small>' . ($item['kind'] === 'event' ? 'Événement' : 'Actualité') . ' · ' . esc($item['starts_at'] ?: 'Sans date') . '</small></td><td><span class="status-badge ' . $item['status'] . '">' . ($item['status'] === 'published' ? 'Publié' : 'Brouillon') . '</span></td><td>' . esc($item['location'] ?: '—') . '</td><td class="table-actions"><a href="/admin/events/' . $item['id'] . '/edit">Modifier</a>' . $view . '<form method="post" action="/admin/events/' . $item['id'] . '/delete" onsubmit="return confirm(\'Supprimer ce contenu ?\')">' . csrf_field() . '<button>Supprimer</button></form></td></tr>';
    }
    $rows = $rows ?: '<tr><td colspan="4">Aucune actualité ni aucun événement.</td></tr>';
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Publication</span><h1>Actualités et événements</h1><p>Publiez les nouvelles et rendez-vous d’I-Dialogos.</p></div><a class="primary-button" href="/admin/events/new">+ Nouveau contenu</a></div><section class="admin-panel"><div class="admin-table-wrap"><table><thead><tr><th>Titre</th><th>État</th><th>Lieu</th><th>Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section></main>';
    send_body(admin_page('Actualités et événements', $content, 'events'));
}

function languages_page(): void
{
    $db = db();
    $rows = $db->all('SELECT * FROM languages ORDER BY is_default DESC,label');
    $cached = (int) $db->value('SELECT COUNT(*) FROM translations');
    $perLanguage = $db->all("SELECT language, COUNT(*) AS total FROM (SELECT language FROM articles WHERE status='published' UNION ALL SELECT language FROM news_events WHERE status='published') AS content GROUP BY language ORDER BY total DESC");
    $flash = '';
    if (request()->q('detected') !== '') {
        $flash = '<p class="success-message">Détection terminée : ' . esc(request()->q('detected')) . ' contenu(s) mis à jour.</p>';
    } elseif (request()->q('translating') !== '') {
        $flash = '<p class="success-message">La traduction des contenus publiés a démarré en arrière-plan. Les pages traduites seront ensuite affichées instantanément.</p>';
    }
    $sources = implode(', ', array_map(fn($row) => esc(strtoupper($row['language'])) . ' : ' . $row['total'], $perLanguage)) ?: 'aucun contenu publié';
    $panel = '<section class="admin-panel"><h2>Traduction automatique des publications</h2><p>Chaque article et chaque actualité est affiché dans la langue choisie par le visiteur. Sa langue d’origine est détectée automatiquement, puis le titre, le résumé, le contenu, les catégories et les étiquettes sont traduits et mis en cache.</p><p class="field-help">Service utilisé : <strong>' . esc(provider_name()) . '</strong> · ' . $cached . ' segment(s) traduit(s) en cache · Langues d’origine des contenus publiés : ' . $sources . '.</p><div class="side-actions"><form method="post" action="/admin/languages">' . csrf_field() . '<input type="hidden" name="action" value="detect"><button class="plain-button">Détecter la langue des contenus existants</button></form><form method="post" action="/admin/languages">' . csrf_field() . '<input type="hidden" name="action" value="pretranslate"><button class="primary-button">Traduire tous les contenus maintenant</button></form></div></section>';
    $items = '';
    foreach ($rows as $row) {
        $items .= '<tr><td><strong>' . esc($row['label']) . '</strong></td><td><code>' . esc(strtoupper($row['code'])) . '</code></td><td>' . ((int) $row['is_default'] ? 'Langue par défaut' : '') . '</td><td><form method="post" action="/admin/languages">' . csrf_field() . '<input type="hidden" name="code" value="' . esc($row['code']) . '"><input type="hidden" name="action" value="' . ((int) $row['enabled'] ? 'disable' : 'enable') . '"><button class="plain-button">' . ((int) $row['enabled'] ? 'Désactiver' : 'Activer') . '</button></form></td><td><form method="post" action="/admin/languages">' . csrf_field() . '<input type="hidden" name="code" value="' . esc($row['code']) . '"><input type="hidden" name="action" value="default"><button class="plain-button" ' . (((int) $row['is_default'] || !(int) $row['enabled']) ? 'disabled' : '') . '>Définir par défaut</button></form></td></tr>';
    }
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Configuration du site</span><h1>Langues</h1><p>Activez les langues proposées aux visiteurs et choisissez la langue par défaut.</p></div></div><section class="admin-panel"><div class="admin-table-wrap"><table><thead><tr><th>Langue</th><th>Code</th><th>Par défaut</th><th>Disponibilité</th><th></th></tr></thead><tbody>' . $items . '</tbody></table></div></section>' . $flash . $panel . '</main>';
    send_body(admin_page('Gestion des langues', $content, 'languages'));
}

function languages_post(): void
{
    $code = strtolower(trim(request()->form('code')));
    $action = request()->form('action');
    if ($action === 'detect') {
        redirect('/admin/languages?detected=' . detect_existing_languages());
        return;
    }
    if ($action === 'pretranslate') {
        after_response(fn() => pretranslate());
        redirect('/admin/languages?translating=1');
        return;
    }
    $db = db();
    $language = $db->one('SELECT * FROM languages WHERE code=?', [$code]);
    if ($language) {
        if ($action === 'default' && (int) $language['enabled']) {
            $db->transaction(function (Db $db) use ($code): void {
                $db->execute('UPDATE languages SET is_default=0');
                $db->execute('UPDATE languages SET is_default=1 WHERE code=?', [$code]);
            });
        } elseif ($action === 'enable') {
            $db->execute('UPDATE languages SET enabled=1 WHERE code=?', [$code]);
        } elseif ($action === 'disable' && !(int) $language['is_default']) {
            $db->execute('UPDATE languages SET enabled=0 WHERE code=?', [$code]);
        }
    }
    redirect('/admin/languages');
}

function language_options(array $languages, string $current): string
{
    $selected = in_array($current, array_column($languages, 'code'), true) ? $current : 'auto';
    $out = '<option value="auto" ' . ($selected === 'auto' ? 'selected' : '') . '>Détection automatique</option>';
    foreach ($languages as $row) {
        $out .= '<option value="' . esc($row['code']) . '" ' . ($row['code'] === $selected ? 'selected' : '') . '>' . esc($row['label']) . '</option>';
    }
    return $out;
}

function translations_panel(array $languages, ?array $article): string
{
    if (!$article) {
        return '<section class="side-panel"><h2>Traductions</h2><p class="field-help">Après publication, l’article sera traduit automatiquement dans chaque langue active du site.</p></section>';
    }
    $items = '';
    foreach ($languages as $row) {
        if (!(int) $row['enabled']) {
            continue;
        }
        if ($row['code'] === $article['language']) {
            $state = '<span class="default-mark">Langue d’origine</span>';
        } elseif ($article['status'] === 'published') {
            $state = '<a href="/article/' . q($article['slug']) . '?lang=' . q($row['code']) . '" target="_blank">Aperçu traduit ↗</a>';
        } else {
            $state = '<small>Disponible après publication</small>';
        }
        $items .= '<li><strong>' . esc($row['label']) . '</strong> ' . $state . '</li>';
    }
    return '<section class="side-panel"><h2>Traductions</h2><ul class="translation-list">' . $items . '</ul><small class="field-help">Les traductions sont mises à jour automatiquement à chaque modification.</small></section>';
}

/** Recalcule la langue d'origine de tous les articles et actualités à partir de leur texte. */
function detect_existing_languages(): int
{
    $db = db();
    $changed = 0;
    foreach ([['articles', 'excerpt'], ['news_events', 'summary']] as [$table, $summary]) {
        foreach ($db->all("SELECT id,title,$summary AS summary,body_html,language FROM $table") as $row) {
            $detected = detect_language(implode("\n", [$row['title'], $row['summary'], html_to_text($row['body_html'])]));
            if ($detected && $detected !== $row['language']) {
                $db->execute("UPDATE $table SET language=? WHERE id=?", [$detected, $row['id']]);
                $changed++;
            }
        }
    }
    return $changed;
}

function selected_attr(bool $condition): string
{
    return $condition ? 'selected' : '';
}

function event_form(?string $eventId = null, string $error = ''): void
{
    $db = db();
    $languages = $db->all('SELECT code,label,is_default,enabled FROM languages ORDER BY is_default DESC,label');
    $item = null;
    if ($eventId !== null) {
        if (!ctype_digit($eventId)) {
            redirect('/admin/events');
            return;
        }
        $item = $db->one('SELECT * FROM news_events WHERE id=?', [(int) $eventId]);
        if (!$item) {
            redirect('/admin/events');
            return;
        }
    }
    $get = fn(string $key, string $default = '') => $item ? (string) $item[$key] : $default;
    $errorHtml = $error !== '' ? '<div class="form-error">' . esc($error) . '</div>' : '';
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">' . ($item ? 'Modifier' : 'Nouveau') . '</span><h1>' . ($item ? 'Modifier le contenu' : 'Créer une actualité ou un événement') . '</h1></div><a class="plain-button" href="/admin/events">← Retour</a></div>' . $errorHtml
        . '<section class="admin-panel"><form method="post" action="/admin/events/save" class="event-admin-form">' . csrf_field() . '<input type="hidden" name="id" value="' . esc($get('id')) . '">'
        . '<label class="admin-field">Langue d’origine<select name="language">' . language_options($languages, $item ? $item['language'] : '') . '</select><small class="field-help">Les visiteurs des autres langues voient une traduction automatique.</small></label>'
        . '<label class="admin-field">Type<select name="kind"><option value="news" ' . selected_attr($get('kind', 'news') === 'news') . '>Actualité</option><option value="event" ' . selected_attr($get('kind') === 'event') . '>Événement</option></select></label>'
        . '<label class="admin-field">Titre<input name="title" required maxlength="180" value="' . esc($get('title')) . '"></label>'
        . '<label class="admin-field">Résumé<textarea name="summary" rows="3" maxlength="500">' . esc($get('summary')) . '</textarea></label>'
        . '<label class="admin-field">Contenu<textarea name="body" rows="10" placeholder="Détails de l’actualité ou de l’événement">' . esc(preg_replace('/<[^>]*>/', '', $get('body_html'))) . '</textarea></label>'
        . '<label class="admin-field">Image (URL https:// ou image déjà téléversée)<input name="image_url" type="url" value="' . esc($get('image_url')) . '" placeholder="https://..."></label>'
        . '<label class="admin-field">Lieu<input name="location" maxlength="180" value="' . esc($get('location')) . '"></label>'
        . '<div class="event-date-fields"><label class="admin-field">Date de début<input name="starts_at" type="date" value="' . esc($get('starts_at')) . '"></label><label class="admin-field">Date de fin<input name="ends_at" type="date" value="' . esc($get('ends_at')) . '"></label></div>'
        . '<label class="admin-field">État<select name="status"><option value="draft" ' . selected_attr($get('status', 'draft') === 'draft') . '>Brouillon</option><option value="published" ' . selected_attr($get('status') === 'published') . '>Publié</option></select></label>'
        . '<div class="side-actions"><button class="primary-button" type="submit">Enregistrer</button><a class="plain-button" href="/admin/events">Annuler</a></div></form></section></main>';
    send_body(admin_page('Éditeur actualités et événements', $content, 'events'));
}

function save_event(): void
{
    $r = request();
    $title = mb_cut(trim($r->form('title')), 180);
    if ($title === '') {
        event_form(null, 'Le titre est obligatoire.');
        return;
    }
    $rawId = trim($r->form('id'));
    if ($rawId !== '' && !ctype_digit($rawId)) {
        redirect('/admin/events');
        return;
    }
    $eventId = $rawId !== '' ? (int) $rawId : null;
    $kind = $r->form('kind') === 'event' ? 'event' : 'news';
    $status = $r->form('status') === 'published' ? 'published' : 'draft';
    $imageUrl = mb_cut(trim($r->form('image_url')), 1000);
    if ($imageUrl !== '' && !str_starts_with($imageUrl, 'https://') && !str_starts_with($imageUrl, '/uploads/')) {
        $imageUrl = '';
    }
    $startsAt = substr(trim($r->form('starts_at')), 0, 10);
    $endsAt = substr(trim($r->form('ends_at')), 0, 10);
    $location = mb_cut(trim($r->form('location')), 180);
    $summary = mb_cut(trim($r->form('summary')), 500);
    $paragraphs = '';
    foreach (preg_split('/\r\n|\r|\n/', $r->form('body')) as $line) {
        if (trim($line) !== '') {
            $paragraphs .= '<p>' . $line . '</p>';
        }
    }
    $bodyHtml = safe_rich_html($paragraphs);
    $now = now_iso();
    $db = db();
    $savedId = $db->transaction(function (Db $db) use ($eventId, $title, $kind, $status, $imageUrl, $startsAt, $endsAt, $location, $summary, $bodyHtml, $now, $r): ?int {
        $existing = $eventId ? $db->one('SELECT * FROM news_events WHERE id=?', [$eventId]) : null;
        if ($eventId && !$existing) {
            return null;
        }
        $root = slugify($title);
        $slug = $root;
        $suffix = 2;
        while ($db->one('SELECT id FROM news_events WHERE slug=? AND id<>?', [$slug, $eventId ?? 0])) {
            $slug = $root . '-' . $suffix++;
        }
        $language = resolve_language($db, $r->form('language', 'auto'), $title, $summary, html_to_text($bodyHtml));
        $values = [$kind, $title, $slug, $summary, $bodyHtml, $imageUrl, $location, $startsAt, $endsAt, $status];
        if ($existing) {
            $db->execute('UPDATE news_events SET kind=?,title=?,slug=?,summary=?,body_html=?,image_url=?,location=?,starts_at=?,ends_at=?,status=?,updated_at=?,language=? WHERE id=?', [...$values, $now, $language, $eventId]);
            return $eventId;
        }
        return (int) $db->value('INSERT INTO news_events(kind,title,slug,summary,body_html,image_url,location,starts_at,ends_at,status,created_at,updated_at,language) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id', [...$values, $now, $now, $language]);
    });
    if ($savedId !== null && $status === 'published') {
        after_response(fn() => pretranslate([], [$savedId]));
    }
    redirect('/admin/events');
}

function delete_event(string $eventId): void
{
    if (ctype_digit($eventId)) {
        db()->execute('DELETE FROM news_events WHERE id=?', [(int) $eventId]);
    }
    redirect('/admin/events');
}

function article_form(?string $articleId = null, string $error = ''): void
{
    $session = auth();
    $db = db();
    $article = $articleId ? $db->one('SELECT * FROM articles WHERE id=?', [$articleId]) : null;
    if ($articleId && !$article) {
        redirect('/admin');
        return;
    }
    $categories = $db->all('SELECT * FROM categories ORDER BY name');
    $tags = $db->all('SELECT * FROM tags ORDER BY name');
    $templates = $db->all('SELECT * FROM templates ORDER BY is_default DESC,name');
    $languages = $db->all('SELECT code,label,is_default,enabled FROM languages ORDER BY is_default DESC,label');
    [$chosenCats, $chosenTags] = $article ? article_taxonomy($db, $article['id']) : [[], []];
    $checkedCats = array_map('intval', array_column($chosenCats, 'id'));
    $checkedTags = array_map('intval', array_column($chosenTags, 'id'));
    $catOptions = '';
    foreach ($categories as $row) {
        $catOptions .= '<label class="check-option"><input type="checkbox" name="categories[]" value="' . $row['id'] . '" ' . (in_array((int) $row['id'], $checkedCats, true) ? 'checked' : '') . '>' . esc($row['name']) . '</label>';
    }
    $catOptions = $catOptions ?: '<p>Aucune catégorie. <a href="/admin/categories">En ajouter une</a>.</p>';
    $tagOptions = '';
    foreach ($tags as $row) {
        $tagOptions .= '<label class="check-option"><input type="checkbox" name="tags[]" value="' . $row['id'] . '" ' . (in_array((int) $row['id'], $checkedTags, true) ? 'checked' : '') . '>#' . esc($row['name']) . '</label>';
    }
    $templateOptions = '';
    foreach ($templates as $row) {
        $isSelected = ($article && (int) $article['template_id'] === (int) $row['id']) || (!$article && (int) $row['is_default']);
        $templateOptions .= '<option value="' . $row['id'] . '" data-body="' . esc($row['body_html']) . '" ' . ($isSelected ? 'selected' : '') . '>' . esc($row['name']) . ((int) $row['is_default'] ? ' · modèle par défaut' : '') . '</option>';
    }
    $status = $article['status'] ?? 'draft';
    $bodyHtml = $article ? $article['body_html'] : ($templates[0]['body_html'] ?? '');
    $title = $article['title'] ?? '';
    $image = $article['featured_image'] ?? '';
    $commentsEnabled = $article ? (int) $article['comments_enabled'] : 1;
    $errorHtml = $error !== '' ? '<div class="form-error">' . esc($error) . '</div>' : '';
    $imageHtml = $image ? '<img class="featured-preview" src="' . esc($image) . '" alt="Image actuelle">' : '<div class="image-placeholder">Aucune image sélectionnée</div>';
    $toolbar = '<div class="rich-toolbar" role="toolbar" aria-label="Mise en forme du texte"><button type="button" data-command="bold" title="Gras"><b>G</b></button><button type="button" data-command="italic" title="Italique"><i>I</i></button><button type="button" data-command="underline" title="Souligné"><u>S</u></button><button type="button" data-command="strikeThrough" title="Barré"><s>ab</s></button><span></span><select id="font-size" aria-label="Taille de police"><option value="3">Taille normale</option><option value="2">Petite</option><option value="4">Grande</option><option value="5">Très grande</option><option value="6">Titre</option></select><select id="block-format" aria-label="Style de paragraphe"><option value="p">Paragraphe</option><option value="h2">Titre 2</option><option value="h3">Titre 3</option><option value="blockquote">Citation</option></select><button type="button" data-command="insertUnorderedList" title="Liste à puces">• Liste</button><button type="button" data-command="insertOrderedList" title="Liste numérotée">1. Liste</button><button type="button" data-command="justifyLeft" title="Aligner à gauche">≡</button><button type="button" data-command="justifyCenter" title="Centrer">☰</button><button type="button" data-command="justifyRight" title="Aligner à droite">≡</button><label class="color-tool" title="Couleur du texte">A<input id="font-color" type="color" value="#28232e" aria-label="Couleur du texte"></label><button type="button" id="link-tool" title="Insérer un lien">↗</button><button type="button" data-command="removeFormat" title="Effacer la mise en forme">Tx</button></div>';
    $content = '<main class="admin-main editor-main"><div class="admin-title-row"><div><span class="eyebrow">' . ($article ? 'Modifier la publication' : 'Nouvelle publication') . '</span><h1>' . esc($title ?: 'Rédiger un article') . '</h1></div><a class="plain-button" href="/admin">← Tableau de bord</a></div>' . $errorHtml
        . '<form method="post" action="/admin/articles/save" enctype="multipart/form-data" id="article-editor-form">' . csrf_field() . '<input type="hidden" name="id" value="' . esc($article['id'] ?? '') . '">'
        . '<label class="admin-field">Langue d’origine<select name="language">' . language_options($languages, $article['language'] ?? '') . '</select><small class="field-help">Laissez « Détection automatique » pour reconnaître la langue du texte. Les visiteurs des autres langues voient une traduction automatique.</small></label>'
        . '<div class="article-edit-layout"><div class="article-edit-main"><label class="admin-field">Titre<input name="title" required maxlength="180" value="' . esc($title) . '" placeholder="Titre de l’article"></label>'
        . '<label class="admin-field">Ajouter un contenu' . $toolbar . '<div id="rich-editor" class="rich-editor" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Commencez à rédiger votre article…">' . $bodyHtml . '</div><textarea name="body_html" id="body-html" hidden></textarea><small class="field-help">Sélectionnez un passage puis choisissez sa mise en forme. Le texte est nettoyé à l’enregistrement.</small>'
        . '<label class="admin-field excerpt-field">Chapô / résumé<textarea name="excerpt" rows="4" maxlength="500" placeholder="Quelques phrases pour présenter l’article">' . esc($article['excerpt'] ?? '') . '</textarea></label></label></div>'
        . '<aside class="article-edit-sidebar"><section class="side-panel"><h2>Publication</h2><label class="admin-field">État<select name="status"><option value="draft" ' . selected_attr($status === 'draft') . '>Brouillon</option><option value="published" ' . selected_attr($status === 'published') . '>Publier</option></select></label><label class="admin-field">Auteur/autrice<input name="author" required maxlength="120" value="' . esc($article['author'] ?? $session['username']) . '"></label><div class="side-actions"><button class="primary-button" type="submit">' . ($article ? 'Enregistrer' : 'Enregistrer l’article') . '</button><a class="plain-button" href="/admin">Annuler</a></div></section>'
        . translations_panel($languages, $article)
        . '<section class="side-panel"><h2>Image mise en avant</h2>' . $imageHtml . '<label class="admin-field">Choisir une image<input type="file" name="featured_image" accept="image/jpeg,image/png,image/webp,image/gif"></label><small class="field-help">JPG, PNG, WebP ou GIF · 8 Mo maximum.</small></section>'
        . '<section class="side-panel"><h2>Catégories</h2><div class="check-list">' . $catOptions . '</div><a class="small-admin-link" href="/admin/categories">+ Ajouter une catégorie</a></section>'
        . '<section class="side-panel"><h2>Étiquettes</h2><div class="check-list">' . $tagOptions . '</div><label class="admin-field">Ajouter des étiquettes<input name="new_tags" placeholder="Séparées par des virgules"></label><a class="small-admin-link" href="/admin/tags">Gérer toutes les étiquettes</a></section>'
        . '<section class="side-panel"><h2>Modèle</h2><label class="admin-field">Modèle<select name="template_id">' . $templateOptions . '</select></label><a class="small-admin-link" href="/admin/templates">Gérer les modèles</a></section>'
        . '<section class="side-panel"><h2>Commentaires</h2><label class="check-option"><input type="checkbox" name="comments_enabled" value="1" ' . ($commentsEnabled ? 'checked' : '') . '>Autoriser les commentaires</label><a class="small-admin-link" href="/admin/comments">Modérer les commentaires</a></section></aside></div></form></main>';
    send_body(admin_page('Éditeur d’article', $content, 'articles'));
}

function uuid4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/** Enregistre l'image envoyée. Renvoie [chemin public, message d'erreur]. */
function store_uploaded_image(?array $file): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || (int) ($file['size'] ?? 0) === 0) {
        return ['', ''];
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $file['size'] > 8 * 1024 * 1024) {
        return ['', 'L’image dépasse la limite de 8 Mo.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['', 'Choisissez une image JPEG, PNG, GIF ou WebP valide.'];
    }
    $ext = strtolower('.' . pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $signatures = ['.jpg' => ["\xff\xd8\xff", 'image/jpeg'], '.jpeg' => ["\xff\xd8\xff", 'image/jpeg'], '.png' => ["\x89PNG\r\n\x1a\n", 'image/png'], '.gif' => ['GIF8', 'image/gif'], '.webp' => ['RIFF', 'image/webp']];
    [$signature, $mime] = $signatures[$ext] ?? ['', ''];
    $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 16);
    $valid = $signature !== '' && str_starts_with($head, $signature) && ($ext !== '.webp' || substr($head, 8, 4) === 'WEBP');
    if (!$valid || ($file['type'] ?? '') !== $mime) {
        return ['', 'Choisissez une image JPEG, PNG, GIF ou WebP valide.'];
    }
    $directory = upload_dir();
    if (!is_dir($directory)) {
        @mkdir($directory, 0755, true);
    }
    $filename = uuid4() . $ext;
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        return ['', 'Impossible d’enregistrer l’image sur le serveur.'];
    }
    return ['/uploads/' . $filename, ''];
}

function find_or_create_term(Db $db, string $table, string $name, string $now): int
{
    $row = $db->one("SELECT id FROM $table WHERE LOWER(name)=LOWER(?)", [$name]);
    if ($row) {
        return (int) $row['id'];
    }
    $root = slugify($name);
    $slug = $root;
    $suffix = 2;
    while ($db->one("SELECT 1 FROM $table WHERE slug=?", [$slug])) {
        $slug = $root . '-' . $suffix++;
    }
    $db->execute("INSERT INTO $table(name,slug,created_at) VALUES(?,?,?)", [$name, $slug, $now]);
    return (int) $db->value("SELECT id FROM $table WHERE LOWER(name)=LOWER(?)", [$name]);
}

function save_article(): void
{
    $r = request();
    $articleId = trim($r->form('id'));
    $title = mb_cut(trim($r->form('title')), 180);
    $excerpt = mb_cut(trim($r->form('excerpt')), 500);
    $body = safe_rich_html($r->form('body_html'));
    $author = mb_cut(trim($r->form('author')), 120);
    $status = $r->form('status') === 'published' ? 'published' : 'draft';
    if ($title === '' || $author === '') {
        article_form($articleId ?: null, 'Le titre et le nom de l’auteur sont obligatoires.');
        return;
    }
    [$imagePath, $imageError] = store_uploaded_image($r->files['featured_image'] ?? null);
    if ($imageError !== '') {
        article_form($articleId ?: null, $imageError);
        return;
    }
    $savedId = db()->transaction(function (Db $db) use ($r, $articleId, $title, $excerpt, $body, $author, $status, $imagePath): array {
        $existing = $articleId !== '' ? $db->one('SELECT * FROM articles WHERE id=?', [$articleId]) : null;
        $id = $existing ? $existing['id'] : bin2hex(random_bytes(16));
        $root = slugify($title);
        $slug = $root;
        $number = 2;
        while (($row = $db->one('SELECT id FROM articles WHERE slug=?', [$slug])) && $row['id'] !== $id) {
            $slug = $root . '-' . $number++;
        }
        $now = now_iso();
        $publishedAt = $existing['published_at'] ?? null;
        if ($status === 'published' && !$publishedAt) {
            $publishedAt = $now;
        }
        if ($status === 'draft') {
            $publishedAt = null;
        }
        $imagePath = $imagePath ?: ($existing['featured_image'] ?? '');
        $templateId = ctype_digit($r->form('template_id')) ? (int) $r->form('template_id') : null;
        if (!$templateId || !$db->one('SELECT id FROM templates WHERE id=?', [$templateId])) {
            $default = $db->value('SELECT id FROM templates WHERE is_default=1 LIMIT 1');
            $templateId = $default !== null ? (int) $default : null;
        }
        $language = resolve_language($db, $r->form('language', 'auto'), $title, $excerpt, html_to_text($body));
        $comments = $r->form('comments_enabled') !== '' ? 1 : 0;
        if ($existing) {
            $db->execute('UPDATE articles SET title=?,slug=?,excerpt=?,body_html=?,featured_image=?,status=?,author=?,template_id=?,comments_enabled=?,updated_at=?,published_at=?,language=? WHERE id=?',
                [$title, $slug, $excerpt, $body, $imagePath, $status, $author, $templateId, $comments, $now, $publishedAt, $language, $id]);
        } else {
            $db->execute('INSERT INTO articles(id,title,slug,excerpt,body_html,featured_image,status,author,template_id,comments_enabled,created_at,updated_at,published_at,language) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$id, $title, $slug, $excerpt, $body, $imagePath, $status, $author, $templateId, $comments, $now, $now, $publishedAt, $language]);
        }
        $db->execute('DELETE FROM article_categories WHERE article_id=?', [$id]);
        $db->execute('DELETE FROM article_tags WHERE article_id=?', [$id]);
        foreach ($r->formList('categories') as $rawId) {
            if (ctype_digit($rawId)) {
                $db->execute('INSERT OR IGNORE INTO article_categories(article_id,category_id) SELECT ?, id FROM categories WHERE id=?', [$id, (int) $rawId]);
            }
        }
        foreach ($r->formList('tags') as $rawId) {
            if (ctype_digit($rawId)) {
                $db->execute('INSERT OR IGNORE INTO article_tags(article_id,tag_id) SELECT ?, id FROM tags WHERE id=?', [$id, (int) $rawId]);
            }
        }
        foreach (explode(',', $r->form('new_tags')) as $item) {
            $name = mb_cut(ltrim(trim($item), '#'), 60);
            if ($name !== '') {
                $db->execute('INSERT OR IGNORE INTO article_tags(article_id,tag_id) VALUES(?,?)', [$id, find_or_create_term($db, 'tags', $name, $now)]);
            }
        }
        return [$id, ($existing['status'] ?? '') === 'published'];
    });
    [$savedId, $wasPublished] = $savedId;
    if ($status === 'published') {
        if (!$wasPublished && queue_article_mailing(db(), $savedId) > 0) {
            after_response(fn() => process_mail_queue());
        }
        after_response(fn() => pretranslate([$savedId], []));
    }
    redirect('/admin');
}

function delete_article(string $articleId): void
{
    db()->execute('DELETE FROM articles WHERE id=?', [$articleId]);
    redirect('/admin');
}

function taxonomy_page(string $kind): void
{
    $table = $kind === 'categories' ? 'categories' : 'tags';
    [$title, $label] = $table === 'categories' ? ['Catégories', 'catégorie'] : ['Étiquettes', 'étiquette'];
    [$link, $fk] = $table === 'categories' ? ['article_categories', 'category_id'] : ['article_tags', 'tag_id'];
    $rows = db()->all("SELECT $table.*, COUNT($link.article_id) AS uses FROM $table LEFT JOIN $link ON $table.id=$link.$fk GROUP BY $table.id ORDER BY $table.name");
    $items = '';
    foreach ($rows as $row) {
        $items .= '<tr><td><strong>' . esc($row['name']) . '</strong></td><td>' . $row['uses'] . '</td><td><form method="post" action="/admin/' . $table . '/delete">' . csrf_field() . '<input type="hidden" name="id" value="' . $row['id'] . '"><button class="danger-button" onclick="return confirm(\'Supprimer cette ' . $label . ' ?\')">Supprimer</button></form></td></tr>';
    }
    $items = $items ?: '<tr><td colspan="3">Aucune donnée pour le moment.</td></tr>';
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Classement</span><h1>' . $title . '</h1><p>Organisez les publications par ' . $label . 's.</p></div></div><section class="admin-panel taxonomy-admin-panel"><form method="post" action="/admin/' . $table . '" class="inline-create">' . csrf_field() . '<label>' . ($table === 'categories' ? 'Nom de la catégorie' : 'Nom de l’étiquette') . '<input name="name" required maxlength="80" placeholder="Nouvelle ' . $label . '"></label><button class="primary-button">Ajouter</button></form><div class="admin-table-wrap"><table><thead><tr><th>Nom</th><th>Articles liés</th><th></th></tr></thead><tbody>' . $items . '</tbody></table></div></section></main>';
    send_body(admin_page($title, $content, $kind));
}

function taxonomy_post(string $table, bool $delete): void
{
    $db = db();
    if ($delete) {
        if (ctype_digit(request()->form('id'))) {
            [$link, $fk] = $table === 'categories' ? ['article_categories', 'category_id'] : ['article_tags', 'tag_id'];
            $id = (int) request()->form('id');
            $db->transaction(function (Db $db) use ($table, $link, $fk, $id): void {
                $db->execute("DELETE FROM $link WHERE $fk=?", [$id]);
                $db->execute("DELETE FROM $table WHERE id=?", [$id]);
            });
        }
        redirect('/admin/' . $table);
        return;
    }
    $name = mb_cut(trim(request()->form('name')), 80);
    if ($name !== '') {
        find_or_create_term($db, $table, $name, now_iso());
    }
    redirect('/admin/' . $table);
}

function templates_page(): void
{
    $items = '';
    foreach (db()->all('SELECT templates.*,COUNT(articles.id) AS uses FROM templates LEFT JOIN articles ON articles.template_id=templates.id GROUP BY templates.id ORDER BY is_default DESC,name') as $row) {
        $default = (int) $row['is_default'];
        $delete = $default ? '' : '<form method="post" action="/admin/templates">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $row['id'] . '"><button class="danger-button" onclick="return confirm(&#39;Supprimer ce modèle ?&#39;)">Supprimer</button></form>';
        $items .= '<tr><td><strong>' . esc($row['name']) . '</strong>' . ($default ? '<span class="default-mark">Par défaut</span>' : '') . '</td><td>' . $row['uses'] . '</td><td><form method="post" action="/admin/templates">' . csrf_field() . '<input type="hidden" name="action" value="default"><input type="hidden" name="id" value="' . $row['id'] . '"><button class="plain-button">Définir par défaut</button></form></td><td>' . $delete . '</td></tr>';
    }
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Structure des articles</span><h1>Modèles</h1><p>Choisissez le modèle proposé par défaut à la création d’un article.</p></div></div><section class="admin-panel"><form method="post" action="/admin/templates" class="template-create">' . csrf_field() . '<input type="hidden" name="action" value="create"><label class="admin-field">Nom du modèle<input name="name" required maxlength="80" placeholder="Ex. Analyse, entretien"></label><label class="admin-field">Contenu de départ <textarea name="body_html" rows="4" placeholder="Structure HTML facultative à insérer dans l’éditeur"></textarea></label><label class="check-option"><input type="checkbox" name="make_default" value="1">Définir comme modèle par défaut</label><button class="primary-button">Créer le modèle</button></form><div class="admin-table-wrap"><table><thead><tr><th>Modèle</th><th>Articles</th><th>Par défaut</th><th></th></tr></thead><tbody>' . $items . '</tbody></table></div></section></main>';
    send_body(admin_page('Modèles', $content, 'templates'));
}

function templates_post(): void
{
    $r = request();
    $action = $r->form('action');
    $id = ctype_digit($r->form('id')) ? (int) $r->form('id') : 0;
    db()->transaction(function (Db $db) use ($r, $action, $id): void {
        if ($action === 'create') {
            $name = mb_cut(trim($r->form('name')), 80);
            if ($name !== '' && !$db->one('SELECT 1 FROM templates WHERE LOWER(name)=LOWER(?)', [$name])) {
                $makeDefault = $r->form('make_default') === '1';
                if ($makeDefault) {
                    $db->execute('UPDATE templates SET is_default=0');
                }
                $db->execute('INSERT INTO templates(name,body_html,is_default,created_at) VALUES(?,?,?,?)', [$name, safe_rich_html($r->form('body_html')), $makeDefault ? 1 : 0, now_iso()]);
            }
        } elseif ($action === 'default' && $id && $db->one('SELECT 1 FROM templates WHERE id=?', [$id])) {
            $db->execute('UPDATE templates SET is_default=0');
            $db->execute('UPDATE templates SET is_default=1 WHERE id=?', [$id]);
        } elseif ($action === 'delete' && $id) {
            $row = $db->one('SELECT is_default FROM templates WHERE id=?', [$id]);
            if ($row && !(int) $row['is_default']) {
                $db->execute('DELETE FROM templates WHERE id=?', [$id]);
            }
        }
    });
    redirect('/admin/templates');
}

function comments_page(): void
{
    $items = '';
    foreach (db()->all("SELECT comments.*,articles.title,articles.slug FROM comments JOIN articles ON articles.id=comments.article_id ORDER BY CASE comments.status WHEN 'pending' THEN 0 ELSE 1 END,comments.created_at DESC") as $row) {
        $approve = $row['status'] === 'pending' ? '<form method="post" action="/admin/comments/' . $row['id'] . '">' . csrf_field() . '<input type="hidden" name="action" value="approve"><button class="primary-button">Approuver</button></form>' : '';
        $items .= '<article class="comment-admin-card"><div class="comment-admin-head"><strong>' . esc($row['author']) . '</strong><span class="status-badge ' . $row['status'] . '">' . ($row['status'] === 'pending' ? 'À modérer' : 'Publié') . '</span><time>' . esc(substr($row['created_at'], 0, 10)) . '</time></div><a href="/article/' . q($row['slug']) . '#comments">Sur « ' . esc($row['title']) . ' »</a><p>' . esc($row['body']) . '</p><div class="comment-actions">' . $approve . '<form method="post" action="/admin/comments/' . $row['id'] . '">' . csrf_field() . '<input type="hidden" name="action" value="delete"><button class="danger-button">Supprimer</button></form></div></article>';
    }
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Modération</span><h1>Commentaires</h1><p>Les commentaires restent invisibles jusqu’à leur approbation.</p></div></div><section class="comments-admin-list">' . ($items ?: '<div class="admin-empty">Aucun commentaire reçu.</div>') . '</section></main>';
    send_body(admin_page('Commentaires', $content, 'comments'));
}

function comment_action(string $commentId): void
{
    if (ctype_digit($commentId)) {
        if (request()->form('action') === 'approve') {
            db()->execute("UPDATE comments SET status='approved' WHERE id=?", [(int) $commentId]);
        } else {
            db()->execute('DELETE FROM comments WHERE id=?', [(int) $commentId]);
        }
    }
    redirect('/admin/comments');
}
