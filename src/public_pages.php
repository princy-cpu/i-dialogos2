<?php
/** Pages publiques : accueil, thématiques, articles, actualités, commentaires. */
declare(strict_types=1);

function themes(): array
{
    return theme_data()['themes'];
}

function default_language(Db $db): string
{
    return (string) ($db->value('SELECT code FROM languages WHERE is_default=1 LIMIT 1') ?? 'fr');
}

function resolve_language(Db $db, string $requested, string ...$texts): string
{
    $requested = strtolower(trim($requested));
    if ($requested !== 'auto' && $db->one('SELECT 1 FROM languages WHERE code=?', [$requested])) {
        return $requested;
    }
    return detect_language(implode("\n", $texts)) ?? default_language($db);
}

/** Langue demandée par ?lang=xx, sinon celle du cookie du sélecteur, sinon la langue par défaut. */
function selected_language(Db $db): string
{
    foreach ([strtolower(trim(request()->q('lang'))), request()->cookie('idialogos_lang')] as $code) {
        if ($code !== '' && $db->one('SELECT code FROM languages WHERE code=? AND enabled=1', [$code])) {
            return $code;
        }
    }
    return default_language($db);
}

function content_lang(array $item): string
{
    return !empty($item['translated_from']) ? (string) ($item['display_language'] ?? '') : (string) ($item['language'] ?? '');
}

function localize_terms(Db $db, array $rows, string $target): array
{
    $source = default_language($db);
    return translate_records($db, array_map(fn($row) => $row + ['language' => $source], $rows), $target, ['name']);
}

function localized_themes(Db $db, string $language): array
{
    return translate_records($db, array_map(fn($theme) => $theme + ['language' => 'fr'], themes()), $language, theme_data()['fields']);
}

function index_page(): string
{
    static $page = null;
    // Fins de ligne normalisées (comme la lecture en mode texte de Python) : le fichier peut venir de Windows.
    return $page ??= str_replace(["\r\n", "\r"], "\n", (string) file_get_contents(app_root() . '/index.html'));
}

/** Sections fixes d'index.html (en français) traduites côté serveur, entre leurs repères. */
function static_sections(): array
{
    $page = index_page();
    $sections = [];
    foreach (theme_data()['sections'] as [$start, $end]) {
        if (str_contains($page, $start) && str_contains($page, $end)) {
            $sections[] = explode($end, explode($start, $page, 2)[1], 2)[0];
        }
    }
    return $sections;
}

function translate_static_sections(Db $db, string $page, string $language): string
{
    foreach (theme_data()['sections'] as [$start, $end]) {
        if (!str_contains($page, $start) || !str_contains($page, $end)) {
            continue;
        }
        [$head, $rest] = explode($start, $page, 2);
        [$section, $tail] = explode($end, $rest, 2);
        $translated = translate_records($db, [['language' => 'fr', 'html' => $section]], $language, [], ['html'])[0];
        $page = $head . $start . $translated['html'] . $end . $tail;
    }
    return $page;
}

/** Prépare les traductions des contenus publiés dans toutes les langues actives (après l'envoi de la page). */
function pretranslate(?array $articleIds = null, ?array $eventIds = null, int $budgetSeconds = 100): void
{
    $db = db();
    if (!$db->value('SELECT pg_try_advisory_lock(?)', [781204])) {
        return;
    }
    $deadline = time() + $budgetSeconds;
    try {
        $targets = array_column($db->all('SELECT code FROM languages WHERE enabled=1'), 'code');
        $source = default_language($db);
        $articles = $db->all("SELECT * FROM articles WHERE status='published'" . ($articleIds !== null ? ' AND id = ANY(?)' : ''), $articleIds !== null ? [$articleIds] : []);
        $events = $db->all("SELECT * FROM news_events WHERE status='published'" . ($eventIds !== null ? ' AND id = ANY(?)' : ''), $eventIds !== null ? [$eventIds] : []);
        $terms = array_map(fn($row) => $row + ['language' => $source], $db->all('SELECT name FROM categories UNION SELECT name FROM tags'));
        $sections = ($articleIds === null && $eventIds === null) ? array_map(fn($html) => ['language' => 'fr', 'html' => $html], static_sections()) : [];
        foreach ($targets as $target) {
            translate_records($db, $sections, $target, [], ['html']);
            translate_records($db, array_map(fn($theme) => $theme + ['language' => 'fr'], themes()), $target, theme_data()['fields']);
            translate_records($db, $terms, $target, ['name']);
            translate_records($db, $articles, $target, ['title', 'excerpt']);
            translate_records($db, $events, $target, ['title', 'summary']);
        }
        foreach ($targets as $target) {
            foreach (array_chunk($articles, 10) as $chunk) {
                if (time() > $deadline) {
                    return;
                }
                translate_records($db, $chunk, $target, [], ['body_html']);
            }
            translate_records($db, $events, $target, [], ['body_html']);
        }
    } finally {
        $db->execute('SELECT pg_advisory_unlock(?)', [781204]);
    }
}

function translation_notice(array $item, string $target, string $href, bool $showingOriginal = false): string
{
    $source = (string) ($item['language'] ?? '');
    if ($showingOriginal) {
        $link = notice_texts($target, $source)[2];
        return '<p class="translation-notice"><a href="' . esc($href) . '">' . esc($link) . ' →</a></p>';
    }
    if (!empty($item['translation_missing'])) {
        return '<p class="translation-notice is-warning">' . esc(notice_texts($target, $source)[3]) . '</p>';
    }
    if (!empty($item['translated_from'])) {
        [$message, $originalLink] = notice_texts($target, $source);
        $separator = str_contains($href, '?') ? '&' : '?';
        return '<p class="translation-notice">' . esc($message) . ' <a href="' . esc($href . $separator) . 'original=1" lang="' . esc($source) . '">' . esc($originalLink) . '</a></p>';
    }
    return '';
}

function theme_links(array $themes, string $current = ''): string
{
    $out = '';
    foreach ($themes as $theme) {
        if ($theme['id'] !== $current) {
            $out .= '<a href="/thematique/' . q($theme['id']) . '" lang="' . esc(content_lang($theme)) . '">' . esc($theme['title']) . '</a>';
        }
    }
    return $out;
}

function explore_themes(array $themes, string $heading, string $current = ''): string
{
    return '<section class="morethemes" id="explorer-thematiques"><h2>' . esc($heading) . '</h2><div class="themegrid">' . theme_links($themes, $current) . '</div></section>';
}

/** Bloc d'une thématique selon les maquettes 7 à 13 : image à gauche, question et texte à droite. */
function theme_block(array $theme, string $moreHref, string $titleTag = 'h2'): string
{
    $question = $theme['question'] ? '<h3>' . esc($theme['question']) . '</h3>' : '';
    $paragraphs = '';
    foreach (['text', 'text2'] as $key) {
        if ($theme[$key]) {
            $paragraphs .= '<p>' . esc($theme[$key]) . '</p>';
        }
    }
    $signature = $theme['signature'] ? '<p class="mk-signature">' . esc($theme['signature']) . '</p>' : '';
    return '<article class="mk-theme" id="theme-' . esc($theme['id']) . '" lang="' . esc(content_lang($theme)) . '"><' . $titleTag . ' class="mk-page-title">' . esc($theme['title']) . '</' . $titleTag . '><div class="mk-split"><img src="/assets/' . esc($theme['image']) . '" alt="Illustration : ' . esc($theme['title']) . '" loading="lazy"><div class="mk-theme-text">' . $question . $paragraphs . $signature . '<a class="mk-more" href="' . esc($moreHref) . '">Voir plus d’informations<br>' . esc($theme['button']) . '</a></div></div></article>';
}

/** Bouton « Faire un don » de l'en-tête (masqué tant que DONATION_URL n'est pas réglée). */
function donate_button(): string
{
    $url = donation_url();
    return $url === '' ? '' : '<a class="donate-button" href="' . esc($url) . '" target="_blank" rel="noopener" aria-label="Faire un don"><span aria-hidden="true">♥</span> <span class="donate-label">Faire un don</span></a>';
}

/** Boutons du bandeau d'appels à l'action du pied de page (chacun masqué si son adresse n'est pas réglée). */
function footer_cta_link(string $url, string $label, string $icon, string $class = ''): string
{
    if ($url === '') {
        return '';
    }
    return '<a class="footer-cta-button' . ($class !== '' ? ' ' . $class : '') . '" href="' . esc($url) . '" target="_blank" rel="noopener"><span aria-hidden="true">' . $icon . '</span> <span>' . esc($label) . '</span></a>';
}

/** Remplace les emplacements réservés d'index.html par les liens configurés. */
function site_links(string $html): string
{
    return str_replace(
        ['<span id="donate-slot"></span>', '<span id="footer-donate-slot"></span>', '<span id="membership-slot"></span>'],
        [donate_button(), footer_cta_link(donation_url(), 'Faire un don', '♥', 'is-donate'), footer_cta_link(membership_url(), 'Devenir adhérent', '★')],
        $html
    );
}

/**
 * Lien de la page courante mis en évidence dans le menu (même style que le survol) : « Les Notes » pour les listes
 * et articles, « Thématiques » (et le thème ouvert) sur une page de thématique.
 */
function mark_active_nav(string $header, string $path): string
{
    if ($path === '/analyses' || str_starts_with($path, '/article/')) {
        return str_replace('<a href="/analyses">Les Notes</a>', '<a href="/analyses" class="is-active" aria-current="page">Les Notes</a>', $header);
    }
    if ($path === '/invites-et-experts') {
        return str_replace('<a href="#experts">Experts</a>', '<a href="#experts" class="is-active" aria-current="page">Experts</a>', $header);
    }
    if (preg_match('#^/thematique/([a-z0-9-]+)$#', $path, $match)) {
        $header = str_replace('<summary>Thématiques</summary>', '<summary class="is-active">Thématiques</summary>', $header);
        return str_replace('<a href="/thematique/' . $match[1] . '"', '<a href="/thematique/' . $match[1] . '" aria-current="page"', $header);
    }
    return $header;
}

/** En-tête et pied de page de l'accueil (index.html), réutilisés sur toutes les pages publiques. */
function site_chrome(array $themes): array
{
    $page = index_page();
    $headerStart = strpos($page, '<div class="world-bar">');
    $headerEnd = strpos($page, '</header>') + strlen('</header>');
    $footerStart = strpos($page, '<footer');
    $footerEnd = strpos($page, '</footer>') + strlen('</footer>');
    $header = substr($page, $headerStart, $headerEnd - $headerStart);
    $footer = substr($page, $footerStart, $footerEnd - $footerStart);
    $footer = site_links($footer);
    $header = str_replace('<div id="themes-menu"></div>', '<div id="themes-menu">' . theme_links($themes) . '</div>', $header);
    $header = mark_active_nav(site_links($header), request()->path);
    $parts = [];
    foreach ([$header, $footer] as $part) {
        // Les ancres de l'accueil (#apropos…) pointent vers la page d'accueil depuis les autres pages.
        $part = str_replace(['href="#atelier"', 'href="#'], ['href="/admin"', 'href="/#'], $part);
        $parts[] = str_replace('href="/#accueil" class="back-top"', 'href="#accueil" class="back-top"', $part);
    }
    return $parts;
}

function site_page(string $title, string $content): string
{
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc($title) . ' · I-Dialogos</title>' . HEAD_ASSETS . '<link rel="stylesheet" href="/style.css"><link rel="stylesheet" href="/admin.css"></head><body>' . $content . '<script src="/admin.js" defer></script></body></html>';
}

/** Page publique complète : en-tête du site, contenu, pied de page et scripts de la vitrine. */
function public_page(string $title, string $content, int $status = 200): void
{
    $db = db();
    $language = selected_language($db);
    [$header, $footer] = site_chrome(localized_themes($db, $language));
    send_body('<!doctype html><html lang="' . esc($language) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc($title) . ' · I-Dialogos</title>' . HEAD_ASSETS . '<link rel="stylesheet" href="/style.css"><link rel="stylesheet" href="/admin.css"></head><body class="public-page">' . $header . $content . $footer . '<script src="/app.js" defer></script><script src="/i18n.js" defer></script></body></html>', $status);
}

function not_found_public(): void
{
    public_page('Page introuvable', '<main class="article-page"><h1>Page introuvable</h1><p>Cette page n’existe pas ou a été déplacée.</p><a class="theme-button" href="/">Retour à l’accueil</a></main>', 404);
}

function article_date(array $article): string
{
    return (string) (($article['published_at'] ?? '') ?: $article['created_at']);
}

function public_featured_article(array $article): string
{
    $image = $article['featured_image'] ? '<img class="featured-image" src="' . esc($article['featured_image']) . '" alt="Image mise en avant de ' . esc($article['title']) . '">' : '<span class="featured-image-placeholder">I·D</span>';
    $date = long_date(article_date($article), $article['display_language'] ?? 'fr');
    $href = '/article/' . q($article['slug']);
    return '<article class="featured-article" lang="' . esc(content_lang($article)) . '"><a href="' . $href . '" class="image-link">' . $image . '</a><h1><a href="' . $href . '">' . esc($article['title']) . '</a></h1><p class="featured-author">' . esc($article['author']) . '</p><p>' . esc($article['excerpt']) . ' <time class="mk-date">' . esc($date) . '</time></p></article>';
}

function public_analysis_article(array $article): string
{
    $image = $article['featured_image'] ? '<img src="' . esc($article['featured_image']) . '" alt="Image mise en avant de ' . esc($article['title']) . '" loading="lazy">' : '<span class="analysis-image-placeholder">I·D</span>';
    $date = long_date(article_date($article), $article['display_language'] ?? 'fr');
    $href = '/article/' . q($article['slug']);
    return '<article class="analysis-item" lang="' . esc(content_lang($article)) . '"><a href="' . $href . '" tabindex="-1">' . $image . '</a><div><h3><a href="' . $href . '">' . esc($article['title']) . '</a> <span class="analysis-author">' . esc($article['author']) . '</span></h3><p class="clamp-3">' . esc($article['excerpt']) . '</p><time class="mk-date">' . esc($date) . '</time></div></article>';
}

function public_news_event_card(array $item, bool $clamp = true): string
{
    $label = $item['kind'] === 'event' ? 'Événement' : 'Actualité';
    $date = (string) ($item['starts_at'] ?: $item['created_at']);
    $when = $date !== '' ? '<time>' . esc(substr($date, 0, 10)) . '</time>' : '';
    $location = $item['location'] ? '<span>' . esc($item['location']) . '</span>' : '';
    return '<article class="event-entry" lang="' . esc(content_lang($item)) . '"><span class="event-kind">' . $label . '</span><h3><a href="/actualite/' . q($item['slug']) . '">' . esc($item['title']) . '</a></h3><p' . ($clamp ? ' class="clamp-3"' : '') . '>' . esc($item['summary']) . '</p><div class="event-meta">' . $when . $location . '</div></article>';
}

function public_card(array $article, array $cats, array $tags): string
{
    $image = $article['featured_image'] ? '<img src="' . esc($article['featured_image']) . '" alt="" loading="lazy">' : '<span class="cover-placeholder">I·D</span>';
    $category = implode(', ', array_column($cats, 'name')) ?: 'Les Notes';
    $tagHtml = '';
    foreach ($tags as $row) {
        $tagHtml .= '<a href="/analyses?tag=' . q($row['slug']) . '">#' . esc($row['name']) . '</a>';
    }
    $href = '/article/' . q($article['slug']);
    return '<article class="public-article-card" lang="' . esc(content_lang($article)) . '" data-categories="' . esc(implode(' ', array_column($cats, 'slug'))) . '" data-tags="' . esc(implode(' ', array_column($tags, 'slug'))) . '"><a class="public-card-image" href="' . $href . '">' . $image . '</a><div class="public-card-body"><div class="article-card-meta"><span>' . esc($category) . '</span><time>' . esc(substr(article_date($article), 0, 10)) . '</time></div><h3><a href="' . $href . '">' . esc($article['title']) . '</a></h3><p>' . esc($article['excerpt']) . '</p><div class="tag-chips">' . $tagHtml . '</div><a class="read-link" href="' . $href . '">Lire l’article <span>→</span></a></div></article>';
}

function sort_by_name(array $rows): array
{
    usort($rows, fn($a, $b) => strcmp(mb_strtolower($a['name'], 'UTF-8'), mb_strtolower($b['name'], 'UTF-8')));
    return $rows;
}

/** Bloc « Actualités / Événements » de l'accueil : carrousel manuel dès qu'il y a plus d'un élément. */
function home_news_block(array $items): string
{
    if (!$items) {
        return '<p class="public-empty-analysis" data-empty="events"></p>';
    }
    $cards = implode('', array_map(fn($item) => public_news_event_card($item), $items));
    if (count($items) === 1) {
        return $cards;
    }
    return '<div class="news-carousel" data-carousel aria-roledescription="carrousel"><div class="news-track" tabindex="0" role="region" aria-label="Actualités et événements">' . $cards . '</div></div>';
}

function public_home(): void
{
    $db = db();
    $language = selected_language($db);
    $articles = $db->all("SELECT * FROM articles WHERE status='published' ORDER BY published_at DESC NULLS LAST, created_at DESC, id LIMIT 5");
    $newsEvents = $db->all("SELECT * FROM news_events WHERE status='published' ORDER BY CASE WHEN starts_at='' THEN created_at ELSE starts_at END DESC, id DESC LIMIT 4");
    $articles = translate_records($db, $articles, $language, ['title', 'excerpt']);
    $newsEvents = translate_records($db, $newsEvents, $language, ['title', 'summary']);
    $themes = localized_themes($db, $language);
    $page = index_page();
    $featured = $articles ? public_featured_article($articles[0]) : '<div class="public-empty-featured" data-empty="articles"></div>';
    $page = str_replace('<div id="database-featured-article"></div>', $featured, $page);
    $analysis = implode('', array_map('public_analysis_article', array_slice($articles, 1, 4))) ?: '<p class="public-empty-analysis" data-empty="articles"></p>';
    $page = str_replace('<div id="database-analysis-articles"></div>', $analysis, $page);
    $page = str_replace('<div id="database-news-events"></div>', home_news_block($newsEvents), $page);
    $page = (string) preg_replace('/\s*<section class="section editorial-workspace" id="atelier">.*?<\/section>\s*/s', "\n", $page);
    $page = (string) preg_replace('/<dialog class="modal-dialog.*?<\/dialog>\s*/s', '', $page);
    $page = str_replace('href="#atelier"', 'href="/admin"', $page);
    $page = str_replace('<div id="themes-menu"></div>', '<div id="themes-menu">' . theme_links($themes) . '</div>', $page);
    $page = site_links($page);
    // Invité le plus récent et experts enregistrés (avant la traduction des sections fixes, qui les traduit aussi).
    $page = home_people_blocks($db, $page);
    if ($language !== 'fr') {
        $page = translate_static_sections($db, $page, $language);
    }
    $page = str_replace('<div id="database-explore-themes"></div>', explore_themes($themes, 'Explorer nos thématiques'), $page);
    $blocks = implode('', array_map(fn($theme) => theme_block($theme, '/thematique/' . q($theme['id'])), $themes));
    $page = str_replace('<div id="database-theme-blocks"></div>', $blocks, $page);
    send_body($page);
}

/** Anciennes adresses de l'accueil filtré (/?category=…, /?tag=…, /?q=…) : redirection permanente vers /analyses. */
function legacy_home_filters_redirect(): bool
{
    $map = ['category' => 'categorie', 'tag' => 'tag', 'q' => 'q'];
    $params = [];
    foreach ($map as $old => $new) {
        $value = filter_value($old);
        if ($value !== '') {
            $params[$new] = $value;
        }
    }
    if (!$params) {
        return false;
    }
    redirect(list_href('/analyses', $params), [], 301);
    return true;
}

function article_taxonomy(Db $db, string $articleId): array
{
    return [
        $db->all('SELECT categories.* FROM categories JOIN article_categories ON categories.id=article_categories.category_id WHERE article_categories.article_id=? ORDER BY categories.name', [$articleId]),
        $db->all('SELECT tags.* FROM tags JOIN article_tags ON tags.id=article_tags.tag_id WHERE article_tags.article_id=? ORDER BY tags.name', [$articleId]),
    ];
}

function public_theme(string $themeId): void
{
    if (trim($themeId, '/') === '') {
        redirect('/#thematiques');
        return;
    }
    $aliases = theme_data()['aliases'];
    if (isset($aliases[$themeId])) {
        redirect('/thematique/' . $aliases[$themeId]);
        return;
    }
    $theme = null;
    foreach (themes() as $item) {
        if ($item['id'] === $themeId) {
            $theme = $item;
        }
    }
    if (!$theme) {
        public_page('Thématique introuvable', '<main class="article-page"><h1>Thématique introuvable</h1><a href="/#thematiques">Retour à l’accueil</a></main>', 404);
        return;
    }
    $db = db();
    $language = selected_language($db);
    $themes = localized_themes($db, $language);
    $articles = $db->all("SELECT articles.* FROM articles JOIN article_categories ON article_categories.article_id=articles.id JOIN categories ON categories.id=article_categories.category_id WHERE articles.status='published' AND categories.slug=? ORDER BY articles.published_at DESC NULLS FIRST, articles.created_at DESC", [$theme['category']]);
    $taxonomy = [];
    $allCats = [];
    $allTags = [];
    foreach ($articles as $article) {
        $taxonomy[$article['id']] = article_taxonomy($db, $article['id']);
        foreach ($taxonomy[$article['id']][0] as $row) {
            $allCats[$row['id']] = $row;
        }
        foreach ($taxonomy[$article['id']][1] as $row) {
            $allTags[$row['id']] = $row;
        }
    }
    $catsById = array_column(localize_terms($db, array_values($allCats), $language), null, 'id');
    $tagsById = array_column(localize_terms($db, array_values($allTags), $language), null, 'id');
    $articles = translate_records($db, $articles, $language, ['title', 'excerpt']);
    $current = null;
    foreach ($themes as $item) {
        if ($item['id'] === $themeId) {
            $current = $item;
        }
    }
    $cards = '';
    foreach ($articles as $article) {
        [$cats, $tags] = $taxonomy[$article['id']];
        $cards .= public_card($article, array_map(fn($row) => $catsById[$row['id']], $cats), array_map(fn($row) => $tagsById[$row['id']], $tags));
    }
    $listing = $cards ? '<div class="public-article-grid theme-articles">' . $cards . '</div>' : '<p class="theme-empty">Aucune publication pour cette thématique pour le moment.</p>';
    $body = '<main class="article-page theme-page">' . theme_block($current, '#theme-articles', 'h1') . '<section id="theme-articles"><h2 class="theme-subtitle">Analyses et contributions</h2>' . $listing . '<a class="theme-button" href="/analyses?categorie=' . q($theme['category']) . '">Toutes les analyses →</a></section>' . explore_themes($themes, 'Autres thématiques', $themeId) . '</main>';
    public_page($current['title'], $body);
}

function page_href(string $base): string
{
    $lang = request()->q('lang');
    return $base . ($lang !== '' ? '?lang=' . q($lang) : '');
}

function public_news_event(string $slug): void
{
    $db = db();
    $item = $db->one("SELECT * FROM news_events WHERE slug=? AND status='published'", [$slug]);
    if (!$item) {
        public_page('Contenu introuvable', '<main class="article-page"><h1>Contenu introuvable</h1><a href="/actualites">Retour aux actualités</a></main>', 404);
        return;
    }
    $language = selected_language($db);
    $showOriginal = request()->q('original') !== '';
    if (!$showOriginal) {
        $item = translate_records($db, [$item], $language, ['title', 'summary'], ['body_html'])[0];
    }
    $notice = translation_notice($item, $language, page_href('/actualite/' . q($slug)), $showOriginal && $item['language'] !== $language);
    $label = $item['kind'] === 'event' ? 'Événement' : 'Actualité';
    $image = $item['image_url'] ? '<img class="article-featured" src="' . esc($item['image_url']) . '" alt="Illustration : ' . esc($item['title']) . '">' : '';
    $dates = implode(' · ', array_map(fn($value) => esc(substr($value, 0, 10)), array_filter([$item['starts_at'], $item['ends_at']])));
    $location = $item['location'] ? '<span>' . esc($item['location']) . '</span>' : '';
    $lang = $showOriginal ? $item['language'] : content_lang($item);
    $body = '<main class="article-page" lang="' . esc($lang) . '">' . $notice . '<span class="eyebrow">' . $label . '</span><h1>' . esc($item['title']) . '</h1><div class="article-byline">' . $dates . (($dates && $location) ? ' · ' : '') . $location . '</div>' . $image . '<p class="article-excerpt">' . esc($item['summary']) . '</p><div class="article-rich-content">' . $item['body_html'] . '</div><a class="back-public" href="/actualites">← Toutes les actualités</a></main>';
    public_page($item['title'], $body);
}

/** Formulaire de commentaire : erreurs en tête, saisies conservées, champ piège invisible. */
function comment_form(string $slug, array $state): string
{
    $values = $state['values'] ?? [];
    $errors = $state['errors'] ?? [];
    $invalid = fn(string $field) => isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="comment-error-' . $field . '"' : '';
    $message = fn(string $field) => isset($errors[$field]) ? '<span class="field-error" id="comment-error-' . $field . '">' . esc($errors[$field]) . '</span>' : '';
    $summary = $errors ? '<div class="form-error" role="alert"><strong>Le commentaire n’a pas été envoyé.</strong> ' . esc(implode(' ', $errors)) . '</div>' : '';
    return '<form class="comment-form" method="post" action="/article/' . q($slug) . '/comment#comments" novalidate>' . csrf_field() . '<h3>Laisser un commentaire</h3>' . $summary
        . '<label>Nom<input name="author" required maxlength="100" autocomplete="name" value="' . esc($values['author'] ?? '') . '"' . $invalid('author') . '>' . $message('author') . '</label>'
        . '<label>Adresse e-mail (facultatif)<input type="email" name="email" maxlength="200" autocomplete="email" value="' . esc($values['email'] ?? '') . '"' . $invalid('email') . '>' . $message('email') . '<small>Elle ne sera pas publiée.</small></label>'
        . '<label>Commentaire<textarea name="body" required minlength="3" maxlength="4000" rows="5"' . $invalid('body') . '>' . esc($values['body'] ?? '') . '</textarea>' . $message('body') . '</label>'
        . '<input class="hp-field" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">'
        . '<button class="primary-button">Envoyer pour modération</button><small>Votre commentaire sera visible après validation par l’équipe.</small></form>';
}

/**
 * Page d'un article. $commentState (erreurs et saisies) est fourni quand un commentaire est refusé : la page est
 * réaffichée avec les messages et le texte saisi, sans rien perdre.
 */
function public_article(string $slug, array $commentState = [], int $status = 200): void
{
    $db = db();
    $article = $db->one("SELECT * FROM articles WHERE slug=? AND status='published'", [$slug]);
    if (!$article) {
        public_page('Article introuvable', '<main class="article-page"><h1>Article introuvable</h1><a href="/">Retour à l’accueil</a></main>', 404);
        return;
    }
    [$cats, $tags] = article_taxonomy($db, $article['id']);
    $comments = $db->all("SELECT * FROM comments WHERE article_id=? AND status='approved' ORDER BY created_at", [$article['id']]);
    $language = selected_language($db);
    $showOriginal = request()->q('original') !== '';
    if (!$showOriginal) {
        $article = translate_records($db, [$article], $language, ['title', 'excerpt'], ['body_html'])[0];
        $cats = localize_terms($db, $cats, $language);
        $tags = localize_terms($db, $tags, $language);
    }
    $notice = translation_notice($article, $language, page_href('/article/' . q($slug)), $showOriginal && $article['language'] !== $language);
    $topicChips = '';
    foreach ($cats as $row) {
        $topicChips .= '<a href="/analyses?categorie=' . q($row['slug']) . '">' . esc($row['name']) . '</a>';
    }
    $tagsHtml = '';
    foreach ($tags as $row) {
        $tagsHtml .= '<a href="/analyses?tag=' . q($row['slug']) . '">#' . esc($row['name']) . '</a>';
    }
    $image = $article['featured_image'] ? '<img class="article-featured" src="' . esc($article['featured_image']) . '" alt="Image mise en avant de l’article">' : '';
    $commentsBlock = '';
    if ((int) $article['comments_enabled']) {
        $items = '';
        foreach ($comments as $row) {
            $items .= '<article class="comment"><strong>' . esc($row['author']) . '</strong><time>' . esc(long_date($row['created_at'], $language)) . '</time><p>' . nl2br(esc($row['body']), false) . '</p></article>';
        }
        $flash = request()->q('sent') !== '' && !$commentState ? '<p class="success-message" role="status">Votre commentaire a été envoyé et sera publié après modération.</p>' : '';
        $commentsBlock = '<section id="comments" class="comments-area"><h2>Commentaires (' . count($comments) . ')</h2>' . $flash . ($items ?: '<p>Soyez le premier à commenter cet article.</p>') . comment_form($slug, $commentState) . '</section>';
    }
    $lang = $showOriginal ? $article['language'] : content_lang($article);
    $date = long_date($article['published_at'] ?: $article['created_at'], $language);
    $body = '<main class="article-layout"><article class="article-page" lang="' . esc($lang) . '">' . $notice . '<div class="article-topic-chips">' . $topicChips . '</div><h1>' . esc($article['title']) . '</h1><div class="article-byline">' . esc($article['author']) . ' · ' . esc($date) . '</div>' . $image . '<p class="article-excerpt">' . esc($article['excerpt']) . '</p><div class="article-rich-content">' . $article['body_html'] . '</div><div class="article-topic-chips article-tag-list">' . $tagsHtml . '</div>' . article_pager($db, $article, $language) . '</article>'
        . related_aside($db, $article, $language)
        . '<div class="article-comments">' . $commentsBlock . '<a class="back-public" href="/">← Retour à l’accueil</a></div></main>';
    public_page($article['title'], $body, $status);
}

/**
 * Envoi d'un commentaire : jeton de session, champ piège, contrôles, limite de 3 commentaires par adresse IP en
 * 10 minutes, puis enregistrement « en attente » (modération dans l'administration).
 */
function comment_post(string $slug): void
{
    $db = db();
    $article = $db->one("SELECT id,comments_enabled FROM articles WHERE slug=? AND status='published'", [$slug]);
    if (!$article || !(int) $article['comments_enabled']) {
        send_body('Commentaires fermés.', 403, 'text/plain; charset=utf-8');
        return;
    }
    $values = ['author' => mb_cut(trim(request()->form('author')), 100), 'email' => mb_cut(trim(request()->form('email')), 200), 'body' => mb_cut(trim(request()->form('body')), 4000)];
    if (!csrf_ok()) {
        public_article($slug, ['errors' => ['session' => 'Votre session a expiré : vérifiez votre commentaire puis renvoyez-le.'], 'values' => $values], 403);
        return;
    }
    if (request()->form('website') !== '') {
        // Champ piège rempli : robot. Même réponse qu'un envoi réussi, rien n'est enregistré.
        redirect('/article/' . q($slug) . '?sent=1#comments');
        return;
    }
    $errors = [];
    if ($values['author'] === '') {
        $errors['author'] = 'Indiquez votre nom.';
    }
    if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'L’adresse e-mail n’est pas valide.';
    }
    if (mb_strlen($values['body']) < 3) {
        $errors['body'] = 'Écrivez votre commentaire (3 caractères au moins).';
    }
    if ($errors) {
        public_article($slug, ['errors' => $errors, 'values' => $values], 422);
        return;
    }
    if (rate_limited($db, 'comment', 3, 600)) {
        public_article($slug, ['errors' => ['rate' => 'Vous avez envoyé plusieurs commentaires en peu de temps. Réessayez dans quelques minutes.'], 'values' => $values], 429);
        return;
    }
    $db->execute('INSERT INTO comments(article_id,author,email,body,status,created_at) VALUES(?,?,?,?,?,?)', [$article['id'], $values['author'], $values['email'], $values['body'], 'pending', now_iso()]);
    redirect('/article/' . q($slug) . '?sent=1#comments');
}
