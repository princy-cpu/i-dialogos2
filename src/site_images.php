<?php
/**
 * Images du site (/admin/images, administrateurs) : chaque image fixe du dossier assets/ (logo, thématiques, pages
 * institutionnelles, portraits, partenaires) peut être remplacée par une image téléversée, puis rétablie. Les
 * remplacements sont stockés dans site_settings (clé « images » : {"assets/x.webp": "/uploads/…"}) et appliqués au
 * moment d'envoyer la page (apply_image_overrides) : les fichiers d'origine ne sont jamais modifiés.
 */
declare(strict_types=1);

const SITE_IMAGE_GROUPS = ['' => 'Images générales (accueil, thématiques, gouvernance)', 'pages' => 'Pages institutionnelles', 'people' => 'Portraits',
    'partners' => 'Logos des partenaires'];

/** Images remplaçables : [dossier => [chemin relatif, …]], sans les drapeaux ni les scripts. */
function site_image_catalog(): array
{
    $catalog = array_fill_keys(array_keys(SITE_IMAGE_GROUPS), []);
    $root = app_root() . '/assets';
    foreach (array_keys(SITE_IMAGE_GROUPS) as $folder) {
        foreach (glob($root . ($folder !== '' ? '/' . $folder : '') . '/*.{webp,png,jpg,jpeg,gif,svg}', GLOB_BRACE) ?: [] as $file) {
            $catalog[$folder][] = 'assets/' . ($folder !== '' ? $folder . '/' : '') . basename($file);
        }
    }
    return $catalog;
}

function site_image_overrides(Db $db): array
{
    static $cache = null;
    if ($cache === null) {
        $stored = json_decode((string) $db->value("SELECT value FROM site_settings WHERE name='images'"), true);
        $cache = is_array($stored) ? array_filter($stored, fn($value, $key) => is_string($value) && str_starts_with($value, '/uploads/') && str_starts_with((string) $key, 'assets/'), ARRAY_FILTER_USE_BOTH) : [];
    }
    return $cache;
}

/** Remplace dans la page les images fixes modifiées depuis l'administration (src="assets/…" ou src="/assets/…"). */
function apply_image_overrides(string $html): string
{
    try {
        $overrides = site_image_overrides(db());
    } catch (Throwable $error) {
        return $html;
    }
    if (!$overrides) {
        return $html;
    }
    return (string) preg_replace_callback('#(src|srcset)="/?(assets/[A-Za-z0-9/_.-]+)"#', fn(array $m) => isset($overrides[$m[2]]) ? $m[1] . '="' . esc($overrides[$m[2]]) . '"' : $m[0], $html);
}

function site_images_page(): void
{
    $overrides = site_image_overrides(db());
    $flash = match (request()->q('ok')) {
        'remplacee' => '<p class="success-message">Image remplacée : elle s’affiche maintenant sur le site.</p>',
        'retablie' => '<p class="success-message">Image d’origine rétablie.</p>',
        default => '',
    };
    $error = request()->q('erreur') !== '' ? '<div class="form-error">' . esc(mb_cut(request()->q('erreur'), 200)) . '</div>' : '';
    $sections = '';
    foreach (site_image_catalog() as $folder => $images) {
        if (!$images) {
            continue;
        }
        $cards = '';
        foreach ($images as $path) {
            $current = $overrides[$path] ?? '/' . $path;
            $changed = isset($overrides[$path]);
            $reset = $changed ? '<button class="plain-button" type="submit" name="reset" value="1">Rétablir l’original</button>' : '';
            $cards .= '<form class="site-image-card' . ($changed ? ' is-changed' : '') . '" method="post" action="/admin/images" enctype="multipart/form-data">' . csrf_field()
                . '<input type="hidden" name="path" value="' . esc($path) . '"><div class="site-image-preview"><img src="' . esc($current) . '" alt="" loading="lazy"></div>'
                . '<strong>' . esc(basename($path)) . '</strong>' . ($changed ? '<span class="status-badge published">Remplacée</span>' : '')
                . '<label class="admin-field"><span class="sr-only">Nouvelle image pour ' . esc(basename($path)) . '</span><input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif"></label>'
                . '<div class="side-actions"><button class="primary-button" type="submit">Remplacer</button>' . $reset . '</div></form>';
        }
        $sections .= '<section class="admin-panel"><h2>' . esc(SITE_IMAGE_GROUPS[$folder]) . ' <small>(' . count($images) . ')</small></h2><div class="site-image-grid">' . $cards . '</div></section>';
    }
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Configuration du site</span><h1>Images du site</h1><p>Remplacez une image du site (logo, thématiques, pages, portraits, partenaires) sans toucher aux fichiers d’origine. Les images des articles se changent dans chaque article.</p></div></div>'
        . $flash . $error . $sections . '</main>';
    send_body(admin_page('Images du site', $content, 'images'));
}

function save_site_image(): void
{
    $db = db();
    $path = request()->form('path');
    $known = array_merge(...array_values(site_image_catalog()));
    if (!in_array($path, $known, true)) {
        redirect('/admin/images');
        return;
    }
    $overrides = site_image_overrides($db);
    $previous = $overrides[$path] ?? '';
    if (request()->form('reset') === '1') {
        unset($overrides[$path]);
        $result = 'ok=retablie';
    } else {
        [$upload, $error] = store_uploaded_image(request()->files['image'] ?? null);
        if ($upload === '') {
            redirect('/admin/images?erreur=' . rawurlencode($error ?: 'Choisissez une image à envoyer.'));
            return;
        }
        $overrides[$path] = $upload;
        $result = 'ok=remplacee';
    }
    $db->execute('INSERT INTO site_settings(name, value) VALUES(?, ?) ON CONFLICT (name) DO UPDATE SET value=EXCLUDED.value',
        ['images', json_encode($overrides, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    if ($previous !== '' && !in_array($previous, $overrides, true)) {
        delete_uploaded_file($previous);
    }
    redirect('/admin/images?' . $result);
}
