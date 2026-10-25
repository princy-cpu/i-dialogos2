<?php
/**
 * Listes paginées et filtrables (/analyses, /actualites), articles similaires et navigation entre articles.
 * Toutes les requêtes sont paramétrées : les saisies du visiteur ne sont jamais concaténées au SQL.
 */
declare(strict_types=1);

const LIST_PAGE_SIZE = 10;
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

/** Cartes d'articles (catégories et étiquettes chargées en une fois, titres et chapôs traduits). */
function article_cards(Db $db, array $articles, string $language): string
{
    if (!$articles) {
        return '';
    }
    $ids = array_column($articles, 'id');
    $categoryLinks = $db->all('SELECT ac.article_id, c.id, c.name, c.slug FROM article_categories ac JOIN categories c ON c.id=ac.category_id WHERE ac.article_id = ANY(?) ORDER BY c.name', [$ids]);
    $tagLinks = $db->all('SELECT t2.article_id, t.id, t.name, t.slug FROM article_tags t2 JOIN tags t ON t.id=t2.tag_id WHERE t2.article_id = ANY(?) ORDER BY t.name', [$ids]);
    $terms = function (array $links) use ($db, $language): array {
        $unique = [];
        foreach ($links as $row) {
            $unique[$row['id']] = ['id' => $row['id'], 'name' => $row['name'], 'slug' => $row['slug']];
        }
        return array_column(localize_terms($db, array_values($unique), $language), null, 'id');
    };
    $categoriesById = $terms($categoryLinks);
    $tagsById = $terms($tagLinks);
    $byArticle = [];
    foreach ($categoryLinks as $row) {
        $byArticle[$row['article_id']]['cats'][] = $categoriesById[$row['id']];
    }
    foreach ($tagLinks as $row) {
        $byArticle[$row['article_id']]['tags'][] = $tagsById[$row['id']];
    }
    $cards = '';
    foreach (translate_records($db, $articles, $language, ['title', 'excerpt']) as $article) {
        $cards .= public_card($article, $byArticle[$article['id']]['cats'] ?? [], $byArticle[$article['id']]['tags'] ?? []);
    }
    return $cards;
}

/** /analyses : tous les articles publiés, 10 par page, filtres par thème, mot-clé, langue et texte. */
function public_analyses(): void
{
    $db = db();
    $language = selected_language($db);
    $filters = ['categorie' => filter_value('categorie'), 'tag' => filter_value('tag'), 'langue' => filter_value('langue', 10), 'q' => filter_value('q')];
    $where = ["a.status='published'"];
    $params = [];
    if ($filters['categorie'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM article_categories ac JOIN categories c ON c.id=ac.category_id WHERE ac.article_id=a.id AND c.slug=?)';
        $params[] = $filters['categorie'];
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
        $where[] = '(a.title ILIKE ? OR a.excerpt ILIKE ?)';
        array_push($params, like_contains($filters['q']), like_contains($filters['q']));
    }
    $condition = implode(' AND ', $where);
    $total = (int) $db->value("SELECT COUNT(*) FROM articles a WHERE $condition", $params);
    $pages = max(1, (int) ceil($total / LIST_PAGE_SIZE));
    $page = requested_page();
    if ($page > $pages) {
        list_page_not_found('/analyses', $filters);
        return;
    }
    $articles = $db->all("SELECT a.* FROM articles a WHERE $condition ORDER BY a.published_at DESC NULLS LAST, a.created_at DESC, a.id LIMIT ? OFFSET ?",
        [...$params, LIST_PAGE_SIZE, ($page - 1) * LIST_PAGE_SIZE]);
    $termOptions = function (string $table) use ($db, $language): array {
        $rows = sort_by_name(localize_terms($db, $db->all("SELECT id,name,slug FROM $table ORDER BY name"), $language));
        return array_column($rows, 'name', 'slug');
    };
    $controls = filter_select('categorie', 'Thème', 'Tous les thèmes', $termOptions('categories'), $filters['categorie'])
        . filter_select('tag', 'Mot-clé', 'Tous les mots-clés', $termOptions('tags'), $filters['tag'])
        . filter_select('langue', 'Langue', 'Toutes les langues', published_languages($db, 'articles'), $filters['langue']);
    $results = $articles ? '<div class="public-article-grid list-grid">' . article_cards($db, $articles, $language) . '</div>' : list_empty('/analyses');
    $body = '<main class="list-page"><div class="section-heading"><span class="eyebrow">Les Notes d’I-Dialogos</span><h1>Analyses et articles</h1><p>Des idées, des analyses et des propositions portées par notre réseau.</p></div>'
        . filters_form('/analyses', $controls, $filters) . list_count($total) . $results . pagination_nav('/analyses', $filters, $page, $pages) . '</main>';
    public_page('Analyses et articles' . ($page > 1 ? " – page $page" : ''), $body);
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

/** Section « À lire aussi » et liens « Article précédent / suivant » en bas d'un article. */
function article_footer_links(Db $db, array $article, string $language): string
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
    $pager = $pager ? '<nav class="article-pager" aria-label="Articles précédent et suivant">' . $pager . '</nav>' : '';
    $similar = similar_articles($db, (string) $article['id']);
    $related = $similar ? '<section class="related-articles" aria-labelledby="related-title"><h2 id="related-title" class="theme-subtitle">À lire aussi</h2><div class="public-article-grid related-grid">' . article_cards($db, $similar, $language) . '</div></section>' : '';
    return $pager . $related;
}
