"""Point d'entrée WSGI pour l'hébergement LWS cPanel (outil « Setup Python App », Phusion Passenger).

Passenger ne lance pas `server.py` comme un serveur autonome : il appelle la fonction `application`
ci-dessous pour chaque requête. Elle transmet la requête au gestionnaire existant (`AppHandler`)
sans modifier son fonctionnement, puis renvoie sa réponse à Passenger.

Réglages cPanel : fichier de démarrage `passenger_wsgi.py`, point d'entrée `application`.
"""
from __future__ import annotations

import io
import os
import sys
import threading
import traceback
from email.parser import BytesParser
from email import policy
from urllib.parse import quote

ROOT = os.path.dirname(os.path.abspath(__file__))
if ROOT not in sys.path:
    sys.path.insert(0, ROOT)
os.chdir(ROOT)

import envfile  # noqa: E402
from pathlib import Path  # noqa: E402

envfile.load(Path(ROOT))  # DATABASE_URL et autres réglages : shared/app.env sur le serveur

import server  # noqa: E402

_ready = False
_ready_lock = threading.Lock()


def _prepare() -> None:
    """Crée les tables au premier appel, puis prépare les traductions en arrière-plan."""
    global _ready
    if _ready:
        return
    with _ready_lock:
        if _ready:
            return
        server.initialize()
        server.pretranslate_in_background()
        _ready = True


class _FakeServer:
    server_name = 'passenger'
    server_port = 0


def _request_headers(environ: dict):
    lines = []
    for key, value in environ.items():
        if key.startswith('HTTP_'):
            name = key[5:].replace('_', '-').title()
            lines.append(f'{name}: {value}')
    if environ.get('CONTENT_TYPE'):
        lines.append(f'Content-Type: {environ["CONTENT_TYPE"]}')
    if environ.get('CONTENT_LENGTH'):
        lines.append(f'Content-Length: {environ["CONTENT_LENGTH"]}')
    raw = ('\r\n'.join(lines) + '\r\n\r\n').encode('latin-1', errors='replace')
    return BytesParser(policy=policy.compat32).parsebytes(raw, headersonly=True)


def _client_ip(environ: dict) -> str:
    forwarded = environ.get('HTTP_X_FORWARDED_FOR', '')
    if forwarded:
        return forwarded.split(',')[0].strip()
    return environ.get('REMOTE_ADDR', '0.0.0.0')


def application(environ: dict, start_response):
    try:
        _prepare()
    except Exception:
        traceback.print_exc(file=sys.stderr)
        start_response('503 Service Unavailable', [('Content-Type', 'text/plain; charset=utf-8')])
        return ['Le site est momentanément indisponible (base de données injoignable).'.encode('utf-8')]

    try:
        length = int(environ.get('CONTENT_LENGTH') or 0)
    except ValueError:
        length = 0
    body = environ['wsgi.input'].read(length) if length > 0 else b''

    # Adresse demandée telle qu'envoyée par le navigateur (encore encodée), comme pour server.py en local.
    target = environ.get('REQUEST_URI', '')
    if target:
        path, _, query = target.partition('?')
        prefix = environ.get('SCRIPT_NAME', '').rstrip('/')
        if prefix and path.startswith(prefix):
            path = path[len(prefix):] or '/'
    else:
        path = environ.get('PATH_INFO', '/') or '/'
        # PATH_INFO arrive décodé en latin-1 selon la norme WSGI : on rétablit les caractères UTF-8.
        try:
            path = path.encode('latin-1').decode('utf-8')
        except (UnicodeEncodeError, UnicodeDecodeError):
            pass
        path = quote(path, safe="/:@!$&'()*+,;=-._~")
        query = environ.get('QUERY_STRING', '')

    handler = server.AppHandler.__new__(server.AppHandler)
    handler.rfile = io.BytesIO(body)
    handler.wfile = io.BytesIO()
    handler.client_address = (_client_ip(environ), 0)
    handler.server = _FakeServer()
    handler.command = environ.get('REQUEST_METHOD', 'GET').upper()
    handler.path = path + ('?' + query if query else '')
    handler.request_version = 'HTTP/1.1'
    handler.requestline = f'{handler.command} {handler.path} HTTP/1.1'
    handler.close_connection = True
    handler.headers = _request_headers(environ)

    method = getattr(handler, 'do_' + handler.command, None)
    if method is None and handler.command == 'HEAD':
        method = getattr(handler, 'do_GET', None)
    try:
        if method is None:
            handler.send_error(405)
        else:
            method()
        if hasattr(handler, '_headers_buffer') and handler._headers_buffer:
            handler.end_headers()
    except Exception:
        traceback.print_exc(file=sys.stderr)
        start_response('500 Internal Server Error', [('Content-Type', 'text/plain; charset=utf-8')])
        return ['Erreur interne du serveur.'.encode('utf-8')]

    raw = handler.wfile.getvalue()
    head, _, payload = raw.partition(b'\r\n\r\n')
    lines = head.decode('latin-1').split('\r\n')
    status_line = lines[0] if lines else 'HTTP/1.1 500 Internal Server Error'
    parts = status_line.split(' ', 1)
    status = parts[1] if len(parts) > 1 and parts[1] else '500 Internal Server Error'
    headers = []
    for line in lines[1:]:
        if ':' not in line:
            continue
        name, value = line.split(':', 1)
        # Date, Server et Connection sont gérés par Apache/Passenger.
        if name.strip().lower() in {'date', 'server', 'connection', 'transfer-encoding'}:
            continue
        headers.append((name.strip(), value.strip()))
    start_response(status, headers)
    if handler.command == 'HEAD':
        return [b'']
    return [payload]
