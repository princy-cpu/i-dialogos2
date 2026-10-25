"""Parcours complets du site, par de vraies requêtes HTTP vers l'application PHP."""
from __future__ import annotations

import gzip
import json
import os
import re
import time

import psycopg
import pytest
from conftest import ADMIN, DATABASE_URL, DEPLOY_TOKEN, ROOT, Client, query, reset_database

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
              ('language', 'fr'), ('status', status), (field, categories[category]), ('new_tags', tags), ('comments_enabled', '1')]
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
    return re.findall(r'class="public-card-image" href="/article/([^"]+)"', html)


def test_accueil_quatre_analyses_et_actualites(admin, visitor):
    for number in range(6):
        create_article(admin, f'Accueil limité {number}')
        create_event(admin, f'Actualité accueil {number}')
    page = visitor.get('/').text
    assert page.count('class="analysis-item"') == 4
    assert len(re.findall(r'<article class="event-entry"', page)) == 4
    assert 'data-carousel' in page and 'class="clamp-3"' in page
    assert 'href="/analyses"' in page and 'href="/actualites"' in page
    assert 'id="public-feed"' not in page  # le bloc « Les Notes » est remplacé par un lien vers /analyses


def test_pagination_des_analyses(admin, visitor):
    for number in range(12):
        article_id = create_article(admin, f'Paginationzeta numéro {number:02d}')
        query('UPDATE articles SET published_at=%s WHERE id=%s', (f'2020-01-{number + 1:02d}T10:00:00+00:00', article_id))
    first = visitor.get('/analyses?q=Paginationzeta')
    assert first.status == 200 and len(card_slugs(first.text)) == 10
    assert '<nav class="pagination" aria-label="Pagination">' in first.text and 'aria-current="page"' in first.text
    assert 'href="/analyses?q=Paginationzeta&amp;page=2"' in first.text  # le filtre est conservé dans la pagination
    assert 'numéro 11' in first.text and 'numéro 01' not in first.text  # du plus récent au plus ancien
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
    related = card_slugs(page.split('id="related-title"', 1)[1])
    assert 3 <= len(related) <= 4
    assert article_slug(main) not in related and article_slug(draft) not in related  # jamais l'article courant ni un brouillon
    assert related[0] == article_slug(close)  # celui qui partage le plus de thèmes et de mots-clés
    assert f'rel="next" href="/article/{article_slug(close)}"' in page and 'rel="prev"' in page



# --- Lot 1 : navigation et en-tête ------------------------------------------------------------------------------------

def test_en_tete_logo_don_et_lien_actif(visitor):
    home = visitor.get('/').text
    assert '<a class="logo" href="/"' in home  # le logo ramène en haut de l'accueil
    assert 'class="donate-button" href="https://exemple.org/don" target="_blank" rel="noopener"' in home
    notes = visitor.get('/analyses').text
    assert '<a href="/analyses" class="is-active" aria-current="page">Les Notes</a>' in notes
    assert 'class="donate-button"' in notes  # même en-tête sur toutes les pages publiques
    theme = visitor.get('/thematique/jeunesse').text
    assert '<summary class="is-active">Thématiques</summary>' in theme
    assert 'href="/thematique/jeunesse" aria-current="page"' in theme
    assert 'aria-current="page"' not in visitor.get('/actualites').text.split('</header>', 1)[0].split('<nav aria-label="Navigation principale">', 1)[1]


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
