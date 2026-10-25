"""Tests de bout en bout : un vrai serveur PHP (php -S) est lancé, les tests lui envoient des requêtes HTTP.

Variables :
  DATABASE_URL  base PostgreSQL de TEST (obligatoire) : toutes ses tables sont supprimées au début ;
  APP_URL       facultatif : adresse d'un serveur déjà lancé (sinon les tests lancent « php -S »).
"""
from __future__ import annotations

import http.client
import os
import re
import shutil
import socket
import subprocess
import tempfile
import time
from http.cookies import SimpleCookie
from pathlib import Path
from urllib.parse import urlencode, urlparse

import psycopg
import pytest
from psycopg.rows import dict_row

ROOT = Path(__file__).resolve().parent.parent
DATABASE_URL = os.environ.get('DATABASE_URL', '')
DEPLOY_TOKEN = os.environ.get('TEST_DEPLOY_TOKEN', 'jeton-de-test-0123456789abcdef')
ADMIN = ('admin', 'motdepasse-solide-123')
# Les e-mails de la newsletter sont écrits dans ce fichier (MAIL_TRANSPORT=log) au lieu d'être envoyés.
MAIL_LOG = Path(tempfile.gettempdir()) / f'idialogos-mails-{os.getpid()}.jsonl'
SITE_URL = 'https://www.exemple-idialogos.org'

if not DATABASE_URL.startswith(('postgres://', 'postgresql://')):
    pytest.exit('Indiquez une base PostgreSQL de test dans DATABASE_URL (elle sera vidée).', returncode=2)

TABLES = ['schema_version', 'mail_queue', 'article_mailings', 'subscribers', 'rate_events', 'people', 'login_attempts', 'sessions', 'translations', 'news_events', 'comments', 'article_tags',
          'article_categories', 'articles', 'tags', 'categories', 'templates', 'languages', 'users']


def query(sql: str, params: tuple = ()) -> list[dict]:
    with psycopg.connect(DATABASE_URL, row_factory=dict_row) as connection:
        cursor = connection.execute(sql, params)
        return cursor.fetchall() if cursor.description else []


def reset_database() -> None:
    with psycopg.connect(DATABASE_URL) as connection:
        for table in TABLES:
            connection.execute(f'DROP TABLE IF EXISTS {table} CASCADE')


class Response:
    def __init__(self, status: int, headers: dict, text: str) -> None:
        self.status, self.headers, self.text = status, headers, text

    @property
    def location(self) -> str:
        return self.headers.get('location', '')


class Client:
    """Client HTTP minimal : garde les cookies, ne suit pas les redirections."""

    def __init__(self, base_url: str) -> None:
        self.base = urlparse(base_url)
        self.cookies: dict[str, str] = {}

    def request(self, method: str, target: str, data=None, files=None, headers: dict | None = None) -> Response:
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
        sent = {'Host': self.base.netloc, **(headers or {})}
        if content_type:
            sent['Content-Type'] = content_type
        if self.cookies:
            sent['Cookie'] = '; '.join(f'{k}={v}' for k, v in self.cookies.items())
        connection = http.client.HTTPConnection(self.base.hostname, self.base.port, timeout=60)
        connection.request(method, target, body=body if method != 'GET' else None, headers=sent)
        response = connection.getresponse()
        payload = response.read()
        result = {}
        for name, value in response.getheaders():
            result.setdefault(name.lower(), value)
            if name.lower() == 'set-cookie':
                cookie = SimpleCookie()
                cookie.load(value)
                for key, morsel in cookie.items():
                    if morsel.value:
                        self.cookies[key] = morsel.value
                    else:
                        self.cookies.pop(key, None)
        connection.close()
        return Response(response.status, result, payload.decode('utf-8', 'replace'))

    def get(self, target: str) -> Response:
        return self.request('GET', target)

    def post(self, target: str, data, files=None, headers=None) -> Response:
        return self.request('POST', target, data, files, headers)

    def csrf(self, target: str) -> str:
        match = re.search(r'name="_csrf" value="([^"]+)"', self.get(target).text)
        assert match, f'jeton de sécurité absent de {target}'
        return match.group(1)


def free_port() -> int:
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


@pytest.fixture(scope='session')
def server_url():
    reset_database()
    MAIL_LOG.unlink(missing_ok=True)
    if os.environ.get('APP_URL'):
        url = os.environ['APP_URL'].rstrip('/')
        assert Client(url).get('/healthz').status == 200, 'le site testé ne répond pas'
        yield url
        return
    uploads = Path(tempfile.mkdtemp(prefix='uploads-'))
    tmp = Path(tempfile.mkdtemp(prefix='phptmp-'))
    port = free_port()
    env = {**os.environ, 'DATABASE_URL': DATABASE_URL, 'TRANSLATION_PROVIDER': 'fake', 'UPLOAD_DIR': str(uploads),
           'DEPLOY_TOKEN': DEPLOY_TOKEN, 'APP_DEBUG': '1', 'DONATION_URL': 'https://exemple.org/don',
           'MAIL_TRANSPORT': 'log', 'MAIL_LOG_FILE': str(MAIL_LOG), 'SITE_URL': SITE_URL, 'TMPDIR': str(tmp), 'APP_ENV_FILE': os.devnull}
    log = open(tmp / 'server.log', 'w')
    process = subprocess.Popen(['php', '-d', 'upload_max_filesize=12M', '-d', 'post_max_size=13M', '-d', f'sys_temp_dir={tmp}',
                                '-S', f'127.0.0.1:{port}', 'dev-router.php'], cwd=ROOT, env=env, stdout=log, stderr=subprocess.STDOUT)
    url = f'http://127.0.0.1:{port}'
    for _ in range(50):
        try:
            if Client(url).get('/healthz').status in (200, 503):
                break
        except OSError:
            time.sleep(0.1)
    try:
        yield url
    finally:
        process.terminate()
        process.wait(5)
        log.close()
        shutil.rmtree(uploads, ignore_errors=True)
        shutil.rmtree(tmp, ignore_errors=True)


@pytest.fixture(scope='session')
def admin(server_url):
    """Client connecté à l'administration (le compte est créé au premier appel)."""
    client = Client(server_url)
    token = client.csrf('/admin/setup')
    client.post('/admin/setup', [('_csrf', token), ('username', ADMIN[0]), ('password', ADMIN[1]), ('confirm', ADMIN[1])])
    assert client.get('/admin').status == 200, 'connexion administrateur impossible'
    return client


@pytest.fixture()
def visitor(server_url):
    return Client(server_url)
