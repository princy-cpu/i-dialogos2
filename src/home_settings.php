<?php
/**
 * Réglages de la page d'accueil (/admin/accueil, administrateurs) : éléments affichés pour l'article à la une et pour
 * les analyses de la colonne de droite, choix de l'article à la une, nombre d'analyses et textes mis en avant (encadrés
 * fixes sous les articles, qui restent en place quelles que soient les publications). Stockés en JSON dans
 * site_settings (clé « home ») ; une valeur absente reprend la présentation de la maquette.
 */
declare(strict_types=1);

const HOME_PARTS = ['image' => 'Image', 'title' => 'Titre', 'author' => 'Auteur', 'excerpt' => 'Extrait de texte', 'date' => 'Date'];
const HOME_ZONES = ['featured' => 'Article à la une', 'column' => 'Colonne « Analyses »'];
const HOME_COLUMN_MIN = 1;
const HOME_COLUMN_MAX = 24;
const HOME_HIGHLIGHTS_MAX = 3;
const HOME_HIGHLIGHT_FIELDS = ['title' => 160, 'text' => 1200, 'link' => 500, 'label' => 60];

function home_default_settings(): array
{
    $all = array_fill_keys(array_keys(HOME_PARTS), true);
    return ['featured' => $all, 'column' => $all, 'featured_id' => '', 'column_count' => 12, 'highlights' => []];
}

function home_settings(Db $db): array
{
    $settings = home_default_settings();
    $stored = json_decode((string) $db->value("SELECT value FROM site_settings WHERE name='home'"), true);
    if (!is_array($stored)) {
        return $settings;
    }
    foreach (array_keys(HOME_ZONES) as $zone) {
        foreach (array_keys(HOME_PARTS) as $part) {
            if (isset($stored[$zone][$part])) {
                $settings[$zone][$part] = (bool) $stored[$zone][$part];
            }
        }
    }
    $settings['featured_id'] = (string) ($stored['featured_id'] ?? '');
    $settings['column_count'] = max(HOME_COLUMN_MIN, min(HOME_COLUMN_MAX, (int) ($stored['column_count'] ?? 12)));
    $settings['highlights'] = clean_highlights(is_array($stored['highlights'] ?? null) ? $stored['highlights'] : []);
    return $settings;
}

/** Textes mis en avant : un titre ou un texte au moins, lien interne (/…) ou https:// seulement. */
function clean_highlights(array $items): array
{
    $clean = [];
    foreach (array_slice($items, 0, HOME_HIGHLIGHTS_MAX) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = [];
        foreach (HOME_HIGHLIGHT_FIELDS as $field => $max) {
            $row[$field] = mb_cut(trim((string) ($item[$field] ?? '')), $max);
        }
        if ($row['link'] !== '' && !preg_match('#^(/(?!/)|https?://)[^\s"<>]*$#D', $row['link'])) {
            $row['link'] = clean_link_url($row['link']);
        }
        if ($row['title'] !== '' || $row['text'] !== '') {
            $clean[] = $row;
        }
    }
    return $clean;
}

/** Encadrés « mis en avant » de l'accueil, sous les articles. */
function home_highlights_block(array $highlights): string
{
    if (!$highlights) {
        return '';
    }
    $items = '';
    foreach ($highlights as $item) {
        $title = $item['title'] !== '' ? '<h2>' . esc($item['title']) . '</h2>' : '';
        $text = $item['text'] !== '' ? implode('', array_map(fn($paragraph) => '<p>' . esc($paragraph) . '</p>', preg_split('/\R{2,}/u', $item['text']) ?: [])) : '';
        $link = $item['link'] !== '' ? '<a class="theme-button" href="' . esc($item['link']) . '">' . esc($item['label'] ?: 'En savoir plus') . ' →</a>' : '';
        $items .= '<article class="home-highlight">' . $title . $text . $link . '</article>';
    }
    return '<section class="home-highlights count-' . count($highlights) . '" aria-label="À retenir">' . $items . '</section>';
}

function home_settings_page(): void
{
    $db = db();
    $settings = home_settings($db);
    $zones = '';
    foreach (HOME_ZONES as $zone => $label) {
        $checks = '';
        foreach (HOME_PARTS as $part => $partLabel) {
            $checks .= '<label class="check-option"><input type="checkbox" name="' . $zone . '_' . $part . '" value="1" ' . ($settings[$zone][$part] ? 'checked' : '') . '>' . $partLabel . '</label>';
        }
        $zones .= '<fieldset class="admin-fieldset home-parts"><legend>' . esc($label) . '</legend>' . $checks . '</fieldset>';
    }
    $articles = $db->all("SELECT id, title, published_at FROM articles WHERE status='published' AND archived=0 ORDER BY published_at DESC NULLS LAST, created_at DESC LIMIT 100");
    $options = '<option value="">Le plus récent (automatique)</option>';
    foreach ($articles as $article) {
        $options .= '<option value="' . esc($article['id']) . '" ' . selected_attr($article['id'] === $settings['featured_id']) . '>' . esc($article['title']) . ' · ' . esc(substr((string) $article['published_at'], 0, 10)) . '</option>';
    }
    $flash = request()->q('saved') !== '' ? '<p class="success-message">Réglages enregistrés : la page d’accueil est à jour.</p>' : '';
    $highlights = '';
    for ($index = 0; $index < HOME_HIGHLIGHTS_MAX; $index++) {
        $item = $settings['highlights'][$index] ?? array_fill_keys(array_keys(HOME_HIGHLIGHT_FIELDS), '');
        $highlights .= '<fieldset class="admin-fieldset home-highlight-edit"><legend>Texte mis en avant ' . ($index + 1) . '</legend>'
            . '<label class="admin-field">Titre<input name="highlight_title[]" maxlength="160" value="' . esc($item['title']) . '"></label>'
            . '<label class="admin-field">Texte<textarea name="highlight_text[]" rows="3" maxlength="1200">' . esc($item['text']) . '</textarea></label>'
            . '<label class="admin-field">Lien (facultatif)<input name="highlight_link[]" maxlength="500" value="' . esc($item['link']) . '" placeholder="/article/… ou https://…"></label>'
            . '<label class="admin-field">Texte du bouton<input name="highlight_label[]" maxlength="60" value="' . esc($item['label']) . '" placeholder="En savoir plus"></label></fieldset>';
    }
    $highlights = '<div class="admin-field home-highlights-edit"><strong>Textes mis en avant</strong><small class="field-help">Encadrés fixes affichés sous les articles de l’accueil : ils restent en place quand de nouveaux articles sont publiés. Laissez le titre et le texte vides pour retirer un encadré. Pour garder un article en tête, cochez « Épingler en tête » dans l’article.</small><div class="home-highlight-grid">' . $highlights . '</div></div>';
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Configuration du site</span><h1>Page d’accueil</h1><p>Choisissez l’article à la une et les éléments affichés pour chaque article de l’accueil.</p></div><a class="plain-button" href="/" target="_blank" rel="noopener">Voir l’accueil ↗</a></div>' . $flash
        . '<section class="admin-panel"><form method="post" action="/admin/accueil" class="event-admin-form admin-form-grid">' . csrf_field()
        . '<label class="admin-field">Article à la une<select name="featured_id">' . $options . '</select><small class="field-help">Les autres articles suivent, du plus récent au plus ancien. Un article archivé ne s’affiche pas sur l’accueil.</small></label>'
        . '<label class="admin-field">Nombre d’analyses dans la colonne de droite<input type="number" name="column_count" min="' . HOME_COLUMN_MIN . '" max="' . HOME_COLUMN_MAX . '" value="' . $settings['column_count'] . '" required><small class="field-help">Quatre sont visibles, les suivantes en déroulé vertical.</small></label>'
        . $zones . $highlights
        . '<div class="side-actions"><button class="primary-button" type="submit">Enregistrer</button><button class="plain-button" type="submit" name="reset" value="1">Revenir à la présentation de la maquette</button></div></form></section></main>';
    send_body(admin_page('Page d’accueil', $content, 'accueil'));
}

function save_home_settings(): void
{
    $db = db();
    $settings = home_default_settings();
    if (request()->form('reset') === '') {
        foreach (array_keys(HOME_ZONES) as $zone) {
            foreach (array_keys(HOME_PARTS) as $part) {
                $settings[$zone][$part] = request()->form($zone . '_' . $part) === '1';
            }
        }
        $featured = request()->form('featured_id');
        $exists = $featured !== '' && $db->one("SELECT 1 FROM articles WHERE id=? AND status='published' AND archived=0", [$featured]);
        $settings['featured_id'] = $exists ? $featured : '';
        $settings['column_count'] = max(HOME_COLUMN_MIN, min(HOME_COLUMN_MAX, (int) request()->form('column_count', '12')));
        $items = [];
        foreach (request()->formList('highlight_title') as $index => $title) {
            $items[] = ['title' => $title, 'text' => request()->formList('highlight_text')[$index] ?? '', 'link' => request()->formList('highlight_link')[$index] ?? '',
                'label' => request()->formList('highlight_label')[$index] ?? ''];
        }
        $settings['highlights'] = clean_highlights($items);
    } else {
        // « Revenir à la présentation de la maquette » ne touche qu'à l'affichage des articles : les textes restent.
        $settings['highlights'] = home_settings($db)['highlights'];
    }
    $db->execute('INSERT INTO site_settings(name, value) VALUES(?, ?) ON CONFLICT (name) DO UPDATE SET value=EXCLUDED.value',
        ['home', json_encode($settings, JSON_UNESCAPED_UNICODE)]);
    redirect('/admin/accueil?saved=1');
}
