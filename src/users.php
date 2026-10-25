<?php
/**
 * Comptes et rôles de l'espace adhérent (/admin) :
 *   admin  : Administrateur, tous les droits, seul à gérer les utilisateurs ;
 *   author : Auteur, crée et publie ses articles, ne modifie que les siens ;
 *   member : Adhérent, tableau de bord, profil et messagerie (bientôt disponible).
 * Les droits sont vérifiés côté serveur pour chaque page et chaque action (voir front.php).
 */
declare(strict_types=1);

const ROLES = ['admin' => 'Administrateur', 'author' => 'Auteur', 'member' => 'Adhérent'];
const MIN_PASSWORD_LENGTH = 12;

function current_user(): ?array
{
    return auth();
}

function user_role(): string
{
    return (string) (auth()['role'] ?? '');
}

function is_admin(): bool
{
    return user_role() === 'admin';
}

/** Rubriques de /admin accessibles à chaque rôle (premier segment après /admin ; '' : tableau de bord). */
function admin_section_allowed(string $section, string $role): bool
{
    if ($role === 'admin') {
        return true;
    }
    $common = ['', 'profile', 'messages', 'logout'];
    if ($role === 'author') {
        return in_array($section, [...$common, 'articles'], true);
    }
    return in_array($section, $common, true);
}

function admin_forbidden(): void
{
    send_body(admin_page('Accès refusé', '<main class="admin-main"><div class="admin-empty-state"><span class="eyebrow">Accès refusé</span><h1>Cette page est réservée</h1><p>Votre rôle (' . esc(ROLES[user_role()] ?? '—') . ') ne permet pas d’accéder à cette rubrique.</p><a class="primary-button" href="/admin">Retour au tableau de bord</a></div></main>'), 403);
}

/** Un auteur ne peut agir que sur ses propres articles ; un administrateur sur tous. */
function can_edit_article(?array $article): bool
{
    if (!$article) {
        return false;
    }
    return is_admin() || (user_role() === 'author' && (int) ($article['owner_id'] ?? 0) === (int) (auth()['user_id'] ?? -1));
}

function user_label(array $user): string
{
    return $user['display_name'] !== '' ? $user['display_name'] : $user['username'];
}

function role_badge(string $role): string
{
    return '<span class="role-badge role-' . esc($role) . '">' . esc(ROLES[$role] ?? $role) . '</span>';
}

/** Nombre d'administrateurs actifs, sans compter $exceptId (pour ne jamais retirer le dernier). */
function other_active_admins(Db $db, int $exceptId): int
{
    return (int) $db->value("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1 AND id<>?", [$exceptId]);
}

// --- Gestion des utilisateurs (administrateurs) ---------------------------------------------------------------------

function users_page(string $notice = '', string $error = ''): void
{
    $db = db();
    $search = filter_value('q');
    $role = array_key_exists(request()->q('role'), ROLES) ? request()->q('role') : '';
    $where = ['1=1'];
    $params = [];
    if ($search !== '') {
        $where[] = '(username ILIKE ? OR display_name ILIKE ? OR email ILIKE ?)';
        array_push($params, like_contains($search), like_contains($search), like_contains($search));
    }
    if ($role !== '') {
        $where[] = 'role=?';
        $params[] = $role;
    }
    $condition = implode(' AND ', $where);
    $total = (int) $db->value("SELECT COUNT(*) FROM users WHERE $condition", $params);
    $page = admin_list_page();
    $rows = '';
    $me = (int) auth()['user_id'];
    foreach ($db->all("SELECT * FROM users WHERE $condition ORDER BY active DESC, role, lower(username) LIMIT ? OFFSET ?", [...$params, ADMIN_PAGE_SIZE, ($page - 1) * ADMIN_PAGE_SIZE]) as $user) {
        $toggle = (int) $user['id'] === $me ? '<span class="field-help">Votre compte</span>'
            : '<form method="post" action="/admin/users/' . $user['id'] . '/toggle">' . csrf_field() . '<button>' . ((int) $user['active'] ? 'Désactiver' : 'Réactiver') . '</button></form>';
        $rows .= '<tr class="' . ((int) $user['active'] ? '' : 'is-inactive') . '"><td><strong>' . esc(user_label($user)) . '</strong><small>' . esc($user['username']) . ($user['email'] !== '' ? ' · ' . esc($user['email']) : '') . '</small></td><td>' . role_badge($user['role'])
            . '</td><td><span class="status-badge ' . ((int) $user['active'] ? 'published' : 'draft') . '">' . ((int) $user['active'] ? 'Actif' : 'Désactivé') . '</span></td><td>' . esc(substr($user['created_at'], 0, 10))
            . '</td><td class="table-actions"><a href="/admin/users/' . $user['id'] . '/edit">Modifier</a>' . $toggle . '</td></tr>';
    }
    $rows = $rows ?: '<tr><td colspan="5">Aucun utilisateur ne correspond à cette recherche.</td></tr>';
    $roleOptions = '<option value="">Tous les rôles</option>';
    foreach (ROLES as $key => $label) {
        $roleOptions .= '<option value="' . $key . '"' . ($key === $role ? ' selected' : '') . '>' . esc($label) . '</option>';
    }
    $filters = admin_search_form('/admin/users', $search, 'Nom, identifiant ou adresse e-mail', '<label class="admin-filter-select">Rôle <select name="role">' . $roleOptions . '</select></label>');
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Administration</span><h1>Utilisateurs</h1><p>Créez les comptes des auteurs et des adhérents, modifiez leur rôle ou désactivez-les.</p></div><a class="primary-button" href="/admin/users/new">+ Nouvel utilisateur</a></div>'
        . ($notice !== '' ? '<p class="success-message" role="status">' . esc($notice) . '</p>' : '') . ($error !== '' ? '<div class="form-error" role="alert">' . esc($error) . '</div>' : '')
        . '<section class="admin-panel">' . $filters . '<div class="admin-table-wrap"><table><thead><tr><th>Utilisateur</th><th>Rôle</th><th>État</th><th>Création</th><th>Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . admin_pagination('/admin/users', ['q' => $search, 'role' => $role], $page, $total) . '</section></main>';
    send_body(admin_page('Utilisateurs', $content, 'users'));
}

function user_form(?string $userId = null, string $error = '', array $values = []): void
{
    $user = null;
    if ($userId !== null) {
        $user = ctype_digit($userId) ? db()->one('SELECT * FROM users WHERE id=?', [(int) $userId]) : null;
        if (!$user) {
            redirect('/admin/users');
            return;
        }
    }
    $get = fn(string $key, string $default = '') => (string) ($values[$key] ?? ($user[$key] ?? $default));
    $self = $user && (int) $user['id'] === (int) auth()['user_id'];
    $roleOptions = '';
    foreach (ROLES as $key => $label) {
        $roleOptions .= '<option value="' . $key . '"' . ($get('role', 'member') === $key ? ' selected' : '') . '>' . esc($label) . '</option>';
    }
    $roleHelp = $self ? '<small class="field-help">Vous ne pouvez pas modifier votre propre rôle.</small>' : '<small class="field-help">Auteur : publie et modifie ses propres articles. Adhérent : accès à son espace (profil, messagerie).</small>';
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">' . ($user ? 'Modifier' : 'Nouveau') . '</span><h1>' . ($user ? esc(user_label($user)) : 'Nouvel utilisateur') . '</h1></div><a class="plain-button" href="/admin/users">← Retour</a></div>'
        . ($error !== '' ? '<div class="form-error" role="alert">' . esc($error) . '</div>' : '')
        . '<section class="admin-panel"><form method="post" action="/admin/users/save" class="event-admin-form admin-form-grid">' . csrf_field() . '<input type="hidden" name="id" value="' . esc($user['id'] ?? '') . '">'
        . '<label class="admin-field">Identifiant de connexion<input name="username" required minlength="3" maxlength="60" autocomplete="off" value="' . esc($get('username')) . '"></label>'
        . '<label class="admin-field">Nom affiché<input name="display_name" maxlength="120" value="' . esc($get('display_name')) . '" placeholder="Ex. Jean-Claude Mairal"></label>'
        . '<label class="admin-field">Adresse e-mail<input type="email" name="email" maxlength="200" value="' . esc($get('email')) . '"></label>'
        . '<label class="admin-field">Rôle<select name="role"' . ($self ? ' disabled' : '') . '>' . $roleOptions . '</select>' . $roleHelp . '</label>'
        . '<label class="admin-field">' . ($user ? 'Nouveau mot de passe (facultatif)' : 'Mot de passe') . '<input type="password" name="password" minlength="' . MIN_PASSWORD_LENGTH . '" autocomplete="new-password"' . ($user ? '' : ' required') . '><small class="field-help">' . MIN_PASSWORD_LENGTH . ' caractères minimum' . ($user ? ' ; laissez vide pour garder le mot de passe actuel.' : '. Communiquez-le à la personne, qui pourra le changer dans son profil.') . '</small></label>'
        . '<div class="side-actions"><button class="primary-button" type="submit">Enregistrer</button><a class="plain-button" href="/admin/users">Annuler</a></div></form></section></main>';
    send_body(admin_page($user ? 'Modifier un utilisateur' : 'Nouvel utilisateur', $content, 'users'));
}

function save_user(): void
{
    $db = db();
    $req = request();
    $id = trim($req->form('id'));
    $existing = ($id !== '' && ctype_digit($id)) ? $db->one('SELECT * FROM users WHERE id=?', [(int) $id]) : null;
    if ($id !== '' && !$existing) {
        redirect('/admin/users');
        return;
    }
    $self = $existing && (int) $existing['id'] === (int) auth()['user_id'];
    $values = [
        'username' => mb_cut(trim($req->form('username')), 60),
        'display_name' => mb_cut(trim($req->form('display_name')), 120),
        'email' => mb_strtolower(mb_cut(trim($req->form('email')), 200)),
        'role' => $self ? 'admin' : (array_key_exists($req->form('role'), ROLES) ? $req->form('role') : 'member'),
    ];
    $password = $req->form('password');
    $error = '';
    if (mb_strlen($values['username']) < 3 || !preg_match('/^[\p{L}\p{N}._@-]+$/u', $values['username'])) {
        $error = 'L’identifiant doit contenir au moins 3 caractères (lettres, chiffres, points, tirets, @).';
    } elseif ($db->one('SELECT 1 FROM users WHERE lower(username)=lower(?) AND id<>?', [$values['username'], (int) ($existing['id'] ?? 0)])) {
        $error = 'Cet identifiant est déjà utilisé.';
    } elseif ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'L’adresse e-mail n’est pas valide.';
    } elseif ((!$existing || $password !== '') && mb_strlen($password) < MIN_PASSWORD_LENGTH) {
        $error = 'Le mot de passe doit contenir au moins ' . MIN_PASSWORD_LENGTH . ' caractères.';
    } elseif ($existing && $existing['role'] === 'admin' && $values['role'] !== 'admin' && (int) $existing['active'] === 1 && other_active_admins($db, (int) $existing['id']) === 0) {
        $error = 'Il doit rester au moins un administrateur actif.';
    }
    if ($error !== '') {
        user_form($existing ? (string) $existing['id'] : null, $error, $values);
        return;
    }
    if ($existing) {
        $db->execute('UPDATE users SET username=?, display_name=?, email=?, role=? WHERE id=?', [$values['username'], $values['display_name'], $values['email'], $values['role'], (int) $existing['id']]);
        if ($password !== '') {
            $salt = bin2hex(random_bytes(16));
            $db->execute('UPDATE users SET password_hash=?, password_salt=? WHERE id=?', [password_digest($password, $salt), $salt, (int) $existing['id']]);
        }
        users_page('Le compte « ' . $values['username'] . ' » a été mis à jour.');
        return;
    }
    $salt = bin2hex(random_bytes(16));
    $db->execute('INSERT INTO users(username,password_hash,password_salt,created_at,role,display_name,email,active) VALUES(?,?,?,?,?,?,?,1)',
        [$values['username'], password_digest($password, $salt), $salt, now_iso(), $values['role'], $values['display_name'], $values['email']]);
    users_page('Le compte « ' . $values['username'] . ' » a été créé (' . ROLES[$values['role']] . ').');
}

/** Désactive (ou réactive) un compte ; un compte désactivé est déconnecté immédiatement. */
function toggle_user(string $userId): void
{
    $db = db();
    $user = ctype_digit($userId) ? $db->one('SELECT * FROM users WHERE id=?', [(int) $userId]) : null;
    if (!$user) {
        redirect('/admin/users');
        return;
    }
    if ((int) $user['id'] === (int) auth()['user_id']) {
        users_page('', 'Vous ne pouvez pas désactiver votre propre compte.');
        return;
    }
    if ((int) $user['active'] === 1 && $user['role'] === 'admin' && other_active_admins($db, (int) $user['id']) === 0) {
        users_page('', 'Il doit rester au moins un administrateur actif.');
        return;
    }
    $active = (int) $user['active'] === 1 ? 0 : 1;
    $db->execute('UPDATE users SET active=? WHERE id=?', [$active, (int) $user['id']]);
    if ($active === 0) {
        $db->execute('DELETE FROM sessions WHERE user_id=?', [(int) $user['id']]);
    }
    users_page('Le compte « ' . $user['username'] . ' » a été ' . ($active ? 'réactivé.' : 'désactivé.'));
}

// --- Pour tous les rôles : tableau de bord adhérent, profil, messagerie ------------------------------------------------

function member_dashboard(): void
{
    $user = current_user();
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Espace adhérent</span><h1>Bonjour ' . esc($user['display_name'] ?: $user['username']) . '</h1><p>Bienvenue dans votre espace I-Dialogos.</p></div></div>'
        . '<div class="admin-cards"><a class="admin-card" href="/admin/profile"><strong>Mon profil</strong><span>Nom, adresse e-mail et mot de passe.</span></a>'
        . '<a class="admin-card" href="/admin/messages"><strong>Messagerie</strong><span>Échangez avec les membres du réseau. Bientôt disponible.</span></a>'
        . '<a class="admin-card" href="/analyses"><strong>Les Notes d’I-Dialogos</strong><span>Toutes les analyses et contributions du réseau.</span></a></div></main>';
    send_body(admin_page('Espace adhérent', $content, 'home'));
}

function messages_page(): void
{
    $content = '<main class="admin-main"><div class="admin-empty-state"><span class="eyebrow">Messagerie</span><h1>Bientôt disponible</h1><p>La messagerie permettra d’échanger avec les membres du réseau I-Dialogos. Elle est en cours de préparation.</p><a class="plain-button" href="/admin">Retour au tableau de bord</a></div></main>';
    send_body(admin_page('Messagerie', $content, 'messages'));
}

function profile_page(string $notice = '', string $error = ''): void
{
    $user = db()->one('SELECT * FROM users WHERE id=?', [(int) auth()['user_id']]);
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">' . role_badge($user['role']) . '</span><h1>Mon profil</h1><p>Identifiant de connexion : <strong>' . esc($user['username']) . '</strong></p></div></div>'
        . ($notice !== '' ? '<p class="success-message" role="status">' . esc($notice) . '</p>' : '') . ($error !== '' ? '<div class="form-error" role="alert">' . esc($error) . '</div>' : '')
        . '<section class="admin-panel"><form method="post" action="/admin/profile" class="event-admin-form admin-form-grid">' . csrf_field()
        . '<label class="admin-field">Nom affiché<input name="display_name" maxlength="120" value="' . esc($user['display_name']) . '"></label>'
        . '<label class="admin-field">Adresse e-mail<input type="email" name="email" maxlength="200" value="' . esc($user['email']) . '"></label>'
        . '<fieldset class="admin-fieldset"><legend>Changer de mot de passe (facultatif)</legend>'
        . '<label class="admin-field">Mot de passe actuel<input type="password" name="current_password" autocomplete="current-password"></label>'
        . '<label class="admin-field">Nouveau mot de passe<input type="password" name="new_password" minlength="' . MIN_PASSWORD_LENGTH . '" autocomplete="new-password"><small class="field-help">' . MIN_PASSWORD_LENGTH . ' caractères minimum.</small></label>'
        . '<label class="admin-field">Confirmer le nouveau mot de passe<input type="password" name="confirm_password" autocomplete="new-password"></label></fieldset>'
        . '<div class="side-actions"><button class="primary-button" type="submit">Enregistrer</button></div></form></section></main>';
    send_body(admin_page('Mon profil', $content, 'profile'));
}

function save_profile(): void
{
    $db = db();
    $req = request();
    $user = $db->one('SELECT * FROM users WHERE id=?', [(int) auth()['user_id']]);
    $displayName = mb_cut(trim($req->form('display_name')), 120);
    $email = mb_strtolower(mb_cut(trim($req->form('email')), 200));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        profile_page('', 'L’adresse e-mail n’est pas valide.');
        return;
    }
    $newPassword = $req->form('new_password');
    if ($newPassword !== '') {
        if (!hash_equals((string) $user['password_hash'], password_digest($req->form('current_password'), (string) $user['password_salt']))) {
            profile_page('', 'Le mot de passe actuel est incorrect.');
            return;
        }
        if (mb_strlen($newPassword) < MIN_PASSWORD_LENGTH || $newPassword !== $req->form('confirm_password')) {
            profile_page('', 'Le nouveau mot de passe doit contenir au moins ' . MIN_PASSWORD_LENGTH . ' caractères et être saisi deux fois à l’identique.');
            return;
        }
        $salt = bin2hex(random_bytes(16));
        $db->execute('UPDATE users SET password_hash=?, password_salt=? WHERE id=?', [password_digest($newPassword, $salt), $salt, (int) $user['id']]);
    }
    $db->execute('UPDATE users SET display_name=?, email=? WHERE id=?', [$displayName, $email, (int) $user['id']]);
    App::$sessionCache = null;
    profile_page($newPassword !== '' ? 'Profil et mot de passe mis à jour.' : 'Profil mis à jour.');
}

// --- Composants des listes de l'administration ---------------------------------------------------------------------

/** Formulaire de recherche (GET) placé au-dessus d'une liste ; $extra : filtres supplémentaires. */
function admin_search_form(string $base, string $search, string $placeholder, string $extra = ''): string
{
    // Réinitialiser : seulement si une recherche ou un filtre (option non vide sélectionnée) est actif.
    $reset = $search !== '' || preg_match('/value="[^"]+" selected/', $extra) ? '<a class="list-reset" href="' . esc($base) . '">Réinitialiser</a>' : '';
    return '<form class="admin-search" method="get" action="' . esc($base) . '" role="search"><label class="admin-search-field"><span class="sr-only">Rechercher</span><input type="search" name="q" value="' . esc($search) . '" placeholder="' . esc($placeholder) . '"></label>' . $extra . '<button class="plain-button" type="submit">Rechercher</button>' . $reset . '</form>';
}
