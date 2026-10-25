<?php
/**
 * Newsletter : inscription avec double confirmation, désinscription (lien dans chaque e-mail), file d'envoi en base
 * et annonce automatique de chaque nouvel article publié (une seule fois par article).
 *
 * Réglages (app.env) :
 *   BREVO_API_KEY      clé API Brevo (https://app.brevo.com > SMTP & API > Clés API) ;
 *   MAIL_FROM_EMAIL    adresse d'expédition, validée dans Brevo (expéditeurs) ; MAIL_FROM_NAME : nom affiché ;
 *   SITE_URL           adresse publique du site (liens et images des e-mails), par exemple https://www.i-dialogos.com ;
 *   MAIL_DAILY_LIMIT   envois maximum par 24 h (300 par défaut, quota de l'offre gratuite de Brevo) ;
 *   MAIL_TRANSPORT     « log » pour écrire les e-mails dans MAIL_LOG_FILE au lieu de les envoyer (tests, essais en local).
 * La file est traitée après chaque inscription ou publication, depuis l'administration, et par la tâche planifiée
 * POST /_ops/mail-queue (en-tête X-Deploy-Token), qui reprend les envois restants (quota journalier, erreurs passagères).
 */
declare(strict_types=1);

const MAIL_MAX_ATTEMPTS = 5;
const NEWSLETTER_RATE_LIMIT = 5;        // inscriptions par adresse IP…
const NEWSLETTER_RATE_WINDOW = 600;     // …sur 10 minutes
const CONFIRMATION_RESEND_DELAY = 600;  // pas de nouvel e-mail de confirmation avant 10 minutes

const NEWSLETTER_TEXTS = [
    'fr' => [
        'invalid' => 'Saisissez une adresse e-mail valide.',
        'pending' => 'Merci ! Un e-mail de confirmation vient de vous être envoyé : cliquez sur le lien qu’il contient pour valider votre inscription.',
        'resent' => 'Votre inscription attend encore votre confirmation : vérifiez votre boîte de réception (et le dossier des indésirables).',
        'already' => 'Cette adresse est déjà inscrite à la newsletter.',
        'rate' => 'Trop de tentatives. Réessayez dans quelques minutes.',
        'confirm_subject' => 'Confirmez votre inscription à la newsletter I-Dialogos',
        'confirm_intro' => 'Vous avez demandé à recevoir la newsletter d’I-Dialogos. Pour confirmer votre inscription, cliquez sur le bouton ci-dessous.',
        'confirm_button' => 'Confirmer mon inscription',
        'confirm_ignore' => 'Si vous n’êtes pas à l’origine de cette demande, ignorez simplement cet e-mail : vous ne recevrez rien.',
        'read' => 'Lire l’article',
        'new_article' => 'Nouvelle publication',
        'footer' => 'Vous recevez cet e-mail car vous êtes inscrit(e) à la newsletter d’I-Dialogos.',
        'unsubscribe' => 'Se désinscrire',
        'confirmed_title' => 'Inscription confirmée',
        'confirmed_text' => 'Merci ! Vous recevrez désormais les nouvelles publications d’I-Dialogos.',
        'bad_link_title' => 'Lien invalide',
        'bad_link_text' => 'Ce lien n’est pas ou plus valide. Vous pouvez vous inscrire de nouveau depuis le pied de page du site.',
        'unsub_title' => 'Désinscription de la newsletter',
        'unsub_question' => 'Voulez-vous vraiment ne plus recevoir la newsletter d’I-Dialogos à l’adresse %s ?',
        'unsub_button' => 'Confirmer la désinscription',
        'unsub_done' => 'Votre adresse a bien été désinscrite. Vous ne recevrez plus la newsletter.',
        'home' => 'Retour à l’accueil',
    ],
    'en' => [
        'invalid' => 'Please enter a valid email address.',
        'pending' => 'Thank you! A confirmation email has just been sent: click the link it contains to confirm your subscription.',
        'resent' => 'Your subscription is still awaiting confirmation: please check your inbox (and spam folder).',
        'already' => 'This address is already subscribed to the newsletter.',
        'rate' => 'Too many attempts. Please try again in a few minutes.',
        'confirm_subject' => 'Confirm your subscription to the I-Dialogos newsletter',
        'confirm_intro' => 'You asked to receive the I-Dialogos newsletter. To confirm your subscription, click the button below.',
        'confirm_button' => 'Confirm my subscription',
        'confirm_ignore' => 'If you did not make this request, simply ignore this email: you will not receive anything.',
        'read' => 'Read the article',
        'new_article' => 'New publication',
        'footer' => 'You are receiving this email because you subscribed to the I-Dialogos newsletter.',
        'unsubscribe' => 'Unsubscribe',
        'confirmed_title' => 'Subscription confirmed',
        'confirmed_text' => 'Thank you! You will now receive new I-Dialogos publications.',
        'bad_link_title' => 'Invalid link',
        'bad_link_text' => 'This link is not or no longer valid. You can subscribe again from the footer of the website.',
        'unsub_title' => 'Unsubscribe from the newsletter',
        'unsub_question' => 'Do you really want to stop receiving the I-Dialogos newsletter at %s?',
        'unsub_button' => 'Confirm unsubscription',
        'unsub_done' => 'Your address has been unsubscribed. You will no longer receive the newsletter.',
        'home' => 'Back to home',
    ],
    'es' => [
        'invalid' => 'Introduzca una dirección de correo electrónico válida.',
        'pending' => '¡Gracias! Le acabamos de enviar un correo de confirmación: haga clic en el enlace para validar su suscripción.',
        'resent' => 'Su suscripción sigue pendiente de confirmación: revise su bandeja de entrada (y la carpeta de correo no deseado).',
        'already' => 'Esta dirección ya está suscrita al boletín.',
        'rate' => 'Demasiados intentos. Vuelva a intentarlo dentro de unos minutos.',
        'confirm_subject' => 'Confirme su suscripción al boletín de I-Dialogos',
        'confirm_intro' => 'Ha solicitado recibir el boletín de I-Dialogos. Para confirmar su suscripción, haga clic en el botón.',
        'confirm_button' => 'Confirmar mi suscripción',
        'confirm_ignore' => 'Si usted no ha hecho esta solicitud, ignore este correo: no recibirá nada.',
        'read' => 'Leer el artículo',
        'new_article' => 'Nueva publicación',
        'footer' => 'Recibe este correo porque está suscrito/a al boletín de I-Dialogos.',
        'unsubscribe' => 'Darse de baja',
        'confirmed_title' => 'Suscripción confirmada',
        'confirmed_text' => '¡Gracias! A partir de ahora recibirá las nuevas publicaciones de I-Dialogos.',
        'bad_link_title' => 'Enlace no válido',
        'bad_link_text' => 'Este enlace no es o ya no es válido. Puede suscribirse de nuevo desde el pie de página del sitio.',
        'unsub_title' => 'Baja del boletín',
        'unsub_question' => '¿Desea realmente dejar de recibir el boletín de I-Dialogos en %s?',
        'unsub_button' => 'Confirmar la baja',
        'unsub_done' => 'Su dirección ha sido dada de baja. Ya no recibirá el boletín.',
        'home' => 'Volver al inicio',
    ],
    'it' => [
        'invalid' => 'Inserisci un indirizzo e-mail valido.',
        'pending' => 'Grazie! Ti abbiamo appena inviato un’e-mail di conferma: clicca sul link per convalidare l’iscrizione.',
        'resent' => 'La tua iscrizione è ancora in attesa di conferma: controlla la posta in arrivo (e la cartella spam).',
        'already' => 'Questo indirizzo è già iscritto alla newsletter.',
        'rate' => 'Troppi tentativi. Riprova tra qualche minuto.',
        'confirm_subject' => 'Conferma la tua iscrizione alla newsletter di I-Dialogos',
        'confirm_intro' => 'Hai chiesto di ricevere la newsletter di I-Dialogos. Per confermare l’iscrizione, clicca sul pulsante qui sotto.',
        'confirm_button' => 'Conferma la mia iscrizione',
        'confirm_ignore' => 'Se non hai fatto questa richiesta, ignora semplicemente questa e-mail: non riceverai nulla.',
        'read' => 'Leggi l’articolo',
        'new_article' => 'Nuova pubblicazione',
        'footer' => 'Ricevi questa e-mail perché sei iscritto/a alla newsletter di I-Dialogos.',
        'unsubscribe' => 'Annulla l’iscrizione',
        'confirmed_title' => 'Iscrizione confermata',
        'confirmed_text' => 'Grazie! D’ora in poi riceverai le nuove pubblicazioni di I-Dialogos.',
        'bad_link_title' => 'Link non valido',
        'bad_link_text' => 'Questo link non è o non è più valido. Puoi iscriverti di nuovo dal piè di pagina del sito.',
        'unsub_title' => 'Annullamento dell’iscrizione',
        'unsub_question' => 'Vuoi davvero smettere di ricevere la newsletter di I-Dialogos all’indirizzo %s?',
        'unsub_button' => 'Conferma l’annullamento',
        'unsub_done' => 'Il tuo indirizzo è stato cancellato. Non riceverai più la newsletter.',
        'home' => 'Torna alla home',
    ],
];

final class MailNotConfigured extends RuntimeException
{
}

function nt(string $key, string $language): string
{
    return NEWSLETTER_TEXTS[$language][$key] ?? NEWSLETTER_TEXTS['fr'][$key];
}

function newsletter_language(Db $db): string
{
    $language = selected_language($db);
    return isset(NEWSLETTER_TEXTS[$language]) ? $language : 'fr';
}

/** Adresse publique du site, pour les liens et images des e-mails (SITE_URL, sinon l'adresse de la requête). */
function site_url(): string
{
    $configured = rtrim(safe_external_url(setting('SITE_URL')), '/');
    if ($configured !== '') {
        return $configured;
    }
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https' : 'http') . '://' . $host;
}

function absolute_url(string $path): string
{
    return preg_match('#^https?://#i', $path) ? $path : site_url() . '/' . ltrim($path, '/');
}

/** Limitation simple par adresse IP (formulaires publics) ; renvoie vrai si la limite est atteinte. */
function rate_limited(Db $db, string $scope, int $max, int $window): bool
{
    $now = time();
    $ip = request()->ip();
    $db->execute('DELETE FROM rate_events WHERE at < ?', [$now - 86400]);
    if ((int) $db->value('SELECT COUNT(*) FROM rate_events WHERE scope=? AND ip=? AND at >= ?', [$scope, $ip, $now - $window]) >= $max) {
        return true;
    }
    $db->execute('INSERT INTO rate_events(scope,ip,at) VALUES(?,?,?)', [$scope, $ip, $now]);
    return false;
}

function wants_json(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

/** Réponse d'un formulaire public : JSON pour le formulaire enrichi en JavaScript, sinon une page du site. */
function form_feedback(bool $ok, string $message, int $status, string $back): void
{
    if (wants_json()) {
        send_body(json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE), $status, 'application/json; charset=utf-8', ['Cache-Control' => 'no-store']);
        return;
    }
    $language = newsletter_language(db());
    public_page($ok ? 'Newsletter' : 'Erreur', '<main class="article-page newsletter-page"><h1>Newsletter</h1><p class="' . ($ok ? 'success-message' : 'form-error') . '" role="status">' . esc($message) . '</p><a class="theme-button" href="' . esc($back) . '">' . esc(nt('home', $language)) . '</a></main>', $status);
}

/** POST /newsletter : inscription (champ piège, limite par IP, doublons, double confirmation). */
function newsletter_subscribe(): void
{
    $db = db();
    $language = newsletter_language($db);
    $email = mb_strtolower(trim(request()->form('email')));
    if (request()->form('website') !== '') {
        // Champ piège rempli : robot. On répond comme pour une inscription, sans rien enregistrer.
        form_feedback(true, nt('pending', $language), 200, '/');
        return;
    }
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        form_feedback(false, nt('invalid', $language), 422, '/#infolettre');
        return;
    }
    if (rate_limited($db, 'newsletter', NEWSLETTER_RATE_LIMIT, NEWSLETTER_RATE_WINDOW)) {
        form_feedback(false, nt('rate', $language), 429, '/#infolettre');
        return;
    }
    $now = now_iso();
    $existing = $db->one('SELECT * FROM subscribers WHERE email=?', [$email]);
    if ($existing && $existing['status'] === 'active') {
        form_feedback(true, nt('already', $language), 200, '/');
        return;
    }
    if ($existing && $existing['status'] === 'pending' && $existing['confirmation_sent_at'] >= gmdate('Y-m-d\TH:i:s', time() - CONFIRMATION_RESEND_DELAY) . '+00:00') {
        form_feedback(true, nt('resent', $language), 200, '/');
        return;
    }
    if ($existing) {
        $db->execute("UPDATE subscribers SET status='pending', token=?, language=?, confirmation_sent_at=?, unsubscribed_at='' WHERE id=?", [random_token(), $language, $now, $existing['id']]);
        $subscriberId = (int) $existing['id'];
    } else {
        $subscriberId = (int) $db->value('INSERT INTO subscribers(email,status,token,language,created_at,confirmation_sent_at) VALUES(?,?,?,?,?,?) RETURNING id',
            [$email, 'pending', random_token(), $language, $now, $now]);
    }
    $db->execute("UPDATE mail_queue SET status='cancelled' WHERE subscriber_id=? AND kind='confirm' AND status='pending'", [$subscriberId]);
    $db->execute("INSERT INTO mail_queue(kind,subscriber_id,status,created_at,next_attempt_at) VALUES('confirm',?,'pending',?,?)", [$subscriberId, $now, $now]);
    after_response(fn() => process_mail_queue());
    form_feedback(true, nt($existing && $existing['status'] === 'pending' ? 'resent' : 'pending', $language), 200, '/');
}

function subscriber_by_token(Db $db, string $token): ?array
{
    return preg_match('/^[A-Za-z0-9_-]{20,100}$/D', $token) ? $db->one('SELECT * FROM subscribers WHERE token=?', [$token]) : null;
}

function newsletter_message_page(string $title, string $message, string $language, int $status = 200): void
{
    public_page($title, '<main class="article-page newsletter-page"><h1>' . esc($title) . '</h1><p class="success-message" role="status">' . esc($message) . '</p><a class="theme-button" href="/">' . esc(nt('home', $language)) . '</a></main>', $status);
}

/** GET /newsletter/confirmer?token=… */
function newsletter_confirm(): void
{
    $db = db();
    $subscriber = subscriber_by_token($db, request()->q('token'));
    $language = $subscriber['language'] ?? newsletter_language($db);
    if (!$subscriber || $subscriber['status'] === 'unsubscribed') {
        newsletter_message_page(nt('bad_link_title', $language), nt('bad_link_text', $language), $language, 404);
        return;
    }
    if ($subscriber['status'] === 'pending') {
        $db->execute("UPDATE subscribers SET status='active', confirmed_at=? WHERE id=?", [now_iso(), $subscriber['id']]);
    }
    newsletter_message_page(nt('confirmed_title', $language), nt('confirmed_text', $language), $language);
}

/** GET /newsletter/desinscription?token=… : demande de confirmation (les robots qui suivent les liens ne désinscrivent pas). */
function newsletter_unsubscribe_page(): void
{
    $db = db();
    $token = request()->q('token');
    $subscriber = subscriber_by_token($db, $token);
    $language = $subscriber['language'] ?? newsletter_language($db);
    if (!$subscriber) {
        newsletter_message_page(nt('bad_link_title', $language), nt('bad_link_text', $language), $language, 404);
        return;
    }
    if ($subscriber['status'] === 'unsubscribed') {
        newsletter_message_page(nt('unsub_title', $language), nt('unsub_done', $language), $language);
        return;
    }
    $form = '<form method="post" action="/newsletter/desinscription?token=' . q($token) . '"><button class="primary-button" type="submit">' . esc(nt('unsub_button', $language)) . '</button></form>';
    public_page(nt('unsub_title', $language), '<main class="article-page newsletter-page"><h1>' . esc(nt('unsub_title', $language)) . '</h1><p>' . esc(sprintf(nt('unsub_question', $language), $subscriber['email'])) . '</p>' . $form . '</main>');
}

/** POST /newsletter/desinscription?token=… (bouton de la page, ou désinscription en un clic des messageries, RFC 8058). */
function newsletter_unsubscribe(): void
{
    $db = db();
    $subscriber = subscriber_by_token($db, request()->q('token') ?: request()->form('token'));
    $language = $subscriber['language'] ?? newsletter_language($db);
    if (!$subscriber) {
        newsletter_message_page(nt('bad_link_title', $language), nt('bad_link_text', $language), $language, 404);
        return;
    }
    if ($subscriber['status'] !== 'unsubscribed') {
        $db->execute("UPDATE subscribers SET status='unsubscribed', unsubscribed_at=? WHERE id=?", [now_iso(), $subscriber['id']]);
        $db->execute("UPDATE mail_queue SET status='cancelled' WHERE subscriber_id=? AND status='pending'", [$subscriber['id']]);
    }
    newsletter_message_page(nt('unsub_title', $language), nt('unsub_done', $language), $language);
}

/**
 * À la première publication d'un article : un e-mail par abonné actif est mis en file. La clé primaire de
 * article_mailings garantit qu'un article n'est jamais annoncé deux fois (même s'il est dépublié puis republié).
 */
function queue_article_mailing(Db $db, string $articleId): int
{
    return (int) $db->transaction(function (Db $db) use ($articleId): int {
        $now = now_iso();
        if ($db->execute('INSERT INTO article_mailings(article_id,queued_at) VALUES(?,?) ON CONFLICT DO NOTHING', [$articleId, $now])->rowCount() === 0) {
            return 0;
        }
        $count = $db->execute("INSERT INTO mail_queue(kind,subscriber_id,article_id,status,created_at,next_attempt_at) SELECT 'article', id, ?, 'pending', ?, ? FROM subscribers WHERE status='active'",
            [$articleId, $now, $now])->rowCount();
        $db->execute('UPDATE article_mailings SET recipients=? WHERE article_id=?', [$count, $articleId]);
        return $count;
    });
}

function unsubscribe_url(array $subscriber): string
{
    return absolute_url('/newsletter/desinscription?token=' . rawurlencode($subscriber['token']));
}

/** Gabarit HTML des e-mails (tableaux et styles en ligne, lus par toutes les messageries). */
function email_layout(string $language, string $content, string $unsubscribeUrl = ''): string
{
    $footer = '<p style="margin:0;font-size:12px;line-height:1.6;color:#6b6b74">' . esc(nt('footer', $language)) . ($unsubscribeUrl !== '' ? '<br><a href="' . esc($unsubscribeUrl) . '" style="color:#1f6b3c">' . esc(nt('unsubscribe', $language)) . '</a>' : '') . '</p>';
    return '<!doctype html><html lang="' . esc($language) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#f3f5f3">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f3"><tr><td align="center" style="padding:24px 12px">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#28232e">'
        . '<tr><td style="background:#1f6b3c;padding:14px 24px;color:#ffffff;font:italic 16px Georgia,serif;text-align:center">Peuples et citoyens du monde</td></tr>'
        . '<tr><td style="padding:22px 24px 6px;font:bold 26px Arial,sans-serif;color:#141414"><span style="color:#e5232b">I</span>-<span style="color:#148044">D</span>ialog<span style="color:#e5232b">o</span>s</td></tr>'
        . '<tr><td style="padding:10px 24px 24px">' . $content . '</td></tr>'
        . '<tr><td style="padding:16px 24px;border-top:1px solid #e5e2e1">' . $footer . '</td></tr></table></td></tr></table></body></html>';
}

function email_button(string $url, string $label): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0"><tr><td style="border-radius:999px;background:#1f6b3c"><a href="' . esc($url) . '" style="display:inline-block;padding:12px 26px;color:#ffffff;font:bold 15px Arial,sans-serif;text-decoration:none;border-radius:999px">' . esc($label) . '</a></td></tr></table>';
}

/** Contenu d'un e-mail de la file : [destinataire, sujet, HTML, texte, en-têtes] ou null si l'envoi n'a plus lieu d'être. */
function render_queued_mail(Db $db, array $item): ?array
{
    $subscriber = $db->one('SELECT * FROM subscribers WHERE id=?', [$item['subscriber_id']]);
    if (!$subscriber) {
        return null;
    }
    $language = isset(NEWSLETTER_TEXTS[$subscriber['language']]) ? $subscriber['language'] : 'fr';
    if ($item['kind'] === 'confirm') {
        if ($subscriber['status'] !== 'pending') {
            return null;
        }
        $url = absolute_url('/newsletter/confirmer?token=' . rawurlencode($subscriber['token']));
        // Lien de désinscription dans chaque e-mail, y compris celui-ci (il annule la demande d'inscription).
        $unsubscribe = unsubscribe_url($subscriber);
        $html = email_layout($language, '<p style="font-size:16px;line-height:1.6">' . esc(nt('confirm_intro', $language)) . '</p>' . email_button($url, nt('confirm_button', $language)) . '<p style="font-size:13px;line-height:1.6;color:#6b6b74">' . esc(nt('confirm_ignore', $language)) . '</p>', $unsubscribe);
        $text = nt('confirm_intro', $language) . "\n\n" . $url . "\n\n" . nt('confirm_ignore', $language) . "\n\n--\n" . nt('unsubscribe', $language) . ' : ' . $unsubscribe;
        return [$subscriber['email'], nt('confirm_subject', $language), $html, $text, ['List-Unsubscribe' => '<' . $unsubscribe . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']];
    }
    if ($subscriber['status'] !== 'active') {
        return null;
    }
    $article = $db->one("SELECT * FROM articles WHERE id=? AND status='published'", [$item['article_id']]);
    if (!$article) {
        return null;
    }
    $article = translate_records($db, [$article], $language, ['title', 'excerpt'])[0];
    $link = absolute_url('/article/' . rawurlencode($article['slug']));
    $unsubscribe = unsubscribe_url($subscriber);
    $image = $article['featured_image'] !== '' ? '<a href="' . esc($link) . '"><img src="' . esc(absolute_url($article['featured_image'])) . '" alt="" width="552" style="display:block;width:100%;max-width:552px;height:auto;border-radius:10px;margin:0 0 18px"></a>' : '';
    $content = '<p style="margin:0 0 10px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#1f6b3c;font-weight:bold">' . esc(nt('new_article', $language)) . '</p>' . $image
        . '<h1 style="margin:0 0 10px;font:bold 24px/1.25 Georgia,serif;color:#141414"><a href="' . esc($link) . '" style="color:#141414;text-decoration:none">' . esc($article['title']) . '</a></h1>'
        . '<p style="margin:0 0 6px;font-size:14px;color:#6b6b74">' . esc($article['author']) . '</p>'
        . '<p style="margin:0;font-size:16px;line-height:1.6">' . esc($article['excerpt']) . '</p>' . email_button($link, nt('read', $language));
    $text = $article['title'] . "\n\n" . $article['excerpt'] . "\n\n" . nt('read', $language) . ' : ' . $link . "\n\n--\n" . nt('footer', $language) . "\n" . nt('unsubscribe', $language) . ' : ' . $unsubscribe;
    $headers = ['List-Unsubscribe' => '<' . $unsubscribe . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
    return [$subscriber['email'], $article['title'], email_layout($language, $content, $unsubscribe), $text, $headers];
}

/** Envoie un e-mail : Brevo (API HTTP) en production, journal JSON avec MAIL_TRANSPORT=log. */
function send_mail(string $to, string $subject, string $html, string $text, array $headers = []): void
{
    $fromEmail = setting('MAIL_FROM_EMAIL');
    $fromName = setting('MAIL_FROM_NAME', 'I-Dialogos');
    if (setting('MAIL_TRANSPORT') === 'log') {
        $file = setting('MAIL_LOG_FILE') ?: sys_get_temp_dir() . '/idialogos-mails.jsonl';
        file_put_contents($file, json_encode(['to' => $to, 'from' => $fromEmail, 'subject' => $subject, 'html' => $html, 'text' => $text, 'headers' => $headers], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        return;
    }
    $key = setting('BREVO_API_KEY');
    if ($key === '' || $fromEmail === '') {
        throw new MailNotConfigured('BREVO_API_KEY et MAIL_FROM_EMAIL doivent être réglés dans app.env.');
    }
    if (!function_exists('curl_init')) {
        throw new MailNotConfigured('Extension PHP curl indisponible.');
    }
    $payload = ['sender' => ['email' => $fromEmail, 'name' => $fromName], 'to' => [['email' => $to]], 'subject' => $subject, 'htmlContent' => $html, 'textContent' => $text];
    if ($headers) {
        $payload['headers'] = $headers;
    }
    $curl = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $key]]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Brevo : ' . ($error ?: "réponse HTTP $status " . mb_cut((string) $response, 200)));
    }
}

/**
 * Traite la file d'envoi (confirmations d'abord), dans la limite du quota sur 24 h et du temps imparti.
 * Un verrou PostgreSQL empêche deux traitements simultanés. Renvoie le nombre d'e-mails envoyés.
 */
function process_mail_queue(int $budgetSeconds = 25, int $maxMessages = 60): int
{
    $db = db();
    if (!$db->value('SELECT pg_try_advisory_lock(?)', [781205])) {
        return 0;
    }
    $sent = 0;
    try {
        $deadline = time() + $budgetSeconds;
        $limit = max(1, (int) (setting('MAIL_DAILY_LIMIT', '300') ?: 300));
        $since = gmdate('Y-m-d\TH:i:s', time() - 86400) . '+00:00';
        $remaining = $limit - (int) $db->value("SELECT COUNT(*) FROM mail_queue WHERE status='sent' AND sent_at >= ?", [$since]);
        $batch = $db->all("SELECT * FROM mail_queue WHERE status='pending' AND next_attempt_at <= ? ORDER BY CASE kind WHEN 'confirm' THEN 0 ELSE 1 END, id LIMIT ?",
            [now_iso(), max(0, min($maxMessages, $remaining))]);
        foreach ($batch as $item) {
            if (time() > $deadline) {
                break;
            }
            $mail = render_queued_mail($db, $item);
            if ($mail === null) {
                $db->execute("UPDATE mail_queue SET status='cancelled' WHERE id=?", [$item['id']]);
                continue;
            }
            try {
                send_mail(...$mail);
                $db->execute("UPDATE mail_queue SET status='sent', sent_at=?, last_error='' WHERE id=?", [now_iso(), $item['id']]);
                $sent++;
            } catch (MailNotConfigured $error) {
                // Rien n'est perdu : la file attend la configuration de l'envoi.
                $db->execute('UPDATE mail_queue SET last_error=? WHERE id=?', [$error->getMessage(), $item['id']]);
                break;
            } catch (Throwable $error) {
                $attempts = (int) $item['attempts'] + 1;
                $next = gmdate('Y-m-d\TH:i:s', time() + 300 * $attempts) . '+00:00';
                $db->execute('UPDATE mail_queue SET attempts=?, last_error=?, next_attempt_at=?, status=? WHERE id=?',
                    [$attempts, mb_cut($error->getMessage(), 500), $next, $attempts >= MAIL_MAX_ATTEMPTS ? 'failed' : 'pending', $item['id']]);
            }
        }
    } finally {
        $db->execute('SELECT pg_advisory_unlock(?)', [781205]);
    }
    return $sent;
}

// --- Administration ------------------------------------------------------------------------------------------------

function newsletter_admin_page(string $notice = ''): void
{
    $db = db();
    $counts = array_column($db->all('SELECT status, COUNT(*) AS total FROM subscribers GROUP BY status'), 'total', 'status');
    $queue = array_column($db->all('SELECT status, COUNT(*) AS total FROM mail_queue GROUP BY status'), 'total', 'status');
    $sent24 = (int) $db->value("SELECT COUNT(*) FROM mail_queue WHERE status='sent' AND sent_at >= ?", [gmdate('Y-m-d\TH:i:s', time() - 86400) . '+00:00']);
    $lastError = (string) $db->value("SELECT last_error FROM mail_queue WHERE last_error <> '' ORDER BY id DESC LIMIT 1");
    $warnings = [];
    if (setting('MAIL_TRANSPORT') !== 'log' && (setting('BREVO_API_KEY') === '' || setting('MAIL_FROM_EMAIL') === '')) {
        $warnings[] = 'L’envoi n’est pas configuré : ajoutez BREVO_API_KEY et MAIL_FROM_EMAIL dans app.env. Les e-mails attendent dans la file.';
    }
    if (safe_external_url(setting('SITE_URL')) === '') {
        $warnings[] = 'SITE_URL n’est pas réglée : les liens des e-mails utilisent l’adresse de la requête en cours (' . site_url() . ').';
    }
    $warningHtml = implode('', array_map(fn($text) => '<div class="form-error">' . esc($text) . '</div>', $warnings));
    $rows = '';
    $labels = ['pending' => 'En attente de confirmation', 'active' => 'Abonné', 'unsubscribed' => 'Désinscrit'];
    $page = admin_list_page();
    $total = (int) $db->value('SELECT COUNT(*) FROM subscribers');
    foreach ($db->all('SELECT * FROM subscribers ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?', [ADMIN_PAGE_SIZE, ($page - 1) * ADMIN_PAGE_SIZE]) as $row) {
        $rows .= '<tr><td><strong>' . esc($row['email']) . '</strong><small>' . esc(strtoupper($row['language'])) . '</small></td><td><span class="status-badge ' . ($row['status'] === 'active' ? 'published' : 'draft') . '">' . esc($labels[$row['status']] ?? $row['status']) . '</span></td><td>' . esc(substr($row['created_at'], 0, 10)) . '</td><td class="table-actions"><form method="post" action="/admin/newsletter/' . $row['id'] . '/delete" onsubmit="return confirm(\'Supprimer cet abonné ?\')">' . csrf_field() . '<button>Supprimer</button></form></td></tr>';
    }
    $rows = $rows ?: '<tr><td colspan="4">Aucun abonné pour le moment.</td></tr>';
    $stats = '<div class="admin-stats"><div><strong>' . (int) ($counts['active'] ?? 0) . '</strong><span>Abonnés</span></div><div><strong>' . (int) ($counts['pending'] ?? 0) . '</strong><span>En attente de confirmation</span></div><div><strong>' . (int) ($queue['pending'] ?? 0) . '</strong><span>E-mails dans la file</span></div><div><strong>' . $sent24 . ' / ' . max(1, (int) (setting('MAIL_DAILY_LIMIT', '300') ?: 300)) . '</strong><span>Envoyés sur 24 h</span></div></div>';
    $process = '<form method="post" action="/admin/newsletter/process">' . csrf_field() . '<button class="primary-button">Envoyer la file maintenant</button></form>';
    $errorInfo = $lastError !== '' ? '<p class="field-help">Dernière erreur d’envoi : ' . esc($lastError) . (isset($queue['failed']) ? ' · ' . (int) $queue['failed'] . ' e-mail(s) abandonné(s) après ' . MAIL_MAX_ATTEMPTS . ' essais.' : '') . '</p>' : '';
    $content = '<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Diffusion</span><h1>Newsletter</h1><p>Chaque nouvel article publié est annoncé une fois aux abonnés confirmés.</p></div>' . $process . '</div>'
        . ($notice !== '' ? '<p class="success-message">' . esc($notice) . '</p>' : '') . $warningHtml . $stats . $errorInfo
        . '<section class="admin-panel"><div class="admin-table-wrap"><table><thead><tr><th>Adresse</th><th>État</th><th>Inscription</th><th>Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . admin_pagination('/admin/newsletter', [], $page, $total) . '</section></main>';
    send_body(admin_page('Newsletter', $content, 'newsletter'));
}

function newsletter_admin_process(): void
{
    $sent = process_mail_queue(40, 100);
    newsletter_admin_page($sent . ' e-mail(s) envoyé(s).');
}

function delete_subscriber(string $subscriberId): void
{
    if (ctype_digit($subscriberId)) {
        db()->execute('DELETE FROM subscribers WHERE id=?', [(int) $subscriberId]);
    }
    redirect('/admin/newsletter');
}
