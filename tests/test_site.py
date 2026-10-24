"""Parcours complets du site, exécutés à travers passenger_wsgi.application."""
from __future__ import annotations

import json
import re
import time

import server
from conftest import ADMIN, Client

PNG = b'\x89PNG\r\n\x1a\n' + b'0' * 64


def create_article(admin, title: str, status: str = 'published', **extra) -> str:
    token = admin.csrf('/admin/articles/new')
    categories = re.findall(r'name="categories" value="(\d+)"', admin.get('/admin/articles/new').text)
    fields = [('_csrf', token), ('title', title), ('excerpt', extra.get('excerpt', 'Chapô éàü, 50 %')),
              ('body_html', extra.get('body', '<p>Texte <strong>riche</strong> 😀</p>')), ('author', 'Jean-Claude Mairal'),
              ('language', 'fr'), ('status', status), ('categories', categories[4]), ('new_tags', 'Paix, #Afrique'),
              ('comments_enabled', '1')]
    response = admin.post('/admin/articles/save', fields, files={'featured_image': ('photo.png', 'image/png', PNG)})
    assert response.status == 303, response.text[:300]
    with server.connect() as db:
        return db.execute('SELECT id FROM articles WHERE title=?', (title,)).fetchone()['id']


def article_slug(article_id: str) -> str:
    with server.connect() as db:
        return db.execute('SELECT slug FROM articles WHERE id=?', (article_id,)).fetchone()['slug']


def test_healthz(app):
    response = Client(app).get('/healthz')
    assert response.status == 200
    report = json.loads(response.text)
    assert report['status'] == 'ok'
    assert report['schema'] == max(version for version, _, _ in server.MIGRATIONS)
    assert response.headers['cache-control'] == 'no-store'


def test_premier_demarrage_redirige_vers_la_configuration(app):
    assert Client(app).get('/admin/login').location in ('', '/admin/setup')


def test_connexion_administrateur(admin, app):
    assert admin.get('/admin').status == 200
    other = Client(app)
    token = other.csrf('/admin/login')
    bad = other.post('/admin/login', [('_csrf', token), ('username', ADMIN[0]), ('password', 'mauvais-mot-de-passe')])
    assert bad.location.endswith('error=1')
    token = other.csrf('/admin/login')
    good = other.post('/admin/login', [('_csrf', token), ('username', ADMIN[0]), ('password', ADMIN[1])])
    assert good.location == '/admin'


def test_formulaire_refuse_sans_jeton(app):
    response = Client(app).post('/admin/login', [('username', ADMIN[0]), ('password', ADMIN[1])])
    assert response.status == 403


def test_article_publie_visible(admin, visitor):
    article_id = create_article(admin, 'Démocratie & 100% dialogue ? Oui')
    slug = article_slug(article_id)
    assert f'/article/{slug}' in visitor.get('/').text
    page = visitor.get(f'/article/{slug}')
    assert page.status == 200 and 'riche' in page.text and 'Paix' in page.text
    image = re.search(r'/uploads/[a-f0-9-]+\.png', page.text)
    assert image and visitor.get(image.group(0)).status == 200
    assert 'Démocratie' in visitor.get('/thematique/democraties').text


def test_brouillon_invisible(admin, visitor):
    article_id = create_article(admin, 'Brouillon secret', status='draft')
    assert visitor.get(f'/article/{article_slug(article_id)}').status in (404, 200)
    assert 'Brouillon secret' not in visitor.get('/').text


def test_modification_article(admin):
    article_id = create_article(admin, 'Titre à modifier')
    token = admin.csrf(f'/admin/articles/{article_id}/edit')
    fields = [('_csrf', token), ('id', article_id), ('title', 'Titre modifié'), ('excerpt', 'x'), ('body_html', '<p>y</p>'),
              ('author', 'Auteur'), ('language', 'fr'), ('status', 'published')]
    assert admin.post('/admin/articles/save', fields).status == 303
    with server.connect() as db:
        assert db.execute('SELECT title FROM articles WHERE id=?', (article_id,)).fetchone()['title'] == 'Titre modifié'


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
                                                 ('body', '<p>Programme</p>'), ('location', 'Dakar'), ('starts_at', '2026-11-20'),
                                                 ('ends_at', ''), ('status', 'published'), ('language', 'auto'), ('image_url', '')])
    assert response.status == 303
    listing = visitor.get('/actualites').text
    assert 'Colloque' in listing
    slug = re.search(r'/actualite/([a-z0-9-]+)', listing).group(1)
    assert visitor.get(f'/actualite/{slug}').status == 200


def test_taxonomies_et_modeles(admin):
    admin.post('/admin/categories', [('_csrf', admin.csrf('/admin/categories')), ('name', 'Économie')])
    assert 'Économie' in admin.get('/admin/categories').text
    admin.post('/admin/templates', [('_csrf', admin.csrf('/admin/templates')), ('action', 'create'), ('name', 'Tribune'), ('body_html', '<p>x</p>')])
    assert 'Tribune' in admin.get('/admin/templates').text
    assert admin.get('/admin/tags').status == 200
    assert admin.get('/admin/languages').status == 200


def test_traduction_mise_en_cache(admin, visitor):
    slug = article_slug(create_article(admin, 'Article à traduire', body='<p>Bonjour le monde</p>'))
    time.sleep(1)
    assert '[en]' in visitor.get(f'/article/{slug}?lang=en').text
    with server.connect() as db:
        cached = db.execute("SELECT COUNT(*) AS total FROM translations WHERE target='en'").fetchone()['total']
    assert cached > 0
    assert '[en]' in visitor.get(f'/article/{slug}?lang=en').text


def test_suppression_article_en_cascade(admin):
    article_id = create_article(admin, 'Article à supprimer')
    admin.post(f'/admin/articles/{article_id}/delete', [('_csrf', admin.csrf('/admin/articles'))])
    with server.connect() as db:
        assert db.execute('SELECT 1 FROM articles WHERE id=?', (article_id,)).fetchone() is None
        assert db.execute('SELECT 1 FROM article_categories WHERE article_id=?', (article_id,)).fetchone() is None


def test_fichiers_statiques_et_erreurs(visitor):
    assert visitor.get('/style.css').status == 200
    assert visitor.get('/api/languages').status == 200
    assert visitor.request('HEAD', '/').status == 200
    missing = visitor.get('/page-inexistante')
    assert missing.status == 404 or 'introuvable' in missing.text
    assert visitor.get('/uploads/../server.py').status == 404


def test_migrations_idempotentes(app):
    assert server.apply_migrations() == []
    with server.connect() as db:
        assert server.schema_version(db) == max(version for version, _, _ in server.MIGRATIONS)
