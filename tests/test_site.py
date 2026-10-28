"""Parcours complets du site, par de vraies requêtes HTTP vers l'application PHP."""
from __future__ import annotations

import gzip
import json
import os
import re
import time

import psycopg
import pytest
from conftest import ADMIN, DATABASE_URL, DEPLOY_TOKEN, MAIL_LOG, ROOT, SITE_URL, Client, query, reset_database

PNG = b'\x89PNG\r\n\x1a\n' + b'0' * 64


def category_field(page: str) -> tuple[str, list[str]]:
    match = re.findall(r'name="(categories(?:\[\])?)" value="(\d+)"', page)
    assert match, 'cases à cocher des catégories absentes'
    return match[0][0], [value for _, value in match]


def create_article(admin, title: str, status: str = 'published', category: int = 4, tags: str = 'Paix, #Afrique', **extra) -> str:
    form = admin.get('/admin/articles/new').text
    token = re.search(r'name="_csrf" value="([^"]+)"', form).group(1)
    field, categories = category_field(form)
    fields = [('_csrf', token), ('title', title), ('excerpt', extra.get('excerpt', 'Chapô éàü, 50 %')),
              ('body_html', extra.get('body', '<p>Texte <strong>riche</strong> 😀</p>')), ('author', 'Jean-Claude Mairal'),
              ('language', 'fr'), ('status', status), (field, categories[category]), ('new_tags', tags), ('comments_enabled', '1'),
              ('countries', extra.get('countries', ''))]
    response = admin.post('/admin/articles/save', fields, files={'featured_image': ('photo.png', 'image/png', PNG)})
    assert response.status == 303, response.text[:500]
    return query('SELECT id FROM articles WHERE title=%s', (title,))[0]['id']


def article_slug(article_id: str) -> str:
    return query('SELECT slug FROM articles WHERE id=%s', (article_id,))[0]['slug']


def test_healthz(server_url):
    response = Client(server_url).get('/healthz')
    assert response.status == 200
    report = json.loads(response.text)
    assert report['status'] == 'ok' and report['schema'] >= 2
    assert '"status": "ok"' in response.text  # format lu par le script de déploiement
    assert response.headers['cache-control'] == 'no-store'


def test_premier_demarrage_redirige_vers_la_configuration(server_url):
    response = Client(server_url).get('/admin/login')
    assert response.status in (200, 303)


def test_connexion_administrateur(admin, server_url):
    assert admin.get('/admin').status == 200
    other = Client(server_url)
    token = other.csrf('/admin/login')
    bad = other.post('/admin/login', [('_csrf', token), ('username', ADMIN[0]), ('password', 'mauvais-mot-de-passe')])
    assert bad.location.endswith('error=1')
    token = other.csrf('/admin/login')
    good = other.post('/admin/login', [('_csrf', token), ('username', ADMIN[0]), ('password', ADMIN[1])])
    assert good.location == '/admin'
    assert 'HttpOnly' in good.headers.get('set-cookie', '')


def test_second_compte_administrateur_refuse(admin, server_url):
    """Une fois le compte créé, /admin/setup ne doit plus permettre d'en créer un autre."""
    intruder = Client(server_url)
    page = intruder.get('/admin/login')
    token = re.search(r'name="_csrf" value="([^"]+)"', page.text).group(1)
    response = intruder.post('/admin/setup', [('_csrf', token), ('username', 'pirate'), ('password', 'x' * 20), ('confirm', 'x' * 20)])
    assert response.status == 409
    assert not query("SELECT 1 FROM users WHERE username='pirate'")


def test_formulaire_refuse_sans_jeton(server_url):
    response = Client(server_url).post('/admin/login', [('username', ADMIN[0]), ('password', ADMIN[1])])
    assert response.status == 403


def test_administration_protegee(server_url):
    anonymous = Client(server_url)
    for path in ('/admin/articles', '/admin/articles/new', '/admin/comments', '/admin/languages'):
        assert anonymous.get(path).location in ('/admin/login', '/admin/setup'), path


def test_article_publie_visible(admin, visitor):
    article_id = create_article(admin, 'Démocratie & 100% dialogue ? Oui')
    slug = article_slug(article_id)
    assert f'/article/{slug}' in visitor.get('/').text
    page = visitor.get(f'/article/{slug}')
    assert page.status == 200 and 'riche' in page.text and 'Paix' in page.text
    assert 'Démocratie &amp; 100% dialogue ? Oui' in page.text
    image = re.search(r'/uploads/[a-f0-9-]+\.png', page.text)
    assert image and visitor.get(image.group(0)).status == 200
    assert 'Démocratie' in visitor.get('/thematique/democraties').text


def test_contenu_nettoye(admin, visitor):
    article_id = create_article(admin, 'Article piégé', body='<p onclick="x()">Bonjour <script>alert(1)</script><a href="javascript:alert(2)">lien</a><img src="https://exemple.org/a.png" onerror="x()"></p>')
    html = query('SELECT body_html FROM articles WHERE id=%s', (article_id,))[0]['body_html']
    assert 'script' not in html and 'javascript' not in html and 'onclick' not in html and 'onerror' not in html
    assert 'https://exemple.org/a.png' in html


def test_image_refusee_si_invalide(admin):
    form = admin.get('/admin/articles/new').text
    token = re.search(r'name="_csrf" value="([^"]+)"', form).group(1)
    response = admin.post('/admin/articles/save', [('_csrf', token), ('title', 'Faux PNG'), ('author', 'A'), ('status', 'draft')],
                          files={'featured_image': ('photo.png', 'image/png', b'<?php echo 1; ?>')})
    assert response.status == 200 and 'image JPEG, PNG, GIF ou WebP valide' in response.text
    assert not query("SELECT 1 FROM articles WHERE title='Faux PNG'")


def test_brouillon_invisible(admin, visitor):
    article_id = create_article(admin, 'Brouillon secret', status='draft')
    assert visitor.get(f'/article/{article_slug(article_id)}').status == 404
    assert 'Brouillon secret' not in visitor.get('/').text


def test_modification_article(admin):
    article_id = create_article(admin, 'Titre à modifier')
    token = admin.csrf(f'/admin/articles/{article_id}/edit')
    fields = [('_csrf', token), ('id', article_id), ('title', 'Titre modifié'), ('excerpt', 'x'), ('body_html', '<p>y</p>'),
              ('author', 'Auteur'), ('language', 'fr'), ('status', 'published')]
    assert admin.post('/admin/articles/save', fields).status == 303
    assert query('SELECT title FROM articles WHERE id=%s', (article_id,))[0]['title'] == 'Titre modifié'


def test_commentaire_modere(admin, visitor):
    slug = article_slug(create_article(admin, 'Article commenté'))
    token = visitor.csrf(f'/article/{slug}')
    assert visitor.post(f'/article/{slug}/comment', [('_csrf', token), ('author', 'Lectrice'), ('email', 'a@b.fr'), ('body', 'Très bon article !')]).status == 303
    assert 'Très bon article' not in visitor.get(f'/article/{slug}').text
    moderation = admin.get('/admin/comments')
    comment_id = re.search(r'/admin/comments/(\d+)', moderation.text).group(1)
    admin.post(f'/admin/comments/{comment_id}', [('_csrf', admin.csrf('/admin/comments')), ('action', 'approve')])
    assert 'Très bon article' in visitor.get(f'/article/{slug}').text


def test_actualite(admin, visitor):
    token = admin.csrf('/admin/events/new')
    response = admin.post('/admin/events/save', [('_csrf', token), ('kind', 'event'), ('title', 'Colloque à Dakar'), ('summary', 'Résumé'),
                                                 ('body', 'Programme\nDeuxième ligne'), ('location', 'Dakar'), ('starts_at', '2026-11-20'),
                                                 ('ends_at', ''), ('status', 'published'), ('language', 'auto'), ('image_url', '')])
    assert response.status == 303
    listing = visitor.get('/actualites').text
    assert 'Colloque' in listing
    slug = re.search(r'/actualite/([a-z0-9-]+)', listing).group(1)
    page = visitor.get(f'/actualite/{slug}')
    assert page.status == 200 and '<p>Deuxième ligne</p>' in page.text


def test_taxonomies_et_modeles(admin):
    admin.post('/admin/categories', [('_csrf', admin.csrf('/admin/categories')), ('name', 'Économie')])
    assert 'Économie' in admin.get('/admin/categories').text
    assert query("SELECT slug FROM categories WHERE name='Économie'")[0]['slug'] == 'economie'
    admin.post('/admin/templates', [('_csrf', admin.csrf('/admin/templates')), ('action', 'create'), ('name', 'Tribune'), ('body_html', '<p>x</p>')])
    assert 'Tribune' in admin.get('/admin/templates').text
    assert admin.get('/admin/tags').status == 200
    assert admin.get('/admin/languages').status == 200


def test_traduction_mise_en_cache(admin, visitor):
    slug = article_slug(create_article(admin, 'Article à traduire', body='<p>Bonjour le <strong>monde</strong></p>'))
    time.sleep(0.5)
    page = visitor.get(f'/article/{slug}?lang=en')
    assert '[En] Bonjour le <strong>monde</strong>' in page.text  # même rendu que la version Python
    assert 'Automatic translation' in page.text
    assert query("SELECT COUNT(*) AS total FROM translations WHERE target='en'")[0]['total'] > 0
    assert '[en]' in visitor.get(f'/article/{slug}?lang=en').text
    original = visitor.get(f'/article/{slug}?lang=en&original=1')
    assert '[En] Bonjour' not in original.text


def test_accueil_traduit(visitor):
    page = visitor.get('/?lang=es')
    assert page.status == 200 and '[es]' in page.text and 'lang="es"' in page.text


def test_suppression_article_en_cascade(admin):
    article_id = create_article(admin, 'Article à supprimer')
    admin.post(f'/admin/articles/{article_id}/delete', [('_csrf', admin.csrf('/admin/articles'))])
    assert not query('SELECT 1 FROM articles WHERE id=%s', (article_id,))
    assert not query('SELECT 1 FROM article_categories WHERE article_id=%s', (article_id,))


def test_fichiers_statiques_et_erreurs(visitor):
    assert visitor.get('/style.css').status == 200
    assert visitor.get('/api/languages').status == 200
    assert visitor.request('HEAD', '/').status == 200
    missing = visitor.get('/page-inexistante')
    assert missing.status == 404 and 'introuvable' in missing.text
    for path in ('/uploads/../src/config.php', '/src/config.php', '/assets/../src/db.php', '/../app.env'):
        assert visitor.get(path).status in (400, 404), path  # Apache refuse lui-même les chemins en « .. » (400)


# --- Accueil, listes paginées et filtrées, articles similaires ---------------------------------------------------------

def create_event(admin, title: str, kind: str = 'news', summary: str = 'Résumé de l’actualité') -> None:
    token = admin.csrf('/admin/events/new')
    response = admin.post('/admin/events/save', [('_csrf', token), ('kind', kind), ('title', title), ('summary', summary), ('body', 'Texte'),
                                                 ('location', ''), ('starts_at', ''), ('ends_at', ''), ('status', 'published'),
                                                 ('language', 'fr'), ('image_url', '')])
    assert response.status == 303


def card_slugs(html: str) -> list[str]:
    return re.findall(r'class="note-title" href="/article/([^"]+)"', html)


def test_accueil_en_titres_et_analyses_en_deroule(admin, visitor):
    for number in range(6):
        article_id = create_article(admin, f'Accueil limité {number}', excerpt=f'Chapô accueil {number}')
        query('UPDATE articles SET published_at=%s WHERE id=%s', (f'2032-01-0{number + 1}T10:00:00+00:00', article_id))  # les plus récents
        create_event(admin, f'Actualité accueil {number}')
    page = visitor.get('/').text
    assert 'Accueil limité 5' in page.split('class="featured-article"', 1)[1].split('</article>', 1)[0]  # à la une
    published = query("SELECT COUNT(*) AS n FROM articles WHERE status='published' AND archived=0")[0]['n']
    column = page.split('<ol class="analysis-scroll"', 1)[1].split('</ol>', 1)[0]  # colonne de droite à défilement vertical
    assert column.count('class="analysis-item"') == min(12, published - 1)
    assert 'Accueil limité 4' in column and 'Chapô accueil' not in page  # titres seuls, sans chapô
    assert len(re.findall(r'<li class="event-entry"', page)) == 4 and 'data-carousel' not in page
    assert 'href="/analyses"' in page and 'href="/actualites"' in page
    assert 'id="public-feed"' not in page  # le bloc « Les Notes » est remplacé par un lien vers /analyses


def test_pagination_des_analyses(admin, visitor):
    for number in range(14):
        article_id = create_article(admin, f'Paginationzeta numéro {number:02d}')
        query('UPDATE articles SET published_at=%s WHERE id=%s', (f'2020-01-{number + 1:02d}T10:00:00+00:00', article_id))
    first = visitor.get('/analyses?q=Paginationzeta')
    assert first.status == 200 and len(card_slugs(first.text)) == 12  # 12 éléments par page
    assert '<nav class="pagination" aria-label="Pagination">' in first.text and 'aria-current="page"' in first.text
    assert 'href="/analyses?q=Paginationzeta&amp;page=2"' in first.text  # le filtre est conservé dans la pagination
    assert 'numéro 13' in first.text and 'numéro 01' not in first.text  # du plus récent au plus ancien
    second = visitor.get('/analyses?q=Paginationzeta&page=2')
    assert second.status == 200 and len(card_slugs(second.text)) == 2 and 'numéro 00' in second.text
    beyond = visitor.get('/analyses?q=Paginationzeta&page=3')
    assert beyond.status == 404
    assert 'id="accueil"' in beyond.text and '<footer' in beyond.text and 'href="/analyses?q=Paginationzeta"' in beyond.text
    assert visitor.get('/analyses?page=abc').status == 200  # numéro invalide : page 1


def test_filtres_combines(admin, visitor):
    alpha = create_article(admin, 'Filtrexyz alpha', category=1, tags='Kivu, Musique')
    beta = create_article(admin, 'Filtrexyz beta', category=1, tags='Kivu')
    create_article(admin, 'Filtrexyz gamma', category=2, tags='Kivu')
    create_article(admin, 'Filtrexyz delta brouillon', category=1, tags='Kivu', status='draft')
    category = query('SELECT c.slug FROM categories c JOIN article_categories ac ON ac.category_id=c.id WHERE ac.article_id=%s', (alpha,))[0]['slug']
    page = visitor.get(f'/analyses?categorie={category}&tag=kivu&q=Filtrexyz').text
    assert 'Filtrexyz alpha' in page and 'Filtrexyz beta' in page
    assert 'Filtrexyz gamma' not in page and 'brouillon' not in page
    assert f'<option value="{category}" selected>' in page  # le filtre reste sélectionné
    music = visitor.get(f'/analyses?categorie={category}&tag=musique&q=Filtrexyz').text
    assert 'Filtrexyz alpha' in music and 'Filtrexyz beta' not in music
    query('UPDATE articles SET language=%s WHERE id=%s', ('en', beta))
    english = visitor.get('/analyses?langue=en&q=Filtrexyz').text
    assert 'Filtrexyz beta' in english and 'Filtrexyz alpha' not in english
    empty = visitor.get('/analyses?q=Filtrexyz&tag=inexistant')
    assert empty.status == 200 and 'Aucun résultat ne correspond à ces filtres.' in empty.text
    hostile = visitor.get('/analyses?q=%25_%27%22%3Cscript%3E')  # pris au pied de la lettre, sans erreur ni injection
    assert hostile.status == 200 and '<script>' not in hostile.text.split('</header>', 1)[1].split('<footer', 1)[0]


def test_liste_des_actualites_filtree(admin, visitor):
    create_event(admin, 'Typefiltre colloque', kind='event')
    create_event(admin, 'Typefiltre annonce', kind='news')
    events = visitor.get('/actualites?type=event&q=Typefiltre').text
    assert 'Typefiltre colloque' in events and 'Typefiltre annonce' not in events
    assert visitor.get('/actualites?q=Typefiltre&page=5').status == 404


def test_anciennes_adresses_redirigees(visitor):
    response = visitor.get('/?category=democraties')
    assert response.status == 301 and response.location == '/analyses?categorie=democraties'
    assert visitor.get('/?tag=paix&q=dialogue').location == '/analyses?tag=paix&q=dialogue'


def test_articles_similaires_et_navigation(admin, visitor):
    main = create_article(admin, 'Similaire principal', category=6, tags='Sahel, Eau')
    close = create_article(admin, 'Similaire proche', category=6, tags='Sahel, Eau')
    draft = create_article(admin, 'Similaire brouillon', category=6, tags='Sahel, Eau', status='draft')
    for number in range(2):
        create_article(admin, f'Similaire autre {number}', category=7, tags='Autre')
    query('UPDATE articles SET published_at=%s WHERE id=%s', ('2031-01-01T10:00:00+00:00', main))
    query('UPDATE articles SET published_at=%s WHERE id=%s', ('2031-01-02T10:00:00+00:00', close))
    page = visitor.get(f'/article/{article_slug(main)}').text
    aside = page.split('<aside class="article-aside"', 1)[1].split('</aside>', 1)[0]  # colonne à droite de l'article
    related = re.findall(r'class="aside-article" href="/article/([^"]+)"', aside)
    assert 3 <= len(related) <= 4
    assert article_slug(main) not in related and article_slug(draft) not in related  # jamais l'article courant ni un brouillon
    assert related[0] == article_slug(close)  # celui qui partage le plus de thèmes et de mots-clés
    assert f'rel="next" href="/article/{article_slug(close)}"' in page and 'rel="prev"' in page



# --- Lot 1 : navigation et en-tête ------------------------------------------------------------------------------------

def test_en_tete_logo_don_et_lien_actif(visitor):
    home = visitor.get('/').text
    assert '<a class="logo" href="/"' in home  # le logo ramène en haut de l'accueil
    assert '<img src="/assets/logo-i-dialogos.png" alt="I-Dialogos, think tank"' in home  # logo officiel (image), pas une réécriture
    assert '<summary>À propos</summary>' in home and 'À propos de nous' not in home
    assert home.count('<details name="menu">') == 5  # cinq rubriques dépliantes ; en ouvrir une referme les autres
    assert 'class="donate-button" href="https://exemple.org/don" target="_blank" rel="noopener"' in home
    notes = visitor.get('/analyses').text
    assert '<summary class="is-active">Les Notes</summary>' in notes and '<a href="/analyses" aria-current="page">' in notes
    assert 'class="donate-button"' in notes  # même en-tête sur toutes les pages publiques
    theme = visitor.get('/thematique/jeunesse').text
    assert '<summary class="is-active">Thématiques</summary>' in theme
    assert 'href="/thematique/jeunesse" aria-current="page"' in theme
    assert 'aria-current="page"' not in visitor.get('/actualites').text.split('</header>', 1)[0].split('<nav aria-label="Navigation principale">', 1)[1]



# --- Lot 2 : pied de page -------------------------------------------------------------------------------------------

def test_pied_de_page_appels_a_l_action(visitor):
    for path in ('/', '/analyses'):
        footer = visitor.get(path).text.split('<footer', 1)[1]
        assert 'class="footer-cta"' in footer
        assert 'class="footer-cta-button is-donate" href="https://exemple.org/don"' in footer
        assert 'href="https://www.helloasso.com/associations/i-dialogos-international/adhesions/adhesions-i-dialogos-2026"' in footer
        assert 'data-newsletter-link' in footer and 'S’abonner à la newsletter' in footer



# --- Lot 3 : invités de la semaine et experts ------------------------------------------------------------------------

def save_person(admin, section: str, fields: list, photo: bool = True):
    token = admin.csrf(f'/admin/{section}/new')
    files = {'photo': ('portrait.png', 'image/png', PNG)} if photo else None
    return admin.post(f'/admin/{section}/save', [('_csrf', token), *fields], files=files)


def expert_track(html: str) -> str:
    return html.split('class="mk-expert-track"', 1)[1].split('</section>', 1)[0]


def guest_section(html: str) -> str:
    return html.split('id="invitee"', 1)[1].split('</section>', 1)[0]


def test_invites_et_experts_contenu_fixe_tant_que_la_base_est_vide(visitor):
    home = visitor.get('/').text
    assert 'href="/invites-et-experts">Voir plus d’informations<br>L’Invité/e de la semaine' in home
    assert 'Oumou Sall Seck' in guest_section(home) and 'Giuseppe GILIBERTI' in expert_track(home)
    page = visitor.get('/invites-et-experts')
    assert page.status == 200 and 'Oumou Sall Seck' in page.text and 'Giuseppe GILIBERTI' in page.text
    assert '<summary class="is-active">Experts</summary>' in page.text and 'href="/invites-et-experts" aria-current="page"' in page.text
    assert visitor.get('/admin/experts').location.endswith('/admin/login')  # administration protégée


def test_gestion_des_invites_et_des_experts(admin, visitor):
    assert save_person(admin, 'invites', [('name', 'Invitée Ancienne'), ('expertise', 'Diplomatie'), ('region', 'Sénégal'),
                                          ('description', 'Première ligne\nSeconde ligne'), ('featured_on', '2026-01-05')]).status == 303
    assert save_person(admin, 'invites', [('name', 'Invité Récent'), ('expertise', 'Médias'), ('region', 'Québec'),
                                          ('description', 'Journaliste <b>engagé</b>'), ('featured_on', '2026-02-02')], photo=False).status == 303
    assert save_person(admin, 'experts', [('name', 'Experte Dynamique'), ('expertise', 'Droit constitutionnel'), ('region', 'Brésil'),
                                          ('description', 'Professeure'), ('position', '1')]).status == 303
    home = visitor.get('/').text
    assert 'Invité Récent' in guest_section(home) and 'Oumou Sall Seck' not in guest_section(home)  # le plus récent
    assert 'Journaliste &lt;b&gt;engagé&lt;/b&gt;' in guest_section(home)  # texte échappé
    assert 'Experte Dynamique' in expert_track(home) and 'GILIBERTI' not in expert_track(home)
    page = visitor.get('/invites-et-experts').text
    assert page.index('Invité Récent') < page.index('Invités précédents') < page.index('Invitée Ancienne')
    assert '<p>Première ligne</p><p>Seconde ligne</p>' in page and 'Diplomatie · Sénégal' in page
    assert re.search(r'src="/uploads/[a-f0-9-]+\.png" alt="Portrait de Invitée Ancienne"', page)
    expert_id = query('SELECT id FROM people WHERE name=%s', ('Experte Dynamique',))[0]['id']
    edit = admin.get(f'/admin/experts/{expert_id}/edit').text
    token = re.search(r'name="_csrf" value="([^"]+)"', edit).group(1)
    admin.post('/admin/experts/save', [('_csrf', token), ('id', str(expert_id)), ('name', 'Experte Modifiée'), ('expertise', 'Droit'),
                                       ('region', 'Brésil'), ('description', ''), ('position', '2')])
    assert 'Experte Modifiée' in visitor.get('/invites-et-experts').text
    assert query('SELECT photo FROM people WHERE id=%s', (expert_id,))[0]['photo'].startswith('/uploads/')  # photo conservée
    missing = save_person(admin, 'experts', [('name', '   ')], photo=False)
    assert missing.status == 200 and 'Le nom est obligatoire.' in missing.text
    admin.post(f'/admin/experts/{expert_id}/delete', [('_csrf', admin.csrf('/admin/experts'))])
    assert not query('SELECT 1 FROM people WHERE id=%s', (expert_id,))
    assert 'Giuseppe GILIBERTI' in expert_track(visitor.get('/').text)  # plus aucun expert : contenu fixe de retour



# --- Lot 4 : newsletter ----------------------------------------------------------------------------------------------

def newsletter_post(client, email: str, **extra):
    return client.post('/newsletter', [('email', email), *extra.items()], headers={'Accept': 'application/json'})


def read_mails() -> list:
    if not MAIL_LOG.exists():
        return []
    return [json.loads(line) for line in MAIL_LOG.read_text(encoding='utf-8').splitlines() if line.strip()]


def wait_for_mail(predicate, timeout: float = 8) -> list:
    """Les e-mails partent après la réponse (file traitée en arrière-plan) : on attend qu'ils apparaissent."""
    deadline = time.time() + timeout
    while time.time() < deadline:
        found = [mail for mail in read_mails() if predicate(mail)]
        if found:
            return found
        time.sleep(0.2)
    return []


def resave_article(admin, article_id: str, title: str, status: str) -> None:
    form = admin.get(f'/admin/articles/{article_id}/edit').text
    token = re.search(r'name="_csrf" value="([^"]+)"', form).group(1)
    field, categories = category_field(form)
    fields = [('_csrf', token), ('id', article_id), ('title', title), ('excerpt', 'Extrait modifié'), ('body_html', '<p>Texte modifié</p>'),
              ('author', 'Jean-Claude Mairal'), ('language', 'fr'), ('status', status), (field, categories[4]), ('comments_enabled', '1')]
    assert admin.post('/admin/articles/save', fields).status == 303


def test_inscription_newsletter_avec_double_confirmation(visitor):
    query('DELETE FROM rate_events')
    bad = newsletter_post(visitor, 'pas-une-adresse')
    assert bad.status == 422 and json.loads(bad.text) == {'ok': False, 'message': 'Saisissez une adresse e-mail valide.'}
    ok = newsletter_post(visitor, '  Lectrice@Exemple.org ')
    assert ok.status == 200 and json.loads(ok.text)['ok'] and 'e-mail de confirmation' in json.loads(ok.text)['message']
    assert query('SELECT status FROM subscribers WHERE email=%s', ('lectrice@exemple.org',))[0]['status'] == 'pending'
    mail = wait_for_mail(lambda m: m['to'] == 'lectrice@exemple.org' and m['subject'].startswith('Confirmez'))
    assert mail, 'e-mail de confirmation absent'
    link = re.search(re.escape(SITE_URL) + r'(/newsletter/confirmer\?token=[A-Za-z0-9_-]+)', mail[-1]['html']).group(1)
    assert f'{SITE_URL}/newsletter/desinscription?token=' in mail[-1]['html']  # lien de désinscription dans chaque e-mail
    pending_again = newsletter_post(visitor, 'lectrice@exemple.org')
    assert json.loads(pending_again.text)['message'].startswith('Votre inscription attend')  # doublon en attente
    confirmed = visitor.get(link)
    assert confirmed.status == 200 and 'Inscription confirmée' in confirmed.text
    assert query('SELECT status FROM subscribers WHERE email=%s', ('lectrice@exemple.org',))[0]['status'] == 'active'
    duplicate = newsletter_post(visitor, 'LECTRICE@exemple.org')
    assert json.loads(duplicate.text)['message'] == 'Cette adresse est déjà inscrite à la newsletter.'
    assert len(query('SELECT id FROM subscribers WHERE email=%s', ('lectrice@exemple.org',))) == 1
    trap = newsletter_post(visitor, 'robot@exemple.org', website='https://spam.example')
    assert trap.status == 200 and not query('SELECT 1 FROM subscribers WHERE email=%s', ('robot@exemple.org',))  # champ piège
    without_js = visitor.post('/newsletter', [('email', 'sans-js@exemple.org')])
    assert without_js.status == 200 and 'id="accueil"' in without_js.text and 'e-mail de confirmation' in without_js.text
    assert visitor.get('/newsletter/confirmer?token=jeton-inconnu-0123456789').status == 404


def test_limite_des_inscriptions_par_adresse_ip(visitor):
    query('DELETE FROM rate_events')
    statuses = [newsletter_post(visitor, f'limite{number}@exemple.org').status for number in range(7)]
    assert statuses[:5] == [200] * 5 and statuses[5:] == [429, 429]
    query('DELETE FROM rate_events')


def test_annonce_des_articles_aux_abonnes_une_seule_fois(admin, visitor, server_url):
    now = '2026-01-01T00:00:00+00:00'
    for email, status, token in (('abonne@exemple.org', 'active', 'jeton-abonne-0123456789abcdef'), ('parti@exemple.org', 'unsubscribed', 'jeton-parti-0123456789abcdef'),
                                 ('attente@exemple.org', 'pending', 'jeton-attente-0123456789abcdef')):
        query('INSERT INTO subscribers(email,status,token,language,created_at) VALUES(%s,%s,%s,%s,%s)', (email, status, token, 'fr', now))
    draft = create_article(admin, 'Annonce brouillon Zeta', status='draft')
    article_id = create_article(admin, 'Annonce Newsletter Zeta', excerpt='Un extrait pour la newsletter')
    mail = wait_for_mail(lambda m: m['subject'] == 'Annonce Newsletter Zeta')
    mail = wait_for_mail(lambda m: m['subject'] == 'Annonce Newsletter Zeta' and m['to'] == 'abonne@exemple.org')
    recipients = {m['to'] for m in read_mails() if m['subject'] == 'Annonce Newsletter Zeta'}
    assert recipients == {'abonne@exemple.org', 'lectrice@exemple.org'}  # abonnés confirmés seulement (ni désinscrit, ni en attente)
    html = mail[0]['html']
    assert f'{SITE_URL}/article/{article_slug(article_id)}' in html and 'Un extrait pour la newsletter' in html
    assert re.search(r'<img src="' + re.escape(SITE_URL) + r'/uploads/[a-f0-9-]+\.png"', html)
    assert f'{SITE_URL}/newsletter/desinscription?token=jeton-abonne-0123456789abcdef' in html and 'Se désinscrire' in html
    assert mail[0]['headers']['List-Unsubscribe-Post'] == 'List-Unsubscribe=One-Click'
    assert not wait_for_mail(lambda m: 'brouillon Zeta' in m['subject'], timeout=1)  # un brouillon n'est pas annoncé
    resave_article(admin, article_id, 'Annonce Newsletter Zeta', 'published')  # simple modification
    resave_article(admin, article_id, 'Annonce Newsletter Zeta', 'draft')  # dépublication…
    resave_article(admin, article_id, 'Annonce Newsletter Zeta', 'published')  # …puis republication
    time.sleep(1)
    assert len([m for m in read_mails() if m['subject'] == 'Annonce Newsletter Zeta']) == 2  # une seule fois par article et par abonné
    assert query('SELECT COUNT(*) AS n FROM mail_queue WHERE article_id=%s', (article_id,))[0]['n'] == 2
    resave_article(admin, draft, 'Annonce brouillon Zeta', 'published')  # brouillon publié : annoncé à son tour
    assert wait_for_mail(lambda m: m['subject'] == 'Annonce brouillon Zeta')
    admin_page = admin.get('/admin/newsletter').text
    assert 'abonne@exemple.org' in admin_page and 'Envoyer la file maintenant' in admin_page
    queue = Client(server_url).post('/_ops/mail-queue', [], headers={'X-Deploy-Token': DEPLOY_TOKEN})
    assert queue.status == 200 and json.loads(queue.text)['status'] == 'ok'


def test_desinscription_de_la_newsletter(visitor):
    token = 'jeton-abonne-0123456789abcdef'
    page = visitor.get(f'/newsletter/desinscription?token={token}')
    assert page.status == 200 and 'abonne@exemple.org' in page.text and 'Confirmer la désinscription' in page.text
    assert query('SELECT status FROM subscribers WHERE token=%s', (token,))[0]['status'] == 'active'  # le lien seul ne désinscrit pas
    done = visitor.post(f'/newsletter/desinscription?token={token}', [])
    assert done.status == 200 and 'bien été désinscrite' in done.text
    assert query('SELECT status FROM subscribers WHERE token=%s', (token,))[0]['status'] == 'unsubscribed'
    one_click = visitor.post('/newsletter/desinscription?token=jeton-attente-0123456789abcdef', [('List-Unsubscribe', 'One-Click')])
    assert one_click.status == 200  # désinscription en un clic depuis la messagerie (RFC 8058)
    assert visitor.get('/newsletter/desinscription?token=inconnu-0123456789abcdefgh').status == 404



# --- Lot 5 : commentaires --------------------------------------------------------------------------------------------

def post_comment(client, slug: str, **fields):
    token = re.search(r'name="_csrf" value="([^"]+)"', client.get(f'/article/{slug}').text).group(1)
    data = [('_csrf', fields.pop('_csrf', token))] + list(fields.items())
    return client.post(f'/article/{slug}/comment', data)


def test_commentaires_messages_antispam_et_moderation(admin, visitor):
    query('DELETE FROM rate_events')
    slug = article_slug(create_article(admin, 'Article à commenter Zeta'))
    page = visitor.get(f'/article/{slug}').text
    assert '<main class="article-layout">' in page and 'name="website"' in page  # champ piège présent
    invalid = post_comment(visitor, slug, author='', email='pas-une-adresse', body='Mon avis détaillé')
    assert invalid.status == 422 and 'Le commentaire n’a pas été envoyé.' in invalid.text
    assert 'Indiquez votre nom.' in invalid.text and 'L’adresse e-mail n’est pas valide.' in invalid.text
    assert '>Mon avis détaillé</textarea>' in invalid.text  # le texte saisi est conservé
    expired = post_comment(visitor, slug, _csrf='jeton-perime', author='Lectrice', body='Texte conservé')
    assert expired.status == 403 and 'Votre session a expiré' in expired.text and '>Texte conservé</textarea>' in expired.text
    trap = post_comment(visitor, slug, author='Robot', body='Achetez maintenant', website='https://spam.example')
    assert trap.status == 303 and not query('SELECT 1 FROM comments WHERE author=%s', ('Robot',))
    for number in range(3):
        assert post_comment(visitor, slug, author=f'Lectrice {number}', email='lectrice@exemple.org', body=f'Commentaire <b>{number}</b>').status == 303
    limited = post_comment(visitor, slug, author='Lectrice 4', body='Un de trop')
    assert limited.status == 429 and 'plusieurs commentaires en peu de temps' in limited.text
    assert len(query("SELECT 1 FROM comments c JOIN articles a ON a.id=c.article_id WHERE a.slug=%s AND c.status='pending'", (slug,))) == 3
    success = visitor.get(f'/article/{slug}?sent=1')
    assert 'Votre commentaire a été envoyé et sera publié après modération.' in success.text
    assert 'Commentaire &lt;b&gt;0&lt;/b&gt;' not in success.text  # en attente de modération : pas encore affiché
    comment_id = query("SELECT c.id FROM comments c JOIN articles a ON a.id=c.article_id WHERE a.slug=%s AND c.author='Lectrice 0'", (slug,))[0]['id']
    admin.post(f'/admin/comments/{comment_id}', [('_csrf', admin.csrf('/admin/comments')), ('action', 'approve')])
    approved = visitor.get(f'/article/{slug}').text
    assert 'Commentaires (1)' in approved and 'Commentaire &lt;b&gt;0&lt;/b&gt;' in approved  # affiché, échappé
    query('DELETE FROM rate_events')


# --- Retours du client : pays, archives, recherche par auteur, thème, pays et date ---------------------------------

def test_pays_archives_et_filtres_des_notes(admin, visitor):
    niger = create_article(admin, 'Paysfiltre Sahel', countries='Niger, france')
    create_article(admin, 'Paysfiltre Paris', countries='France')
    old = create_article(admin, 'Paysfiltre ancien', countries='niger')
    query("UPDATE articles SET author='Abdoulahi ATTAYOUB', published_at='2019-05-02T10:00:00+00:00' WHERE id=%s", (old,))
    assert query('SELECT country FROM article_countries WHERE article_id=%s ORDER BY country', (niger,)) == [{'country': 'France'}, {'country': 'Niger'}]
    assert query('SELECT country FROM article_countries WHERE article_id=%s', (old,)) == [{'country': 'Niger'}]  # orthographe existante reprise
    by_country = visitor.get('/analyses?pays=Niger&q=Paysfiltre').text
    assert 'Paysfiltre Sahel' in by_country and 'Paysfiltre ancien' in by_country and 'Paysfiltre Paris' not in by_country
    assert 'France, Niger' in by_country  # pays affichés sous le titre
    assert 'Paysfiltre ancien' in visitor.get('/analyses?annee=2019&q=Paysfiltre').text
    assert 'Paysfiltre Sahel' not in visitor.get('/analyses?annee=2019&q=Paysfiltre').text
    by_author = visitor.get('/analyses?auteur=Abdoulahi+ATTAYOUB').text
    assert 'Paysfiltre ancien' in by_author and 'Paysfiltre Sahel' not in by_author
    home = visitor.get('/').text
    assert 'href="/analyses?pays=Niger"' in home  # menu « Régions » : pays des articles publiés
    assert '<summary class="is-active">Régions</summary>' in visitor.get('/analyses?pays=Niger').text

    # Rangement groupé dans les archives : l'article quitte l'accueil et la sélection, mais reste trouvable.
    token = admin.csrf('/admin/articles')
    response = admin.post('/admin/articles/bulk', [('_csrf', token), ('action', 'archive'), ('ids[]', old), ('ids[]', niger), ('back', '/admin/articles?q=Paysfiltre')])
    assert response.location == '/admin/articles?q=Paysfiltre&ranges=2'
    assert '2 article(s) mis à jour.' in admin.get(response.location).text
    assert 'Paysfiltre Sahel' not in visitor.get('/').text
    selection = visitor.get('/analyses?q=Paysfiltre').text
    assert 'Paysfiltre Paris' in selection and 'Paysfiltre Sahel' not in selection
    assert re.search(r'Archives</span> <small>2</small>', selection)  # l'onglet annonce les résultats rangés
    archives = visitor.get('/analyses?q=Paysfiltre&archives=1').text
    assert 'Paysfiltre Sahel' in archives and 'Paysfiltre ancien' in archives and 'Paysfiltre Paris' not in archives
    assert visitor.get(f'/article/{article_slug(old)}').status == 200  # un article archivé reste en ligne
    assert 'Paysfiltre ancien' in admin.get('/admin/articles?rangement=archives&q=Paysfiltre').text
    admin.post('/admin/articles/bulk', [('_csrf', token), ('action', 'unarchive'), ('ids[]', niger)])
    assert 'Paysfiltre Sahel' in visitor.get('/analyses?q=Paysfiltre').text
    edit = admin.get(f'/admin/articles/{old}/edit').text
    assert 'name="archived" value="1" checked' in edit and 'value="Niger"' in edit


# --- Lot 6 : utilisateurs, rôles, listes de l'espace adhérent ---------------------------------------------------------

def login(server_url: str, username: str, password: str) -> Client:
    client = Client(server_url)
    response = client.post('/admin/login', [('_csrf', client.csrf('/admin/login')), ('username', username), ('password', password)])
    assert response.location == '/admin', response.location
    return client


def save_user(admin, fields: list[tuple[str, str]]):
    return admin.post('/admin/users/save', [('_csrf', admin.csrf('/admin/users/new')), *fields])


def user_id(username: str) -> int:
    return query('SELECT id FROM users WHERE username=%s', (username,))[0]['id']


def test_gestion_des_utilisateurs_et_des_roles(admin, server_url):
    assert query('SELECT role FROM users WHERE username=%s', (ADMIN[0],))[0]['role'] == 'admin'  # compte existant : administrateur
    created = save_user(admin, [('username', 'autrice'), ('display_name', 'Awa Autrice'), ('email', 'awa@exemple.org'),
                                ('role', 'author'), ('password', 'autrice-mot-de-passe')])
    assert created.status == 200 and 'a été créé (Auteur)' in created.text
    assert save_user(admin, [('username', 'adherent'), ('role', 'member'), ('password', 'adherent-mot-de-passe')]).status == 200
    assert 'déjà utilisé' in save_user(admin, [('username', 'AUTRICE'), ('role', 'member'), ('password', 'x' * 14)]).text
    assert 'au moins 12 caractères' in save_user(admin, [('username', 'court'), ('role', 'member'), ('password', 'court')]).text
    assert not query("SELECT 1 FROM users WHERE username='court'")
    listing = admin.get('/admin/users?q=awa').text
    assert 'Awa Autrice' in listing and 'adherent' not in listing
    assert 'Awa Autrice' not in admin.get('/admin/users?role=member').text

    # Auteur : publie directement, ne voit et ne modifie que ses propres articles.
    author = login(server_url, 'autrice', 'autrice-mot-de-passe')
    for path in ('/admin/users', '/admin/categories', '/admin/newsletter', '/admin/comments', '/admin/experts'):
        assert author.get(path).status == 403, path
    admin_article = create_article(admin, 'Article de l’administrateur')
    own = create_article(author, 'Article de l’autrice')
    assert query('SELECT owner_id, status FROM articles WHERE id=%s', (own,))[0] == {'owner_id': user_id('autrice'), 'status': 'published'}
    board = author.get('/admin').text
    assert 'Mes articles' in board and 'Article de l’autrice' in board and 'Article de l’administrateur' not in board
    assert 'href="/admin/users"' not in board and 'href="/admin/categories"' not in board  # menu limité au rôle
    assert author.get(f'/admin/articles/{admin_article}/edit').status == 403
    assert author.post(f'/admin/articles/{admin_article}/delete', [('_csrf', author.csrf('/admin'))]).status == 403
    assert query('SELECT 1 FROM articles WHERE id=%s', (admin_article,))
    assert author.get(f'/admin/articles/{own}/edit').status == 200
    assert admin.get(f'/admin/articles/{own}/edit').status == 200  # l'administrateur garde tous les droits
    assert author.post('/admin/users/save', [('_csrf', author.csrf('/admin')), ('username', 'intrus'), ('role', 'admin'),
                                             ('password', 'x' * 14)]).status == 403
    assert not query("SELECT 1 FROM users WHERE username='intrus'")

    # Adhérent : tableau de bord, profil et messagerie seulement.
    member = login(server_url, 'adherent', 'adherent-mot-de-passe')
    home = member.get('/admin').text
    assert 'Espace adhérent' in home and 'href="/admin/messages"' in home and 'href="/admin/articles"' not in home
    for path in ('/admin/articles', '/admin/articles/new', '/admin/users', '/admin/events'):
        assert member.get(path).status == 403, path
    messages = member.get('/admin/messages')
    assert messages.status == 200 and 'Bientôt disponible' in messages.text
    profile_token = member.csrf('/admin/profile')
    wrong = member.post('/admin/profile', [('_csrf', profile_token), ('display_name', 'Ada Adhérente'), ('email', ''),
                                           ('current_password', 'faux'), ('new_password', 'nouveau-mot-de-passe'), ('confirm_password', 'nouveau-mot-de-passe')])
    assert 'Le mot de passe actuel est incorrect.' in wrong.text
    changed = member.post('/admin/profile', [('_csrf', profile_token), ('display_name', 'Ada Adhérente'), ('email', 'ada@exemple.org'),
                                             ('current_password', 'adherent-mot-de-passe'), ('new_password', 'nouveau-mot-de-passe'),
                                             ('confirm_password', 'nouveau-mot-de-passe')])
    assert 'Profil et mot de passe mis à jour.' in changed.text and 'Ada Adhérente' in changed.text

    # Désactivation : la session ouverte est coupée et la connexion refusée, jusqu'à la réactivation.
    member_id = user_id('adherent')
    disabled = admin.post(f'/admin/users/{member_id}/toggle', [('_csrf', admin.csrf('/admin/users'))])
    assert 'a été désactivé' in disabled.text
    assert member.get('/admin').location == '/admin/login'
    retry = Client(server_url)
    refused = retry.post('/admin/login', [('_csrf', retry.csrf('/admin/login')), ('username', 'adherent'), ('password', 'nouveau-mot-de-passe')])
    assert refused.location.endswith('error=disabled') and 'désactivé' in retry.get(refused.location).text
    admin.post(f'/admin/users/{member_id}/toggle', [('_csrf', admin.csrf('/admin/users'))])
    login(server_url, 'adherent', 'nouveau-mot-de-passe')

    # L'administrateur ne peut ni se désactiver ni se retirer son rôle.
    me = user_id(ADMIN[0])
    assert 'propre compte' in admin.post(f'/admin/users/{me}/toggle', [('_csrf', admin.csrf('/admin/users'))]).text
    save_user(admin, [('id', str(me)), ('username', ADMIN[0]), ('role', 'member'), ('password', '')])
    assert query('SELECT role, active FROM users WHERE id=%s', (me,))[0] == {'role': 'admin', 'active': 1}
    edited = save_user(admin, [('id', str(user_id('autrice'))), ('username', 'autrice'), ('display_name', 'Awa A.'), ('role', 'member'), ('password', '')])
    assert 'mis à jour' in edited.text and query("SELECT role FROM users WHERE username='autrice'")[0]['role'] == 'member'
    assert author.get('/admin/articles').status == 403  # nouveau rôle appliqué immédiatement


def test_recherche_et_pagination_des_listes(admin):
    for number in range(23):
        query('INSERT INTO tags (name, slug, created_at) VALUES (%s, %s, %s) ON CONFLICT DO NOTHING', (f'Thème {number:02d}', f'theme-{number:02d}', ''))
    first = admin.get('/admin/tags?q=Th%C3%A8me').text
    assert first.count('Thème ') == 20 and 'rel="next"' in first and 'page=2' in first
    second = admin.get('/admin/tags?q=Th%C3%A8me&page=2').text
    assert second.count('Thème ') == 3 and 'Thème 22' in second
    assert 'Thème 07' in admin.get('/admin/tags?q=07').text and 'Thème 08' not in admin.get('/admin/tags?q=07').text
    create_article(admin, 'Recherche alpha introuvable ailleurs', status='draft')
    found = admin.get('/admin/articles?q=alpha+introuvable').text
    assert 'Recherche alpha introuvable ailleurs' in found and 'Article de l’autrice' not in found
    assert 'Recherche alpha' not in admin.get('/admin/articles?q=alpha+introuvable&etat=published').text
    assert 'Aucun commentaire ne correspond' in admin.get('/admin/comments?q=zzzz-introuvable').text
    assert 'Aucun contenu ne correspond' in admin.get('/admin/events?q=zzzz-introuvable').text
    assert 'Aucune fiche ne correspond' in admin.get('/admin/invites?q=zzzz-introuvable').text
    assert admin.get('/admin/newsletter?q=zzzz').status == 200
    query("DELETE FROM tags WHERE slug LIKE 'theme-%%'")



def test_operations_de_deploiement(server_url):
    client = Client(server_url)
    assert client.post('/_ops/migrate', []).status == 403
    assert client.post('/_ops/migrate', [], headers={'X-Deploy-Token': 'mauvais'}).status == 403
    migrated = client.post('/_ops/migrate', [], headers={'X-Deploy-Token': DEPLOY_TOKEN})
    assert migrated.status == 200 and json.loads(migrated.text)['applied'] == []
    backup = client.post('/_ops/backup?label=test', [], headers={'X-Deploy-Token': DEPLOY_TOKEN})
    assert backup.status == 200 and json.loads(backup.text)['file'].endswith('-test.sql.gz')


def test_sauvegarde_restaurable_dans_une_base_vide(server_url):
    """Transfert vers un autre hébergeur : la sauvegarde, rejouée dans une base vide, redonne le même site."""
    if os.environ.get('APP_URL'):
        pytest.skip('le fichier de sauvegarde est sur le serveur testé')
    client = Client(server_url)
    backup = client.post('/_ops/backup?label=transfert', [], headers={'X-Deploy-Token': DEPLOY_TOKEN})
    assert backup.status == 200
    sql = gzip.decompress((ROOT / 'backups' / json.loads(backup.text)['file']).read_bytes()).decode('utf-8')
    counted = ['users', 'articles', 'categories', 'tags', 'article_categories', 'translations', 'news_events', 'comments']
    before = {table: query(f'SELECT COUNT(*) AS n FROM {table}')[0]['n'] for table in counted}
    titles = query('SELECT title FROM articles ORDER BY id')
    reset_database()
    with psycopg.connect(DATABASE_URL, autocommit=True) as connection:
        connection.execute(sql)
    assert {table: query(f'SELECT COUNT(*) AS n FROM {table}')[0]['n'] for table in counted} == before
    assert query('SELECT title FROM articles ORDER BY id') == titles
    assert '"status": "ok"' in client.get('/healthz').text
    migrated = client.post('/_ops/migrate', [], headers={'X-Deploy-Token': DEPLOY_TOKEN})
    assert migrated.status == 200 and json.loads(migrated.text)['applied'] == []
    # Les compteurs repartent après les identifiants restaurés.
    created = query("INSERT INTO categories (name, slug, created_at) VALUES ('Après transfert', 'apres-transfert', '') RETURNING id")
    assert created[0]['id'] > max(row['id'] for row in query("SELECT id FROM categories WHERE slug <> 'apres-transfert'"))


def test_limitation_des_tentatives_de_connexion(server_url):
    """En dernier : bloque l'adresse de test pendant 15 minutes."""
    client = Client(server_url)
    for _ in range(8):
        token = client.csrf('/admin/login')
        client.post('/admin/login', [('_csrf', token), ('username', ADMIN[0]), ('password', 'faux-faux-faux')])
    token = client.csrf('/admin/login')
    assert client.post('/admin/login', [('_csrf', token), ('username', ADMIN[0]), ('password', ADMIN[1])]).status == 429
    query('DELETE FROM login_attempts')
