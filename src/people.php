<?php
/**
 * Invités de la semaine et experts : page publique /invites-et-experts, blocs de l'accueil et administration.
 * Tant qu'aucun invité (ou aucun expert) n'est enregistré, l'accueil garde exactement le contenu fixe d'index.html.
 */
declare(strict_types=1);

const PEOPLE_KINDS = [
    'guest' => ['path' => 'invites', 'plural' => 'Invités de la semaine', 'singular' => 'invité de la semaine', 'new' => 'Nouvel invité'],
    'expert' => ['path' => 'experts', 'plural' => 'Experts', 'singular' => 'expert', 'new' => 'Nouvel expert'],
];

function people(Db $db, string $kind): array
{
    $order = $kind === 'guest' ? 'featured_on DESC, id DESC' : 'position, name, id';
    return $db->all("SELECT * FROM people WHERE kind=? ORDER BY $order", [$kind]);
}

/** Initiales affichées quand une personne n'a pas de photo. */
function person_initials(string $name): string
{
    $initials = '';
    foreach (preg_split('/[\s\-]+/u', trim($name)) ?: [] as $part) {
        if ($part !== '' && mb_strlen($initials) < 2) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }
    return $initials ?: '·';
}

function person_photo(array $person, string $class = ''): string
{
    if ($person['photo'] !== '') {
        return '<img' . ($class !== '' ? ' class="' . $class . '"' : '') . ' src="' . esc($person['photo']) . '" alt="Portrait de ' . esc($person['name']) . '" loading="lazy">';
    }
    return '<span class="person-initials' . ($class !== '' ? ' ' . $class : '') . '" aria-hidden="true">' . esc(person_initials($person['name'])) . '</span>';
}

/** Texte saisi (sauts de ligne) rendu en paragraphes échappés. */
function text_paragraphs(string $text): string
{
    $html = '';
    foreach (preg_split('/\R{2,}|\R/u', trim($text)) ?: [] as $line) {
        if (trim($line) !== '') {
            $html .= '<p>' . esc(trim($line)) . '</p>';
        }
    }
    return $html;
}

function person_meta(array $person): string
{
    $parts = array_filter([$person['expertise'], $person['region']], fn($value) => $value !== '');
    return $parts ? '<p class="person-meta">' . esc(implode(' · ', $parts)) . '</p>' : '';
}

/** Remplace le contenu entre deux repères HTML d'index.html (repères compris). */
function replace_between(string $page, string $start, string $end, string $replacement): string
{
    $from = strpos($page, $start);
    $to = strpos($page, $end);
    if ($from === false || $to === false || $to < $from) {
        return $page;
    }
    return substr($page, 0, $from) . $replacement . substr($page, $to + strlen($end));
}

/** Contenu fixe d'index.html entre deux repères (repères exclus). */
function static_between(string $start, string $end): string
{
    $page = index_page();
    $from = strpos($page, $start);
    $to = strpos($page, $end);
    return ($from === false || $to === false) ? '' : substr($page, $from + strlen($start), $to - $from - strlen($start));
}

/** Accueil : invité le plus récent et experts enregistrés, à la place du contenu fixe (seulement s'il y en a). */
function home_people_blocks(Db $db, string $page): string
{
    $guests = people($db, 'guest');
    if ($guests) {
        $guest = $guests[0];
        $page = replace_between($page, '<!-- invite:debut -->', '<!-- invite:fin -->',
            '<div class="mk-split">' . person_photo($guest) . '<div><h3 class="mk-guest-name">' . esc($guest['name']) . '</h3>' . person_meta($guest) . text_paragraphs($guest['description']) . '</div></div>');
    }
    $experts = people($db, 'expert');
    if ($experts) {
        $cards = '';
        foreach ($experts as $expert) {
            $lines = implode('', array_map(fn($value) => '<p>' . esc($value) . '</p>', array_filter([$expert['expertise'], $expert['region']], fn($value) => $value !== '')));
            $cards .= '<article>' . person_photo($expert) . '<div><h3>' . esc($expert['name']) . '</h3>' . $lines . '</div></article>';
        }
        $page = replace_between($page, '<!-- experts:debut -->', '<!-- experts:fin -->', $cards);
    }
    return $page;
}

function person_card(array $person, string $language): string
{
    $date = $person['kind'] === 'guest' && $person['featured_on'] !== '' ? '<p class="person-date">Invité/e de la semaine du <time datetime="' . esc($person['featured_on']) . '">' . esc(long_date($person['featured_on'], $language)) . '</time></p>' : '';
    return '<article class="person-card" lang="' . esc(content_lang($person)) . '">' . person_photo($person, 'person-photo') . '<div class="person-body"><h3>' . esc($person['name']) . '</h3>' . person_meta($person) . $date . text_paragraphs($person['description']) . '</div></article>';
}

/** /invites-et-experts : tous les invités (le plus récent mis en avant) et tous les experts. */
function public_people(): void
{
    $db = db();
    $language = selected_language($db);
    $translate = fn(array $rows) => translate_records($db, array_map(fn($row) => $row + ['language' => 'fr'], $rows), $language, ['description', 'expertise', 'region']);
    $guests = $translate(people($db, 'guest'));
    $experts = $translate(people($db, 'expert'));
    if ($guests) {
        $current = $guests[0];
        $featured = '<article class="person-featured" lang="' . esc(content_lang($current)) . '"><span class="eyebrow">Invité/e de la semaine</span><div class="mk-split">' . person_photo($current) . '<div><h3 class="mk-guest-name">' . esc($current['name']) . '</h3>' . person_meta($current) . text_paragraphs($current['description']) . '</div></div></article>';
        $past = array_slice($guests, 1);
        $pastHtml = $past ? '<h3 class="people-subtitle">Invités précédents</h3><div class="people-grid">' . implode('', array_map(fn($person) => person_card($person, $language), $past)) . '</div>' : '';
        $guestsHtml = $featured . $pastHtml;
    } else {
        // Aucun invité enregistré : l'invitée actuelle de l'accueil (contenu fixe).
        $guestsHtml = '<article class="person-featured"><span class="eyebrow">Invité/e de la semaine</span>' . str_replace('src="assets/', 'src="/assets/', static_between('<!-- invite:debut -->', '<!-- invite:fin -->')) . '</article>';
    }
    if ($experts) {
        $expertsHtml = '<div class="people-grid">' . implode('', array_map(fn($person) => person_card($person, $language), $experts)) . '</div>';
    } else {
        // Aucun expert enregistré : les experts présentés sur l'accueil (contenu fixe).
        $expertsHtml = '<div class="people-grid people-static">' . str_replace('src="assets/', 'src="/assets/', static_between('<!-- experts:debut -->', '<!-- experts:fin -->')) . '</div>';
    }
    $body = '<main class="list-page people-page"><div class="section-heading"><span class="eyebrow">Le réseau I-Dialogos</span><h1>Invités et experts</h1><p>Les invités de la semaine et les experts qui font vivre le dialogue d’I-Dialogos.</p></div>'
        . '<nav class="people-tabs" aria-label="Sections de la page"><a href="#invites">Invités de la semaine</a><a href="#experts-liste">Experts</a></nav>'
        . '<section id="invites" class="people-section" aria-labelledby="invites-title"><h2 id="invites-title" class="theme-subtitle">Invités de la semaine</h2>' . $guestsHtml . '</section>'
        . '<section id="experts-liste" class="people-section" aria-labelledby="experts-title"><h2 id="experts-title" class="theme-subtitle">Experts</h2>' . $expertsHtml . '</section></main>';
    public_page('Invités et experts', $body);
}

// --- Administration ------------------------------------------------------------------------------------------------

function people_kind_from_path(string $segment): ?string
{
    foreach (PEOPLE_KINDS as $kind => $labels) {
        if ($labels['path'] === $segment) {
            return $kind;
        }
    }
    return null;
}

function people_admin_page(string $kind): void
{
    $labels = PEOPLE_KINDS[$kind];
    $rows = '';
    foreach (people(db(), $kind) as $person) {
        $detail = $kind === 'guest' ? ($person['featured_on'] !== '' ? esc(long_date($person['featured_on'], 'fr')) : 'Sans date') : 'Ordre ' . (int) $person['position'];
        $rows .= '<tr><td class="admin-person"><span class="admin-person-photo">' . person_photo($person) . '</span><span><strong>' . esc($person['name']) . '</strong><small>' . esc($person['expertise'] ?: '—') . '</small></span></td><td>' . esc($person['region'] ?: '—') . '</td><td>' . $detail . '</td><td class="table-actions"><a href="/admin/' . $labels['path'] . '/' . $person['id'] . '/edit">Modifier</a><form method="post" action="/admin/' . $labels['path'] . '/' . $person['id'] . '/delete" onsubmit="return confirm(\'Supprimer cette fiche ?\')">' . csrf_field() . '<button>Supprimer</button></form></td></tr>';
    }
    $rows = $rows ?: '<tr><td colspan="4">Aucune fiche pour le moment.' . ($kind === 'expert' ? ' Tant qu’aucun expert n’est enregistré, l’accueil présente les experts actuels du site.' : ' Tant qu’aucun invité n’est enregistré, l’accueil présente l’invitée actuelle du site.') . '</td></tr>';
    $dateHead = $kind === 'guest' ? 'Semaine' : 'Affichage';
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Réseau</span><h1>' . esc($labels['plural']) . '</h1><p>Fiches présentées sur l’accueil et sur la page <a href="/invites-et-experts" target="_blank">Invités et experts</a>.</p></div><a class="primary-button" href="/admin/' . $labels['path'] . '/new">+ ' . esc($labels['new']) . '</a></div><section class="admin-panel"><div class="admin-table-wrap"><table><thead><tr><th>Nom</th><th>Pays ou région</th><th>' . $dateHead . '</th><th>Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section></main>';
    send_body(admin_page($labels['plural'], $content, $labels['path']));
}

function person_form(string $kind, ?string $personId = null, string $error = '', array $values = []): void
{
    $labels = PEOPLE_KINDS[$kind];
    $person = null;
    if ($personId !== null) {
        $person = ctype_digit($personId) ? db()->one('SELECT * FROM people WHERE id=? AND kind=?', [(int) $personId, $kind]) : null;
        if (!$person) {
            redirect('/admin/' . $labels['path']);
            return;
        }
    }
    $get = fn(string $key, string $default = '') => (string) ($values[$key] ?? ($person[$key] ?? $default));
    $photo = $person && $person['photo'] !== '' ? '<img class="featured-preview" src="' . esc($person['photo']) . '" alt="Photo actuelle"><label class="check-option"><input type="checkbox" name="remove_photo" value="1"> Retirer la photo</label>' : '<div class="image-placeholder">Aucune photo</div>';
    $specific = $kind === 'guest'
        ? '<label class="admin-field">Semaine (date de mise en avant)<input type="date" name="featured_on" value="' . esc($get('featured_on', substr(now_iso(), 0, 10))) . '"><small class="field-help">L’invité le plus récent apparaît sur l’accueil.</small></label>'
        : '<label class="admin-field">Ordre d’affichage<input type="number" name="position" min="0" max="9999" value="' . esc($get('position', '0')) . '"><small class="field-help">Les experts sont présentés du plus petit au plus grand nombre.</small></label>';
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">' . ($person ? 'Modifier' : 'Nouveau') . '</span><h1>' . ($person ? esc($person['name']) : esc($labels['new'])) . '</h1></div><a class="plain-button" href="/admin/' . $labels['path'] . '">← Retour</a></div>'
        . ($error !== '' ? '<div class="form-error" role="alert">' . esc($error) . '</div>' : '')
        . '<section class="admin-panel"><form method="post" action="/admin/' . $labels['path'] . '/save" enctype="multipart/form-data" class="event-admin-form person-form">' . csrf_field() . '<input type="hidden" name="id" value="' . esc($person['id'] ?? '') . '">'
        . '<label class="admin-field">Nom<input name="name" required maxlength="160" value="' . esc($get('name')) . '"></label>'
        . '<label class="admin-field">Domaine d’expertise<input name="expertise" maxlength="200" value="' . esc($get('expertise')) . '" placeholder="Ex. droit constitutionnel, médias, géopolitique"></label>'
        . '<label class="admin-field">Pays ou région<input name="region" maxlength="120" value="' . esc($get('region')) . '" placeholder="Ex. Mali, Bologne (Italie)"></label>'
        . '<label class="admin-field">Description<textarea name="description" rows="7" maxlength="4000">' . esc($get('description')) . '</textarea><small class="field-help">Un saut de ligne crée un nouveau paragraphe.</small></label>'
        . $specific
        . '<div class="admin-field">Photo' . $photo . '<input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" aria-label="Choisir une photo"><small class="field-help">JPG, PNG, WebP ou GIF · 8 Mo maximum.</small></div>'
        . '<div class="side-actions"><button class="primary-button" type="submit">Enregistrer</button><a class="plain-button" href="/admin/' . $labels['path'] . '">Annuler</a></div></form></section></main>';
    send_body(admin_page(($person ? 'Modifier ' : 'Nouvel ') . $labels['singular'], $content, $labels['path']));
}

function save_person(string $kind): void
{
    $labels = PEOPLE_KINDS[$kind];
    $req = request();
    $id = trim($req->form('id'));
    $values = [
        'name' => mb_cut(trim($req->form('name')), 160),
        'expertise' => mb_cut(trim($req->form('expertise')), 200),
        'region' => mb_cut(trim($req->form('region')), 120),
        'description' => mb_cut(trim(str_replace("\r\n", "\n", $req->form('description'))), 4000),
        'featured_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $req->form('featured_on')) ? $req->form('featured_on') : '',
        'position' => (string) max(0, min(9999, (int) $req->form('position'))),
    ];
    $existing = ($id !== '' && ctype_digit($id)) ? db()->one('SELECT * FROM people WHERE id=? AND kind=?', [(int) $id, $kind]) : null;
    if ($id !== '' && !$existing) {
        redirect('/admin/' . $labels['path']);
        return;
    }
    if ($values['name'] === '') {
        person_form($kind, $existing ? (string) $existing['id'] : null, 'Le nom est obligatoire.', $values);
        return;
    }
    [$photo, $photoError] = store_uploaded_image($req->files['photo'] ?? null);
    if ($photoError !== '') {
        person_form($kind, $existing ? (string) $existing['id'] : null, $photoError, $values);
        return;
    }
    if ($photo === '') {
        $photo = ($existing && $req->form('remove_photo') !== '1') ? (string) $existing['photo'] : '';
    }
    $now = now_iso();
    $fields = [$values['name'], $values['description'], $values['expertise'], $values['region'], $photo, $kind === 'guest' ? $values['featured_on'] : '', $kind === 'expert' ? (int) $values['position'] : 0, $now];
    if ($existing) {
        db()->execute('UPDATE people SET name=?,description=?,expertise=?,region=?,photo=?,featured_on=?,position=?,updated_at=? WHERE id=?', [...$fields, (int) $existing['id']]);
    } else {
        db()->execute('INSERT INTO people(name,description,expertise,region,photo,featured_on,position,updated_at,kind,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)', [...$fields, $kind, $now]);
    }
    redirect('/admin/' . $labels['path']);
}

function delete_person(string $kind, string $personId): void
{
    if (ctype_digit($personId)) {
        db()->execute('DELETE FROM people WHERE id=? AND kind=?', [(int) $personId, $kind]);
    }
    redirect('/admin/' . PEOPLE_KINDS[$kind]['path']);
}
