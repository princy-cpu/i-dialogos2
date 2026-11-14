<?php
/**
 * Documents joints aux articles : fichiers PDF téléversés et liens externes (table article_attachments).
 * Sur la page de l'article, chaque PDF se lit dans une liseuse intégrée (assets/pdf-reader.js, pdf.js) ou se
 * télécharge ; les listes signalent les articles accompagnés d'un PDF. L'import d'un PDF en article se fait dans le
 * navigateur (assets/pdf-import.js) : le texte est extrait puis enregistré par save_article comme un article saisi.
 */
declare(strict_types=1);

const PDF_MAX_BYTES = 10 * 1024 * 1024;
const ATTACHMENTS_MAX = 12;

/** Liste des fichiers d'un champ <input type="file" multiple name="x[]"> (structure $_FILES « par colonne »). */
function uploaded_file_list(?array $field): array
{
    if (!$field || !isset($field['name'])) {
        return [];
    }
    if (!is_array($field['name'])) {
        return [$field];
    }
    $files = [];
    foreach (array_keys($field['name']) as $index) {
        $files[] = ['name' => $field['name'][$index], 'type' => $field['type'][$index] ?? '', 'tmp_name' => $field['tmp_name'][$index] ?? '',
            'error' => $field['error'][$index] ?? UPLOAD_ERR_NO_FILE, 'size' => $field['size'][$index] ?? 0];
    }
    return $files;
}

/** Enregistre un PDF envoyé. Renvoie [chemin public, titre proposé, taille, message d'erreur]. */
function store_uploaded_pdf(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || (int) ($file['size'] ?? 0) === 0) {
        return ['', '', 0, ''];
    }
    $name = (string) $file['name'];
    if ($file['error'] === UPLOAD_ERR_INI_SIZE) {
        // Limite du serveur (upload_max_filesize) plus basse que celle du site : à relever dans la configuration PHP.
        return ['', '', 0, 'Le PDF « ' . $name . ' » dépasse la taille acceptée par le serveur (' . ini_get('upload_max_filesize') . ').'];
    }
    if ($file['error'] === UPLOAD_ERR_FORM_SIZE || (int) $file['size'] > PDF_MAX_BYTES) {
        return ['', '', 0, 'Le PDF « ' . $name . ' » dépasse la limite de 10 Mo.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['', '', 0, 'Le fichier « ' . $name . ' » n’a pas pu être reçu.'];
    }
    $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 1024);
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf' || !str_contains($head, '%PDF-')) {
        return ['', '', 0, 'Le fichier « ' . $name . ' » n’est pas un PDF valide.'];
    }
    $directory = upload_dir();
    if (!is_dir($directory)) {
        @mkdir($directory, 0755, true);
    }
    $filename = uuid4() . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        return ['', '', 0, 'Impossible d’enregistrer le PDF sur le serveur.'];
    }
    $title = trim((string) preg_replace('/[_\s]+/u', ' ', pathinfo($name, PATHINFO_FILENAME)));
    return ['/uploads/' . $filename, mb_cut($title !== '' ? $title : 'Document PDF', 160), (int) $file['size'], ''];
}

/** Supprime le fichier d'un PDF retiré (seulement les fichiers de /uploads, jamais un autre chemin). */
function delete_uploaded_file(string $url): void
{
    $name = basename($url);
    if (str_starts_with($url, '/uploads/') && preg_match('/^[a-f0-9-]+\.(?:pdf|jpg|jpeg|png|gif|webp)$/D', $name)) {
        @unlink(upload_dir() . '/' . $name);
    }
}

function article_attachments(Db $db, string $articleId): array
{
    return $db->all('SELECT * FROM article_attachments WHERE article_id=? ORDER BY position, id', [$articleId]);
}

/** Articles (parmi $ids) accompagnés d'au moins un PDF : [id => true]. */
function articles_with_pdf(Db $db, array $ids): array
{
    if (!$ids) {
        return [];
    }
    return array_fill_keys(array_column($db->all("SELECT DISTINCT article_id FROM article_attachments WHERE kind='pdf' AND article_id = ANY(?)", [$ids]), 'article_id'), true);
}

/** Adresse de lien acceptée : http(s) uniquement, sans espace ni guillemet. */
function clean_link_url(string $url): string
{
    $url = trim($url);
    if ($url !== '' && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
        $url = 'https://' . $url;
    }
    return preg_match('#^https?://[^\s"<>]+$#iD', $url) && filter_var($url, FILTER_VALIDATE_URL) ? mb_cut($url, 1000) : '';
}

/**
 * Pièces jointes à l'enregistrement d'un article : retire les éléments cochés, renomme les titres modifiés, ajoute les
 * PDF déjà stockés ($newPdfs : [chemin, titre, taille]) et les nouveaux liens. Renvoie les fichiers à supprimer du disque.
 */
function save_article_attachments(Db $db, string $articleId, Request $r, array $newPdfs): array
{
    $now = now_iso();
    $removed = [];
    $existing = array_column(article_attachments($db, $articleId), null, 'id');
    $remove = array_flip(array_filter($r->formList('remove_attachments'), 'ctype_digit'));
    $ids = $r->formList('attachment_ids');
    $titles = $r->formList('attachment_titles');
    foreach ($ids as $index => $rawId) {
        $row = $existing[$rawId] ?? null;
        if (!$row) {
            continue;
        }
        if (isset($remove[$rawId])) {
            $db->execute('DELETE FROM article_attachments WHERE id=?', [(int) $rawId]);
            if ($row['kind'] === 'pdf') {
                $removed[] = $row['url'];
            }
            continue;
        }
        $title = mb_cut(trim((string) ($titles[$index] ?? '')), 160);
        if ($title !== '' && $title !== $row['title']) {
            $db->execute('UPDATE article_attachments SET title=? WHERE id=?', [$title, (int) $rawId]);
        }
    }
    $position = (int) $db->value('SELECT COALESCE(MAX(position), 0) FROM article_attachments WHERE article_id=?', [$articleId]);
    $count = (int) $db->value('SELECT COUNT(*) FROM article_attachments WHERE article_id=?', [$articleId]);
    foreach ($newPdfs as [$url, $title, $size]) {
        if ($count >= ATTACHMENTS_MAX) {
            $removed[] = $url;
            continue;
        }
        $db->execute('INSERT INTO article_attachments(article_id,kind,title,url,size,position,created_at) VALUES(?,?,?,?,?,?,?)', [$articleId, 'pdf', $title, $url, $size, ++$position, $now]);
        $count++;
    }
    $linkTitles = $r->formList('link_titles');
    foreach ($r->formList('link_urls') as $index => $rawUrl) {
        $url = clean_link_url((string) $rawUrl);
        if ($url === '' || $count >= ATTACHMENTS_MAX) {
            continue;
        }
        $title = mb_cut(trim((string) ($linkTitles[$index] ?? '')), 160) ?: (string) parse_url($url, PHP_URL_HOST);
        $db->execute('INSERT INTO article_attachments(article_id,kind,title,url,size,position,created_at) VALUES(?,?,?,?,?,?,?)', [$articleId, 'link', $title, $url, 0, ++$position, $now]);
        $count++;
    }
    return $removed;
}

function file_size_label(int $bytes): string
{
    if ($bytes <= 0) {
        return '';
    }
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', ' ') . ' Mo' : max(1, (int) round($bytes / 1024)) . ' Ko';
}

/** Panneau « Documents et liens » de l'éditeur d'article. */
function attachments_panel(array $attachments): string
{
    $rows = '';
    foreach ($attachments as $row) {
        $kind = $row['kind'] === 'pdf' ? '<span class="attachment-kind is-pdf">PDF</span>' : '<span class="attachment-kind">Lien</span>';
        $rows .= '<li class="attachment-edit">' . $kind . '<input type="hidden" name="attachment_ids[]" value="' . (int) $row['id'] . '">'
            . '<label class="sr-only" for="attachment-title-' . (int) $row['id'] . '">Titre</label><input id="attachment-title-' . (int) $row['id'] . '" name="attachment_titles[]" maxlength="160" value="' . esc($row['title']) . '">'
            . '<a href="' . esc($row['url']) . '" target="_blank" rel="noopener" title="Ouvrir">↗</a>'
            . '<label class="check-option attachment-remove"><input type="checkbox" name="remove_attachments[]" value="' . (int) $row['id'] . '">Retirer</label></li>';
    }
    $list = $rows !== '' ? '<ul class="attachment-edit-list">' . $rows . '</ul>' : '<p class="field-help">Aucun document joint pour le moment.</p>';
    $linkRow = '<div class="link-row"><input name="link_titles[]" maxlength="160" placeholder="Titre du lien" aria-label="Titre du lien"><input name="link_urls[]" type="url" inputmode="url" maxlength="1000" placeholder="https://…" aria-label="Adresse du lien"></div>';
    return '<section class="side-panel" id="attachments-panel"><h2>Documents PDF et liens</h2>' . $list
        . '<label class="admin-field">Joindre des PDF<input type="file" name="pdf_files[]" accept="application/pdf,.pdf" multiple></label><small class="field-help">10 Mo maximum par fichier. Les lecteurs pourront lire le PDF sur la page de l’article ou le télécharger.</small>'
        . '<div class="admin-field">Ajouter des liens<div id="link-rows">' . $linkRow . $linkRow . '</div><button type="button" class="small-admin-link link-add" data-add-link>+ Un autre lien</button></div></section>';
}

/** Encadré de l'import PDF dans l'éditeur (rempli par assets/pdf-import.js). */
function pdf_import_panel(): string
{
    return '<section class="side-panel pdf-import-panel" data-pdf-import><h2>Importer depuis un PDF</h2><p class="field-help">Choisissez un PDF : son texte remplit l’article (titre, chapô, contenu), que vous pouvez ensuite relire et corriger.</p>'
        . '<label class="admin-field">Fichier PDF<input type="file" accept="application/pdf,.pdf" data-pdf-source></label>'
        . '<label class="check-option"><input type="checkbox" data-pdf-attach checked>Joindre aussi le PDF à l’article</label>'
        . '<label class="check-option"><input type="checkbox" data-pdf-cover checked>Image mise en avant : la première page (si aucune image)</label>'
        . '<button type="button" class="primary-button" data-pdf-run disabled>Remplir l’article</button><p class="pdf-import-status" role="status" aria-live="polite"></p></section>';
}

/** Bloc public : documents et liens de l'article, avec la liseuse pour les PDF. */
function attachments_block(array $attachments): string
{
    if (!$attachments) {
        return '';
    }
    $items = '';
    foreach ($attachments as $row) {
        $title = esc($row['title'] ?: 'Document');
        if ($row['kind'] === 'pdf') {
            $size = file_size_label((int) $row['size']);
            $items .= '<li class="attachment is-pdf"><span class="attachment-icon" aria-hidden="true">PDF</span><div><strong>' . $title . '</strong>' . ($size !== '' ? '<small>Document PDF · ' . $size . '</small>' : '<small>Document PDF</small>') . '</div>'
                . '<div class="attachment-actions"><a class="theme-button" href="' . esc($row['url']) . '" target="_blank" rel="noopener" data-pdf-read="' . esc($row['url']) . '" data-pdf-title="' . $title . '">Lire le PDF</a>'
                . '<a class="attachment-download" href="' . esc($row['url']) . '" download>Télécharger</a></div></li>';
        } else {
            $host = (string) parse_url($row['url'], PHP_URL_HOST);
            $items .= '<li class="attachment is-link"><span class="attachment-icon" aria-hidden="true">↗</span><div><strong>' . $title . '</strong><small>' . esc($host) . '</small></div>'
                . '<div class="attachment-actions"><a class="attachment-download" href="' . esc($row['url']) . '" target="_blank" rel="noopener noreferrer">Ouvrir le lien</a></div></li>';
        }
    }
    return '<section class="article-attachments" aria-labelledby="attachments-title"><h2 id="attachments-title">Documents et liens</h2><ul>' . $items . '</ul></section>';
}

/** Invitation à lire le PDF, en tête de l'article. */
function pdf_invitation(array $attachments): string
{
    $pdfs = array_values(array_filter($attachments, fn($row) => $row['kind'] === 'pdf'));
    if (!$pdfs) {
        return '';
    }
    $first = $pdfs[0];
    $more = count($pdfs) > 1 ? ' <a href="#attachments-title">' . count($pdfs) . ' documents joints</a>' : '';
    return '<aside class="pdf-invitation"><span class="attachment-icon" aria-hidden="true">PDF</span><p>Cet article est accompagné d’un document PDF.' . $more . '</p>'
        . '<a class="theme-button" href="' . esc($first['url']) . '" target="_blank" rel="noopener" data-pdf-read="' . esc($first['url']) . '" data-pdf-title="' . esc($first['title']) . '">Lire le PDF</a></aside>';
}

/** Pastille « PDF » des listes d'articles. */
function pdf_badge(): string
{
    return '<span class="pdf-badge" title="Document PDF à lire">PDF</span>';
}

/**
 * Image insérée dans le corps d'un article depuis l'éditeur (bouton « Image ») : réponse JSON avec l'adresse publique.
 */
function upload_article_image(): void
{
    [$path, $error] = store_uploaded_image(request()->files['image'] ?? null);
    if ($path === '' && $error === '') {
        $error = 'Choisissez une image.';
    }
    send_body(json_encode($error !== '' ? ['error' => $error] : ['url' => $path], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $error !== '' ? 422 : 200, 'application/json; charset=utf-8');
}

/** Page d'import de plusieurs PDF : un brouillon par fichier (assets/pdf-import.js). */
function pdf_import_page(): void
{
    $session = auth();
    $db = db();
    $categories = '';
    foreach ($db->all('SELECT id,name FROM categories ORDER BY name') as $row) {
        $categories .= '<option value="' . (int) $row['id'] . '">' . esc($row['name']) . '</option>';
    }
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Articles</span><h1>Importer des PDF</h1><p>Chaque PDF devient un article en brouillon : le texte est repris, le PDF est joint et sa première page sert d’image. Relisez ensuite chaque brouillon avant de le publier.</p></div><a class="plain-button" href="/admin/articles">← Articles</a></div>'
        . '<section class="admin-panel"><form class="event-admin-form admin-form-grid" data-pdf-bulk>' . csrf_field()
        . '<label class="admin-field">Fichiers PDF<input type="file" accept="application/pdf,.pdf" multiple required data-pdf-files></label>'
        . '<label class="admin-field">Auteur/autrice<input name="author" required maxlength="120" value="' . esc($session['display_name'] ?: $session['username']) . '"><small class="field-help">Modifiable ensuite dans chaque article.</small></label>'
        . '<label class="admin-field">Thématique (facultatif)<select name="categories[]"><option value="">— Aucune —</option>' . $categories . '</select></label>'
        . '<label class="check-option"><input type="checkbox" data-pdf-attach checked>Joindre le PDF à chaque article</label>'
        . '<label class="check-option"><input type="checkbox" data-pdf-cover checked>Image mise en avant : la première page du PDF</label>'
        . '<div class="side-actions"><button class="primary-button" type="submit">Importer</button></div></form>'
        . '<ol class="pdf-import-results" aria-live="polite"></ol></section></main>'
        . '<script type="module" src="/assets/pdf-import.js"></script>';
    send_body(admin_page('Importer des PDF', $content, 'articles'));
}
