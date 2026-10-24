"""Outils communs aux tests : base vide, client HTTP qui passe par passenger_wsgi (comme en production).

Les tests utilisent la base indiquée par DATABASE_URL (postgresql://… ou mysql://…).
ATTENTION : toutes les tables de cette base sont supprimées. Utiliser une base dédiée aux tests.
"""
from __future__ import annotations

import io
import os
import re
import shutil
import sys
import tempfile
from http.cookies import SimpleCookie
from pathlib import Path
from urllib.parse import urlencode

import pytest

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

if not os.environ.get('DATABASE_URL'):
    pytest.exit('Indiquez une base de test dans DATABASE_URL (elle sera vidée).', returncode=2)

import translation  # noqa: E402


class FakeTranslator:
    """Remplace le service de traduction : aucun appel réseau pendant les tests."""
    name, max_chars, max_items = 'test', 4000, 50

    def translate(self, texts, target):
        return [f'[{target}] {text}' for text in texts]


translation.PROVIDER = FakeTranslator()

import server  # noqa: E402

TABLES = ['schema_version', 'sessions', 'translations', 'news_events', 'comments', 'article_tags',
          'article_categories', 'articles', 'tags', 'categories', 'templates', 'languages', 'users']


def reset_database() -> None:
    with server.connect() as db:
        if server.DB_KIND == 'mysql':
            db.execute('SET FOREIGN_KEY_CHECKS=0')
            for table in TABLES:
                db.execute(f'DROP TABLE IF EXISTS {table}')
            db.execute('SET FOREIGN_KEY_CHECKS=1')
        else:
            for table in TABLES:
                db.execute(f'DROP TABLE IF EXISTS {table} CASCADE')


class Client:
    def __init__(self, application) -> None:
        self.application = application
        self.cookies: dict[str, str] = {}

    def request(self, method: str, target: str, data=None, files=None):
        body, content_type = b'', ''
        if files:
            boundary = 'TESTBOUNDARY'
            parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k, v in (data or [])]
            for name, (filename, mime, content) in files.items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + content + b'\r\n')
            body = b''.join(parts) + f'--{boundary}--\r\n'.encode()
            content_type = f'multipart/form-data; boundary={boundary}'
        elif data is not None:
            body, content_type = urlencode(data).encode(), 'application/x-www-form-urlencoded'
        path, _, query = target.partition('?')
        environ = {
            'REQUEST_METHOD': method, 'REQUEST_URI': target, 'PATH_INFO': path, 'QUERY_STRING': query, 'SCRIPT_NAME': '',
            'REMOTE_ADDR': '203.0.113.7', 'wsgi.input': io.BytesIO(body), 'CONTENT_LENGTH': str(len(body)),
            'CONTENT_TYPE': content_type, 'HTTP_HOST': 'test.local',
        }
        if self.cookies:
            environ['HTTP_COOKIE'] = '; '.join(f'{k}={v}' for k, v in self.cookies.items())
        captured = {}

        def start_response(status, headers):
            captured['status'], captured['headers'] = status, headers

        payload = b''.join(self.application(environ, start_response))
        headers = {}
        for name, value in captured['headers']:
            headers.setdefault(name.lower(), value)
            if name.lower() == 'set-cookie':
                cookie = SimpleCookie()
                cookie.load(value)
                for key, morsel in cookie.items():
                    if morsel.value:
                        self.cookies[key] = morsel.value
                    else:
                        self.cookies.pop(key, None)
        return Response(int(captured['status'].split()[0]), headers, payload.decode('utf-8', 'replace'))

    def get(self, target: str):
        return self.request('GET', target)

    def post(self, target: str, data, files=None):
        return self.request('POST', target, data, files)

    def csrf(self, target: str) -> str:
        match = re.search(r'name="_csrf" value="([^"]+)"', self.get(target).text)
        assert match, f'jeton de sécurité absent de {target}'
        return match.group(1)


class Response:
    def __init__(self, status: int, headers: dict, text: str) -> None:
        self.status, self.headers, self.text = status, headers, text

    @property
    def location(self) -> str:
        return self.headers.get('location', '')


ADMIN = ('admin', 'motdepasse-solide-123')


@pytest.fixture(scope='session')
def app():
    uploads = Path(tempfile.mkdtemp(prefix='uploads-'))
    server.UPLOAD_DIR = uploads
    reset_database()
    import passenger_wsgi
    passenger_wsgi._ready = False
    yield passenger_wsgi.application
    shutil.rmtree(uploads, ignore_errors=True)


@pytest.fixture(scope='session')
def admin(app):
    """Client connecté à l'administration (le compte est créé au premier appel)."""
    client = Client(app)
    token = client.csrf('/admin/setup')
    client.post('/admin/setup', [('_csrf', token), ('username', ADMIN[0]), ('password', ADMIN[1]), ('confirm', ADMIN[1])])
    return client


@pytest.fixture()
def visitor(app):
    return Client(app)
