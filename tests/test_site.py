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


def create_article(admin, title: str, status: str = 'published', **extra) -> str:
    form = admin.get('/admin/articles/new').text
    token = re.search(r'name="_csrf" value="([^"]+)"', form).group(1)
    field, categories = category_field(form)
    fields = [('_csrf', token), ('title', title), ('excerpt', extra.get('excerpt', 'Chapô éàü, 50 %')),
              ('body_html', extra.get('body', '<p>Texte <strong>riche</strong> 😀</p>')), ('author', 'Jean-Claude Mairal'),
              ('language', 'fr'), ('status', status), (field, categories[4]), ('new_tags', 'Paix, #Afrique'), ('comments_enabled', '1')]
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
