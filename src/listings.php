<?php
/**
 * Listes paginées et filtrables (/analyses, /actualites), articles similaires et navigation entre articles.
 * Toutes les requêtes sont paramétrées : les saisies du visiteur ne sont jamais concaténées au SQL.
 */
declare(strict_types=1);

const LIST_PAGE_SIZE = 10;      // actualités et événements
const ANALYSES_PAGE_SIZE = 12;  // Notes, analyses et articles
const NEWS_KINDS = ['news' => 'Actualité', 'event' => 'Événement'];

/** Numéro de page demandé (?page=) : tout ce qui n'est pas un entier positif vaut 1. */
function requested_page(): int
{
    $raw = request()->q('page');
    return preg_match('/^[1-9]\d{0,5}$/D', $raw) ? (int) $raw : 1;
}

/** Valeur d'un filtre de l'adresse, nettoyée et bornée en longueur. */
function filter_value(string $name, int $max = 100): string
{
    return mb_cut(trim(request()->q($name)), $max);
}

/** Motif ILIKE « contient » : %, _ et \ saisis par le visiteur sont pris au pied de la lettre. */
function like_contains(string $text): string
{
    return '%' . strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
}

/** Adresse d'une page de liste avec ses filtres actifs ; les filtres vides et la page 1 sont omis. */
function list_href(string $base, array $filters, int $page = 1): string
{
    $params = array_filter($filters, fn($value) => $value !== '');
    $lang = request()->q('lang');
    if ($lang !== '') {
        $params['lang'] = $lang;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }
    return $base . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
}

/** Pagination : précédent, première et dernière pages, voisines de la page courante, suivant. */
function pagination_nav(string $base, array $filters, int $page, int $pages): string
{
    if ($pages <= 1) {
        return '';
    }
    $link = fn(int $number, string $label, string $attributes = '') => '<a href="' . esc(list_href($base, $filters, $number)) . '"' . $attributes . '>' . $label . '</a>';
    $previousLabel = '<span aria-hidden="true">←</span> <span>Précédent</span>';
    $nextLabel = '<span>Suivant</span> <span aria-hidden="true">→</span>';
    $previous = $page > 1 ? $link($page - 1, $previousLabel, ' class="pagination-step" rel="prev"') : '<span class="pagination-step is-disabled" aria-disabled="true">' . $previousLabel . '</span>';
    $next = $page < $pages ? $link($page + 1, $nextLabel, ' class="pagination-step" rel="next"') : '<span class="pagination-step is-disabled" aria-disabled="true">' . $nextLabel . '</span>';
    $numbers = array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], fn($number) => $number >= 1 && $number <= $pages));
    sort($numbers);
    $items = '';
    $last = 0;
    foreach ($numbers as $number) {
        if ($number - $last > 1) {
            $items .= '<li class="pagination-gap" aria-hidden="true">…</li>';
        }
        $items .= '<li>' . $link($number, (string) $number, $number === $page ? ' aria-current="page"' : '') . '</li>';
        $last = $number;
    }
    return '<nav class="pagination" aria-label="Pagination">' . $previous . '<ul>' . $items . '</ul>' . $next . '</nav>';
}

/** Liste déroulante d'un filtre ; $options associe la valeur de l'adresse au libellé affiché. */
function filter_select(string $name, string $label, string $allLabel, array $options, string $selected): string
{
    $html = '<option value="">' . esc($allLabel) . '</option>';
    foreach ($options as $value => $text) {
        $html .= '<option value="' . esc((string) $value) . '"' . ((string) $value === $selected ? ' selected' : '') . '>' . esc($text) . '</option>';
    }
    return '<label>' . esc($label) . ' <select name="' . esc($name) . '">' . $html . '</select></label>';
}

/** Formulaire de filtres (GET) : les filtres restent dans l'adresse et se combinent. */
function filters_form(string $base, string $controls, array $filters): string
{
    $lang = request()->q('lang');
    $hidden = $lang !== '' ? '<input type="hidden" name="lang" value="' . esc($lang) . '">' : '';
    $reset = array_filter($filters, fn($value) => $value !== '') ? '<a class="list-reset" href="' . esc(list_href($base, [])) . '">Réinitialiser les filtres</a>' : '';
    $search = '<label class="list-search">Recherche <input type="search" name="q" value="' . esc($filters['q']) . '" maxlength="100" placeholder="Rechercher (en français)"></label>';
    return '<form class="public-filter-row list-filters" method="get" action="' . esc($base) . '" role="search">' . $hidden . $controls . $search . '<button class="theme-button" type="submit">Filtrer</button>' . $reset . '</form>';
}

/** Langues présentes parmi les contenus publiés de la table indiquée (articles ou news_events). */
function published_languages(Db $db, string $table): array
{
    $table = $table === 'news_events' ? 'news_events' : 'articles';
    $labels = array_column($db->all('SELECT code,label FROM languages'), 'label', 'code');
    $options = [];
    foreach ($db->all("SELECT DISTINCT language FROM $table WHERE status='published' ORDER BY language") as $row) {
        $code = (string) $row['language'];
        $options[$code] = $labels[$code] ?? (isset(LANGUAGE_NAMES['fr'][$code]) ? mb_convert_case(LANGUAGE_NAMES['fr'][$code], MB_CASE_TITLE, 'UTF-8') : strtoupper($code));
    }
    return $options;
}

/** Nombre de résultats (le mot est un nœud texte à part, traduit par i18n.js). */
function list_count(int $total): string
{
    return '<p class="list-count" aria-live="polite"><strong>' . $total . '</strong> <span>' . ($total === 1 ? 'résultat' : 'résultats') . '</span></p>';
}

function list_empty(string $base): string
{
    return '<div class="list-empty"><p>Aucun résultat ne correspond à ces filtres.</p><a class="theme-button" href="' . esc(list_href($base, [])) . '">Réinitialiser les filtres</a></div>';
}

/** Page au-delà de la dernière : 404 avec l'en-tête du site et un lien vers la page 1 (filtres conservés). */
function list_page_not_found(string $base, array $filters): void
{
    public_page('Page introuvable', '<main class="article-page list-page"><h1>Page introuvable</h1><p>Cette page de résultats n’existe pas.</p><a class="theme-button" href="' . esc(list_href($base, $filters)) . '">Revenir à la page 1</a></main>', 404);
}

/**
 * Pays saisis dans l'éditeur (« Niger, France ») : liste nettoyée et sans doublon. Un pays déjà connu garde son
 * orthographe (« niger » devient « Niger ») pour que le filtre par pays regroupe bien les articles.
 */
function parse_countries(Db $db, string $text): array
{
    $countries = [];
    foreach (preg_split('/[,;\n]+/u', $text) ?: [] as $item) {
        $name = mb_cut(trim((string) preg_replace('/\s+/u', ' ', $item)), 80);
        if ($name === '') {
            continue;
        }
        $known = $db->value('SELECT country FROM article_countries WHERE lower(country)=lower(?) LIMIT 1', [$name]);
        $name = $known !== null ? (string) $known : mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        $countries[mb_strtolower($name)] = $name;
    }
    return array_values($countries);
}

function article_countries(Db $db, string $articleId): array
{
    return array_column($db->all('SELECT country FROM article_countries WHERE article_id=? ORDER BY country', [$articleId]), 'country');
}

/** Tous les pays déjà saisis (suggestions de l'éditeur). */
function all_countries(Db $db): array
{
    return array_column($db->all('SELECT DISTINCT country FROM article_countries ORDER BY country'), 'country');
}

/** Pays des articles publiés, avec leur nombre d'articles (filtre « Pays » et menu « Régions »). */
function published_countries(Db $db): array
{
    return array_column($db->all("SELECT c.country, COUNT(*) AS total FROM article_countries c JOIN articles a ON a.id=c.article_id
        WHERE a.status='published' GROUP BY c.country ORDER BY c.country"), 'total', 'country');
}

/** Libellés des pays pour un lot d'articles, en une requête : [id => « Niger, France »]. */
function countries_by_article(Db $db, array $ids): array
{
    $byArticle = [];
    foreach ($ids ? $db->all('SELECT article_id, country FROM article_countries WHERE article_id = ANY(?) ORDER BY country', [$ids]) : [] as $row) {
        $byArticle[$row['article_id']][] = $row['country'];
    }
    return array_map(fn($list) => implode(', ', $list), $byArticle);
}

/** Date de référence d'un article en SQL (publication, sinon création). */
const ARTICLE_DATE_SQL = 'COALESCE(a.published_at, a.created_at)';

/**
 * Liste de titres (présentation inspirée de Brookings) : thème et date au-dessus, titre, puis auteur et pays.
 * Pas de chapô ni d'image : la page reste aérée et se parcourt d'un coup d'œil.
 */
function note_rows(Db $db, array $articles, string $language): string
{
    if (!$articles) {
        return '';
    }
    $ids = array_column($articles, 'id');
    $categories = [];
    foreach ($db->all('SELECT ac.article_id, c.id, c.name, c.slug FROM article_categories ac JOIN categories c ON c.id=ac.category_id WHERE ac.article_id = ANY(?) ORDER BY c.name', [$ids]) as $row) {
        $categories[$row['article_id']][] = $row;
    }
    $countries = countries_by_article($db, $ids);
    $rows = '';
    foreach (translate_records($db, $articles, $language, ['title']) as $article) {
        $cats = localize_terms($db, $categories[$article['id']] ?? [], $language);
        $theme = $cats ? '<a href="/analyses?categorie=' . q($cats[0]['slug']) . '">' . esc($cats[0]['name']) . '</a>' : '<span>Les Notes</span>';
        $byline = esc($article['author']) . (isset($countries[$article['id']]) ? ' <span class="note-country">' . esc($countries[$article['id']]) . '</span>' : '');
        $rows .= '<article class="note-row" lang="' . esc(content_lang($article)) . '"><div class="note-meta">' . $theme . '<time>' . esc(long_date(article_date($article), $language)) . '</time></div>'
            . '<h3><a class="note-title" href="/article/' . q($article['slug']) . '">' . esc($article['title']) . '</a></h3><p class="note-byline">' . $byline . '</p></article>';
    }
    return $rows;
}

/**
 * /analyses : articles publiés, 12 par page. Deux onglets : la sélection (mise en avant) et les archives (articles
 * rangés par l'équipe). Filtres combinables : thème, auteur, pays, année, mot-clé, langue et texte.
 */
function public_analyses(): void
{
    $db = db();
    $language = selected_language($db);
    $filters = ['categorie' => filter_value('categorie'), 'auteur' => filter_value('auteur', 120), 'pays' => filter_value('pays', 80),
        'annee' => preg_match('/^\d{4}$/D', request()->q('annee')) ? request()->q('annee') : '', 'tag' => filter_value('tag'),
        'langue' => filter_value('langue', 10), 'q' => filter_value('q'), 'archives' => request()->q('archives') === '1' ? '1' : ''];
    $where = ["a.status='published'"];
    $params = [];
    if ($filters['categorie'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM article_categories ac JOIN categories c ON c.id=ac.category_id WHERE ac.article_id=a.id AND c.slug=?)';
        $params[] = $filters['categorie'];
    }
    if ($filters['auteur'] !== '') {
        $where[] = 'a.author=?';
        $params[] = $filters['auteur'];
    }
    if ($filters['pays'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM article_countries co WHERE co.article_id=a.id AND co.country=?)';
        $params[] = $filters['pays'];
    }
    if ($filters['annee'] !== '') {
        $where[] = 'substr(' . ARTICLE_DATE_SQL . ', 1, 4)=?';
        $params[] = $filters['annee'];
    }
    if ($filters['tag'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM article_tags at2 JOIN tags t ON t.id=at2.tag_id WHERE at2.article_id=a.id AND t.slug=?)';
        $params[] = $filters['tag'];
    }
    if ($filters['langue'] !== '') {
        $where[] = 'a.language=?';
        $params[] = $filters['langue'];
    }
    if ($filters['q'] !== '') {
        $where[] = '(a.title ILIKE ? OR a.excerpt ILIKE ? OR a.author ILIKE ?)';
        array_push($params, like_contains($filters['q']), like_contains($filters['q']), like_contains($filters['q']));
    }
    $condition = implode(' AND ', $where);
    // Effectif de chaque onglet pour les mêmes filtres : on voit tout de suite si les archives contiennent des résultats.
    $counts = array_column($db->all("SELECT a.archived, COUNT(*) AS total FROM articles a WHERE $condition GROUP BY a.archived", $params), 'total', 'archived');
    $archives = $filters['archives'] === '1';
    $total = (int) ($counts[$archives ? 1 : 0] ?? 0);
    $pages = max(1, (int) ceil($total / ANALYSES_PAGE_SIZE));
    $page = requested_page();
    if ($page > $pages) {
        list_page_not_found('/analyses', $filters);
        return;
    }
    $articles = $db->all("SELECT a.* FROM articles a WHERE $condition AND a.archived=? ORDER BY " . ARTICLE_DATE_SQL . " DESC, a.id LIMIT ? OFFSET ?",
        [...$params, $archives ? 1 : 0, ANALYSES_PAGE_SIZE, ($page - 1) * ANALYSES_PAGE_SIZE]);
    $termOptions = function (string $table) use ($db, $language): array {
        $rows = sort_by_name(localize_terms($db, $db->all("SELECT id,name,slug FROM $table ORDER BY name"), $language));
        return array_column($rows, 'name', 'slug');
    };
    $authors = array_column($db->all("SELECT DISTINCT author FROM articles WHERE status='published' AND author<>'' ORDER BY author"), 'author', 'author');
    $countries = array_keys(published_countries($db));
    $years = array_column($db->all("SELECT DISTINCT substr(COALESCE(published_at, created_at), 1, 4) AS year FROM articles WHERE status='published' ORDER BY year DESC"), 'year', 'year');
    $controls = filter_select('categorie', 'Thème', 'Tous les thèmes', $termOptions('categories'), $filters['categorie'])
        . filter_select('auteur', 'Auteur', 'Tous les auteurs', $authors, $filters['auteur'])
        . filter_select('pays', 'Pays', 'Tous les pays', array_combine($countries, $countries) ?: [], $filters['pays'])
        . filter_select('annee', 'Année', 'Toutes les années', $years, $filters['annee'])
        . filter_select('tag', 'Mot-clé', 'Tous les mots-clés', $termOptions('tags'), $filters['tag'])
        . filter_select('langue', 'Langue', 'Toutes les langues', published_languages($db, 'articles'), $filters['langue'])
        . ($archives ? '<input type="hidden" name="archives" value="1">' : '');
    $tab = function (bool $isArchives, string $label) use ($filters, $archives, $counts): string {
        $href = list_href('/analyses', ['archives' => $isArchives ? '1' : ''] + $filters);
        $current = $isArchives === $archives ? ' aria-current="page" class="is-current"' : '';
        return '<a href="' . esc($href) . '"' . $current . '><span>' . $label . '</span> <small>' . (int) ($counts[$isArchives ? 1 : 0] ?? 0) . '</small></a>';
    };
    $tabs = '<nav class="notes-tabs" aria-label="Sélection ou archives">' . $tab(false, 'Sélection') . $tab(true, 'Archives') . '</nav>';
    $results = $articles ? '<div class="note-list">' . note_rows($db, $articles, $language) . '</div>' : list_empty('/analyses');
    $intro = $archives ? 'Les Notes plus anciennes, rangées par l’équipe : retrouvez-les par auteur, thème, pays ou date.' : 'Des idées, des analyses et des propositions portées par notre réseau.';
    $body = '<main class="list-page notes-page"><div class="section-heading"><span class="eyebrow">Les Notes d’I-Dialogos</span><h1>' . ($archives ? 'Archives des Notes' : 'Analyses et articles') . '</h1><p>' . $intro . '</p></div>'
        . $tabs . '<div id="filtres">' . filters_form('/analyses', $controls, $filters) . '</div>' . list_count($total) . $results . pagination_nav('/analyses', $filters, $page, $pages) . '</main>';
    public_page(($archives ? 'Archives des Notes' : 'Analyses et articles') . ($page > 1 ? " – page $page" : ''), $body);
}

/** /actualites : actualités et événements publiés, 10 par page, filtres par type, langue et texte. */
function public_news_list(): void
{
    $db = db();
    $language = selected_language($db);
    $filters = ['type' => filter_value('type', 10), 'langue' => filter_value('langue', 10), 'q' => filter_value('q')];
    $where = ["e.status='published'"];
    $params = [];
    if ($filters['type'] !== '') {
        $where[] = 'e.kind=?';
        $params[] = $filters['type'];
    }
    if ($filters['langue'] !== '') {
        $where[] = 'e.language=?';
        $params[] = $filters['langue'];
    }
    if ($filters['q'] !== '') {
        $where[] = '(e.title ILIKE ? OR e.summary ILIKE ?)';
        array_push($params, like_contains($filters['q']), like_contains($filters['q']));
    }
    $condition = implode(' AND ', $where);
    $total = (int) $db->value("SELECT COUNT(*) FROM news_events e WHERE $condition", $params);
    $pages = max(1, (int) ceil($total / LIST_PAGE_SIZE));
    $page = requested_page();
    if ($page > $pages) {
        list_page_not_found('/actualites', $filters);
        return;
    }
    $items = $db->all("SELECT e.* FROM news_events e WHERE $condition ORDER BY CASE WHEN e.starts_at='' THEN e.created_at ELSE e.starts_at END DESC, e.id DESC LIMIT ? OFFSET ?",
        [...$params, LIST_PAGE_SIZE, ($page - 1) * LIST_PAGE_SIZE]);
    $items = translate_records($db, $items, $language, ['title', 'summary']);
    $controls = filter_select('type', 'Type', 'Tous les types', NEWS_KINDS, $filters['type'])
        . filter_select('langue', 'Langue', 'Toutes les langues', published_languages($db, 'news_events'), $filters['langue']);
    $results = $items ? '<section class="event-list list-events">' . implode('', array_map(fn($item) => public_news_event_card($item, false), $items)) . '</section>' : list_empty('/actualites');
    $body = '<main class="list-page"><div class="section-heading"><span class="eyebrow">I-Dialogos</span><h1>Actualités et événements</h1></div>'
        . filters_form('/actualites', $controls, $filters) . list_count($total) . $results . pagination_nav('/actualites', $filters, $page, $pages) . '</main>';
    public_page('Actualités et événements' . ($page > 1 ? " – page $page" : ''), $body);
}

/**
 * Articles similaires : d'abord ceux qui partagent le plus de thèmes (×2) et de mots-clés (×1),
 * puis les plus récents. Jamais l'article courant, uniquement des articles publiés.
 */
function similar_articles(Db $db, string $articleId, int $limit = 4): array
{
    $categoryIds = array_column($db->all('SELECT category_id FROM article_categories WHERE article_id=?', [$articleId]), 'category_id');
    $tagIds = array_column($db->all('SELECT tag_id FROM article_tags WHERE article_id=?', [$articleId]), 'tag_id');
    return $db->all("SELECT a.* FROM articles a
        LEFT JOIN (
            SELECT shared.article_id, SUM(shared.weight) AS score FROM (
                SELECT ac.article_id, 2 AS weight FROM article_categories ac WHERE ac.category_id = ANY(?)
                UNION ALL
                SELECT at2.article_id, 1 AS weight FROM article_tags at2 WHERE at2.tag_id = ANY(?)
            ) shared GROUP BY shared.article_id
        ) s ON s.article_id = a.id
        WHERE a.status='published' AND a.id <> ?
        ORDER BY COALESCE(s.score, 0) DESC, a.published_at DESC NULLS LAST, a.created_at DESC, a.id
        LIMIT ?", [$categoryIds, $tagIds, $articleId, $limit]);
}

/** Article publié précédent (plus ancien) et suivant (plus récent) dans l'ordre de publication. */
function adjacent_articles(Db $db, array $article): array
{
    $key = (string) (($article['published_at'] ?? '') ?: $article['created_at']);
    $older = $db->one("SELECT id,slug,title,excerpt,language FROM articles WHERE status='published' AND id <> ? AND (COALESCE(published_at, created_at), id) < (?, ?)
        ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT 1", [$article['id'], $key, $article['id']]);
    $newer = $db->one("SELECT id,slug,title,excerpt,language FROM articles WHERE status='published' AND id <> ? AND (COALESCE(published_at, created_at), id) > (?, ?)
        ORDER BY COALESCE(published_at, created_at) ASC, id ASC LIMIT 1", [$article['id'], $key, $article['id']]);
    return [$older, $newer];
}

/** Liens « Article précédent » et « Article suivant » en bas d'un article. */
function article_pager(Db $db, array $article, string $language): string
{
    [$older, $newer] = adjacent_articles($db, $article);
    $neighbours = translate_records($db, array_values(array_filter([$older, $newer])), $language, ['title']);
    $titles = array_column($neighbours, 'title', 'id');
    $pager = '';
    if ($older) {
        $pager .= '<a class="article-pager-link is-previous" rel="prev" href="/article/' . q($older['slug']) . '"><span class="article-pager-label">← <span>Article précédent</span></span><strong>' . esc($titles[$older['id']]) . '</strong></a>';
    }
    if ($newer) {
        $pager .= '<a class="article-pager-link is-next" rel="next" href="/article/' . q($newer['slug']) . '"><span class="article-pager-label"><span>Article suivant</span> →</span><strong>' . esc($titles[$newer['id']]) . '</strong></a>';
    }
    return $pager ? '<nav class="article-pager" aria-label="Articles précédent et suivant">' . $pager . '</nav>' : '';
}

/** Colonne « À lire aussi » (à droite de l'article sur ordinateur, sous l'article sur mobile). */
function related_aside(Db $db, array $article, string $language): string
{
    $similar = similar_articles($db, (string) $article['id']);
    if (!$similar) {
        return '';
    }
    $items = '';
    foreach (translate_records($db, $similar, $language, ['title']) as $item) {
        $image = $item['featured_image'] ? '<img src="' . esc($item['featured_image']) . '" alt="" loading="lazy">' : '<span class="aside-placeholder" aria-hidden="true">I·D</span>';
        $items .= '<li><a class="aside-article" href="/article/' . q($item['slug']) . '" lang="' . esc(content_lang($item)) . '">' . $image . '<span><strong>' . esc($item['title']) . '</strong><time>' . esc(long_date(article_date($item), $language)) . '</time></span></a></li>';
    }
    return '<aside class="article-aside" aria-labelledby="related-title"><h2 id="related-title">À lire aussi</h2><ul class="aside-articles">' . $items . '</ul><a class="read-link aside-more" href="/analyses">Toutes les analyses <span>→</span></a></aside>';
}
