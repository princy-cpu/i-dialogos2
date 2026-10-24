from __future__ import annotations

import hashlib
import html
import json
import mimetypes
import os
import re
import secrets
import threading
import time
import unicodedata
import uuid
from datetime import datetime, timezone
from email import policy
from email.parser import BytesParser
from html.parser import HTMLParser
from http import HTTPStatus
from http.cookies import SimpleCookie
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, quote, unquote, urlparse

from translation import detect_language, html_to_text, notice_texts, provider_name, translate_records


ROOT = Path(__file__).resolve().parent
DATABASE_URL = os.environ.get('DATABASE_URL', '').strip()
# Moteur choisi d'après l'adresse : postgresql://… (poste local) ou mysql://… (hébergement LWS cPanel).
DB_KIND = 'mysql' if DATABASE_URL.lower().startswith(('mysql://', 'mysql+pymysql://', 'mariadb://')) else 'postgresql'
if DB_KIND == 'mysql':
    import pymysql
    import pymysql.cursors
    DBIntegrityError: type[Exception] = pymysql.err.IntegrityError
else:
    import psycopg
    from psycopg.rows import dict_row
    DBIntegrityError = psycopg.IntegrityError
# En production (HTTPS), le cookie de session n'est envoyé que sur une connexion chiffrée.
COOKIE_SECURE = '; Secure' if os.environ.get('COOKIE_SECURE', '').strip() == '1' else ''
UPLOAD_DIR = ROOT / 'uploads'
# Version déployée (identifiant du commit), écrite dans le fichier VERSION par le script de déploiement.
APP_VERSION = (ROOT / 'VERSION').read_text(encoding='utf-8').strip() if (ROOT / 'VERSION').exists() else 'dev'
MAX_REQUEST = 12 * 1024 * 1024
COOKIE_NAME = 'idialogos_session'
SESSION_TTL = 12 * 60 * 60
LOGIN_ATTEMPTS: dict[str, list[float]] = {}
# Icône et polices communes aux pages générées (la page d'accueil les déclare dans index.html).
FAVICON = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%231f6b3c'/%3E%3Ctext x='32' y='43' font-family='Arial' font-weight='700' font-size='28' text-anchor='middle' fill='white'%3EI%C2%B7D%3C/text%3E%3C/svg%3E"
HEAD_ASSETS = f'<link rel="icon" href="{FAVICON}"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&display=swap" rel="stylesheet">'


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec='seconds')


class Database:
    """Connexion PostgreSQL. Les requêtes utilisent `?` comme marqueur de paramètre."""

    def __init__(self, connection) -> None:
        self.connection = connection

    def __enter__(self) -> 'Database':
        self.connection.__enter__()
        return self

    def __exit__(self, *args: object) -> object:
        return self.connection.__exit__(*args)

    def execute(self, query: str, params: tuple = ()):
        ignore_conflict = 'INSERT OR IGNORE INTO' in query.upper()
        query = query.replace('INSERT OR IGNORE INTO', 'INSERT INTO').replace('?', '%s')
        if ignore_conflict:
            query += ' ON CONFLICT DO NOTHING'
        return self.connection.execute(query, params)


class MySQLResult:
    """Résultat d'une requête MySQL, avec les mêmes méthodes que le curseur psycopg."""

    def __init__(self, rows: list[dict], rowcount: int) -> None:
        self.rows, self.rowcount = rows, rowcount

    def fetchone(self) -> dict | None:
        return self.rows[0] if self.rows else None

    def fetchall(self) -> list[dict]:
        return list(self.rows)


_MYSQL_PLACEHOLDER = re.compile(r'=\s*ANY\(\?\)|\?')


class MySQLDatabase:
    """Connexion MySQL/MariaDB qui accepte les requêtes écrites pour PostgreSQL dans ce projet :
    `?`, `INSERT OR IGNORE`, `= ANY(?)` avec une liste et `RETURNING id`."""

    def __init__(self, connection) -> None:
        self.connection = connection

    def __enter__(self) -> 'MySQLDatabase':
        return self

    def __exit__(self, exc_type, *args: object) -> bool:
        try:
            if exc_type is None:
                self.connection.commit()
            else:
                self.connection.rollback()
        finally:
            self.connection.close()
        return False

    def execute(self, query: str, params: tuple | list = ()):
        query = query.strip()
        returning = re.search(r'\s+RETURNING\s+id\s*$', query, re.IGNORECASE)
        if returning:
            query = query[:returning.start()]
        query = re.sub(r'INSERT\s+OR\s+IGNORE\s+INTO', 'INSERT IGNORE INTO', query, flags=re.IGNORECASE)
        query = query.replace('%', '%%')
        values = iter(params)
        args: list = []

        def placeholder(match: re.Match) -> str:
            value = next(values)
            if match.group(0) == '?':
                args.append(value)
                return '%s'
            items = list(value)
            if not items:
                return 'IN (NULL)'
            args.extend(items)
            return 'IN (' + ','.join(['%s'] * len(items)) + ')'

        query = _MYSQL_PLACEHOLDER.sub(placeholder, query)
        with self.connection.cursor() as cursor:
            cursor.execute(query, tuple(args))
            if returning:
                return MySQLResult([{'id': cursor.lastrowid}], cursor.rowcount)
            rows = list(cursor.fetchall()) if cursor.description else []
            return MySQLResult(rows, cursor.rowcount)


def mysql_settings(url: str) -> dict:
    parsed = urlparse(url)
    return {
        'host': parsed.hostname or 'localhost', 'port': parsed.port or 3306,
        'user': unquote(parsed.username or ''), 'password': unquote(parsed.password or ''),
        'database': unquote(parsed.path.lstrip('/')), 'charset': 'utf8mb4',
    }


def connect() -> Database | MySQLDatabase:
    if not DATABASE_URL:
        raise RuntimeError('Configurez DATABASE_URL avec l’adresse de votre base PostgreSQL ou MySQL.')
    if DB_KIND == 'mysql':
        connection = pymysql.connect(**mysql_settings(DATABASE_URL), cursorclass=pymysql.cursors.DictCursor, autocommit=False)
        return MySQLDatabase(connection)
    return Database(psycopg.connect(DATABASE_URL, row_factory=dict_row))


MYSQL_TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
# Identifiants, jetons et adresses : comparaison exacte (sensible à la casse et aux accents), comme sous PostgreSQL.
MYSQL_KEY = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'
MYSQL_SCHEMA = f'''
  CREATE TABLE IF NOT EXISTS users (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL, password_salt VARCHAR(255) NOT NULL, created_at VARCHAR(40) NOT NULL
  ) {MYSQL_TABLE_OPTIONS};
  CREATE TABLE IF NOT EXISTS languages (
    code VARCHAR(16) {MYSQL_KEY} PRIMARY KEY, label VARCHAR(100) NOT NULL, enabled INT NOT NULL DEFAULT 1,
    is_default INT NOT NULL DEFAULT 0
  ) {MYSQL_TABLE_OPTIONS};
  CREATE TABLE IF NOT EXISTS sessions (
    token_hash VARCHAR(128) {MYSQL_KEY} PRIMARY KEY, user_id BIGINT NULL,
    csrf VARCHAR(128) {MYSQL_KEY} NOT NULL, expires_at BIGINT NOT NULL,
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  ) {MYSQL_TABLE_OPTIONS};
  CREATE TABLE IF NOT EXISTS categories (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE,
    slug VARCHAR(190) {MYSQL_KEY} NOT NULL UNIQUE, created_at VARCHAR(40) NOT NULL
  ) {MYSQL_TABLE_OPTIONS};
  CREATE TABLE IF NOT EXISTS tags (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE,
    slug VARCHAR(190) {MYSQL_KEY} NOT NULL UNIQUE, created_at VARCHAR(40) NOT NULL
  ) {MYSQL_TABLE_OPTIONS};
  CREATE TABLE IF NOT EXISTS templates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, body_html MEDIUMTEXT NOT NULL,
    is_default INT NOT NULL DEFAULT 0, created_at VARCHAR(40) NOT NULL
  ) {MYSQL_TABLE_OPTIONS};
  CREATE TABLE IF NOT EXISTS articles (
    id VARCHAR(64) {MYSQL_KEY} PRIMARY KEY, title TEXT NOT NULL, slug VARCHAR(190) {MYSQL_KEY} NOT NULL UNIQUE,
    excerpt TEXT NOT NULL, body_html MEDIUMTEXT NOT NULL, featured_image VARCHAR(1000) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'draft', author VARCHAR(255) NOT NULL, template_id BIGINT NULL,
    comments_enabled INT NOT NULL DEFAULT 1, created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL,
    published_at VARCHAR(40) NULL, language VARCHAR(16) NOT NULL DEFAULT 'fr',
    source_url VARCHAR(1000) NOT NULL DEFAULT '',
    CONSTRAINT fk_articles_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL
  ) {MYSQL_TABLE_OPTIONS};
  CREATE TABLE IF NOT EXISTS article_categories (
    article_id VARCHAR(64) {MYSQL_KEY} NOT NULL, category_id BIGINT NOT NULL,
    PRIMARY KEY(article_id, category_id),
    CONSTRAINT fk_ac_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_ac_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
  ) {MYSQL_TABLE_OPTIONS};
  CREATE TABLE IF NOT EXISTS article_tags (
    article_id VARCHAR(64) {MYSQL_KEY} NOT NULL, tag_id BIGINT NOT NULL,
    PRIMARY KEY(article_id, tag_id),
    CONSTRAINT fk_at_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_at_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
  ) {MYSQL_TABLE_OPTIONS};
  CREATE TABLE IF NOT EXISTS comments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, article_id VARCHAR(64) {MYSQL_KEY} NOT NULL,
    author VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL DEFAULT '', body TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending', created_at VARCHAR(40) NOT NULL,
    CONSTRAINT fk_comments_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE
  ) {MYSQL_TABLE_OPTIONS};
  CREATE TABLE IF NOT EXISTS news_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(20) NOT NULL DEFAULT 'news', title TEXT NOT NULL,
    slug VARCHAR(190) {MYSQL_KEY} NOT NULL UNIQUE, summary TEXT NOT NULL, body_html MEDIUMTEXT NOT NULL,
    image_url VARCHAR(1000) NOT NULL DEFAULT '', location VARCHAR(500) NOT NULL DEFAULT '',
    starts_at VARCHAR(40) NOT NULL DEFAULT '', ends_at VARCHAR(40) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'draft', created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL,
    language VARCHAR(16) NOT NULL DEFAULT 'fr', source_url VARCHAR(1000) NOT NULL DEFAULT ''
  ) {MYSQL_TABLE_OPTIONS};
  CREATE TABLE IF NOT EXISTS translations (
    hash VARCHAR(64) {MYSQL_KEY} NOT NULL, target VARCHAR(16) {MYSQL_KEY} NOT NULL,
    translated MEDIUMTEXT NOT NULL, created_at VARCHAR(40) NOT NULL,
    PRIMARY KEY(hash, target)
  ) {MYSQL_TABLE_OPTIONS}
'''


# Migrations versionnées : chaque évolution de structure de la base est ajoutée ici, à la suite,
# avec un numéro croissant et sa requête pour chaque moteur. Elles sont appliquées une seule fois,
# dans l'ordre, au démarrage et lors de chaque déploiement (`python migrate.py`).
# Exemple :
#   (2, 'Ajoute le sous-titre des articles', {
#       'postgresql': ["ALTER TABLE articles ADD COLUMN IF NOT EXISTS subtitle TEXT NOT NULL DEFAULT ''"],
#       'mysql': ["ALTER TABLE articles ADD COLUMN subtitle VARCHAR(300) NOT NULL DEFAULT ''"],
#   }),
MIGRATIONS: list[tuple[int, str, dict[str, list[str]]]] = [
    (1, 'Structure initiale (tables créées par initialize)', {'postgresql': [], 'mysql': []}),
]


def schema_version(db) -> int:
    row = db.execute('SELECT MAX(version) AS version FROM schema_version').fetchone()
    return int(row['version'] or 0) if row else 0


def apply_migrations() -> list[int]:
    """Applique les migrations manquantes. Renvoie les numéros appliqués."""
    with connect() as db:
        db.execute('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER PRIMARY KEY, description VARCHAR(255) NOT NULL, applied_at VARCHAR(40) NOT NULL)')
        current = schema_version(db)
    applied = []
    for version, description, statements in sorted(MIGRATIONS):
        if version <= current:
            continue
        # Une connexion par migration : elle est enregistrée seulement si toutes ses requêtes réussissent.
        with connect() as db:
            for statement in statements.get(DB_KIND, []):
                db.execute(statement)
            db.execute('INSERT INTO schema_version(version,description,applied_at) VALUES(?,?,?)', (version, description, now_iso()))
        applied.append(version)
    return applied


def initialize() -> None:
    create_baseline()
    apply_migrations()


def create_baseline() -> None:
    UPLOAD_DIR.mkdir(exist_ok=True)
    if DB_KIND == 'mysql':
        with connect() as db:
            for statement in MYSQL_SCHEMA.split(';'):
                if statement.strip():
                    db.execute(statement)
            seed_defaults(db)
        return
    with connect() as db:
        schema = '''
          CREATE TABLE IF NOT EXISTS users (
            id BIGSERIAL PRIMARY KEY, username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL, password_salt TEXT NOT NULL, created_at TEXT NOT NULL
          );
          CREATE TABLE IF NOT EXISTS languages (
            code TEXT PRIMARY KEY, label TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1,
            is_default INTEGER NOT NULL DEFAULT 0
          );
          CREATE TABLE IF NOT EXISTS sessions (
            token_hash TEXT PRIMARY KEY, user_id BIGINT REFERENCES users(id) ON DELETE CASCADE,
            csrf TEXT NOT NULL, expires_at INTEGER NOT NULL
          );
          CREATE TABLE IF NOT EXISTS categories (
            id BIGSERIAL PRIMARY KEY, name TEXT UNIQUE NOT NULL, slug TEXT UNIQUE NOT NULL, created_at TEXT NOT NULL
          );
          CREATE TABLE IF NOT EXISTS tags (
            id BIGSERIAL PRIMARY KEY, name TEXT UNIQUE NOT NULL, slug TEXT UNIQUE NOT NULL, created_at TEXT NOT NULL
          );
          CREATE TABLE IF NOT EXISTS templates (
            id BIGSERIAL PRIMARY KEY, name TEXT UNIQUE NOT NULL, body_html TEXT NOT NULL DEFAULT '',
            is_default INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL
          );
          CREATE TABLE IF NOT EXISTS articles (
            id TEXT PRIMARY KEY, title TEXT NOT NULL, slug TEXT UNIQUE NOT NULL, excerpt TEXT NOT NULL DEFAULT '',
            body_html TEXT NOT NULL DEFAULT '', featured_image TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'draft',
            author TEXT NOT NULL, template_id BIGINT REFERENCES templates(id) ON DELETE SET NULL,
            comments_enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
            published_at TEXT, language TEXT NOT NULL DEFAULT 'fr'
          );
          CREATE TABLE IF NOT EXISTS article_categories (
            article_id TEXT NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
            category_id BIGINT NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
            PRIMARY KEY(article_id, category_id)
          );
          CREATE TABLE IF NOT EXISTS article_tags (
            article_id TEXT NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
            tag_id BIGINT NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
            PRIMARY KEY(article_id, tag_id)
          );
          CREATE TABLE IF NOT EXISTS comments (
            id BIGSERIAL PRIMARY KEY, article_id TEXT NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
            author TEXT NOT NULL, email TEXT NOT NULL DEFAULT '', body TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending', created_at TEXT NOT NULL
          );
          CREATE TABLE IF NOT EXISTS news_events (
            id BIGSERIAL PRIMARY KEY, kind TEXT NOT NULL DEFAULT 'news', title TEXT NOT NULL,
            slug TEXT UNIQUE NOT NULL, summary TEXT NOT NULL DEFAULT '', body_html TEXT NOT NULL DEFAULT '',
            image_url TEXT NOT NULL DEFAULT '', location TEXT NOT NULL DEFAULT '',
            starts_at TEXT NOT NULL DEFAULT '', ends_at TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'draft', created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
            language TEXT NOT NULL DEFAULT 'fr'
          );
          CREATE TABLE IF NOT EXISTS translations (
            hash TEXT NOT NULL, target TEXT NOT NULL, translated TEXT NOT NULL, created_at TEXT NOT NULL,
            PRIMARY KEY(hash, target)
          );
        '''
        for statement in schema.split(';'):
            if statement.strip():
                db.execute(statement)
        db.execute("ALTER TABLE articles ADD COLUMN IF NOT EXISTS language TEXT NOT NULL DEFAULT 'fr'")
        db.execute("ALTER TABLE news_events ADD COLUMN IF NOT EXISTS language TEXT NOT NULL DEFAULT 'fr'")
        # Adresse d'origine des contenus importés de l'ancien site (évite les doublons à la réimportation).
        db.execute("ALTER TABLE articles ADD COLUMN IF NOT EXISTS source_url TEXT NOT NULL DEFAULT ''")
        db.execute("ALTER TABLE news_events ADD COLUMN IF NOT EXISTS source_url TEXT NOT NULL DEFAULT ''")
        seed_defaults(db)


def seed_defaults(db: Database | MySQLDatabase) -> None:
    """Langues, catégories et modèle d'article créés au premier démarrage."""
    for code, label, is_default in [('fr', 'Français', 1), ('en', 'English', 0), ('es', 'Español', 0), ('it', 'Italiano', 0)]:
        db.execute('INSERT OR IGNORE INTO languages(code,label,enabled,is_default) VALUES(?,?,1,?)', (code, label, is_default))
    defaults = [
        'IA – Intelligence artificielle', 'Jeunesse', 'Médias et pluralisme', 'Francophonies',
        'Démocraties', 'Ultramarins', 'Eau, climat et environnement', 'Géopolitique', 'Santé et développement'
    ]
    for name in defaults:
        db.execute('INSERT OR IGNORE INTO categories(name, slug, created_at) VALUES(?,?,?)', (name, slugify(name), now_iso()))
    if db.execute('SELECT COUNT(*) AS total FROM templates').fetchone()['total'] == 0:
        skeleton = '<h2>Introduction</h2><p>Présentez le sujet et la question principale.</p><h2>Développement</h2><p>Développez votre analyse et vos arguments.</p><h2>Conclusion</h2><p>Résumez les idées essentielles.</p>'
        db.execute('INSERT INTO templates(name, body_html, is_default, created_at) VALUES(?,?,1,?)', ('Modèle par défaut', skeleton, now_iso()))


def default_language(db: Database) -> str:
    row = db.execute('SELECT code FROM languages WHERE is_default=1 LIMIT 1').fetchone()
    return row['code'] if row else 'fr'


def resolve_language(db: Database, requested: str, *texts: str) -> str:
    """Langue choisie dans l'éditeur, ou détectée automatiquement à partir du contenu."""
    requested = requested.strip().lower()
    if requested != 'auto' and db.execute('SELECT 1 FROM languages WHERE code=?', (requested,)).fetchone():
        return requested
    return detect_language('\n'.join(texts)) or default_language(db)


# Thématiques, dans l'ordre et avec les textes des maquettes 7 à 13 (dossier « PAGES Site 13.10.26 »).
# Chaque thématique regroupe les articles publiés de la catégorie dont le slug est indiqué.
THEMES = [
    {'id': 'medias', 'title': 'Médias et pluralisme', 'question': '', 'text': 'Dans un Monde en pleine mutation et aux enjeux considérables pour l’avenir de l’Humanité, comment garantir une information « responsable », qui soit une information libre, indépendante, qualitative, humaine et respectueuse du pluralisme ?', 'text2': '', 'signature': '', 'button': 'Médias et pluralisme', 'image': 'media-pluralism.webp', 'category': 'medias-et-pluralisme'},
    {'id': 'francophonies', 'title': 'Francophonies', 'question': 'Quelle(s) Francophonie(s) pour demain ?', 'text': 'I-Dialogos entend contribuer, dans le cadre d’une approche transnationale, à une Francophonie renouvelée, moins institutionnelle et davantage ouverte aux sociétés civiles, aux territoires et aux jeunes générations. Une Francophonie plurielle, attentive à la diversité des cultures et des langues, favorisant la circulation des idées et un dialogue équilibré entre les peuples.', 'text2': '', 'signature': 'Jean-Claude Mairal', 'button': 'Francophonie', 'image': 'francophonie.webp', 'category': 'francophonies'},
    {'id': 'democraties', 'title': 'Démocraties', 'question': 'Quelles démocraties ?', 'text': 'À partir des expériences existantes, I-Dialogos explore, dans le cadre d’une approche transnationale croisée, les voies d’une démocratie à plusieurs niveaux, adaptée à chaque société.', 'text2': 'Dialogue et écoute sont essentiels pour en préserver l’esprit et prévenir les dérives autoritaires ou impérialistes.', 'signature': '', 'button': 'Quelles démocraties ?', 'image': 'democracies.webp', 'category': 'democraties'},
    {'id': 'ia', 'title': 'IA – Intelligence artificielle', 'question': 'Quelle intelligence artificielle, pour quelle société ?', 'text': 'Comment mettre l’intelligence artificielle au service de l’humain, sans renforcer les inégalités, les dépendances technologiques ni les manipulations de l’information ?', 'text2': 'I-Dialogos propose de croiser les regards sur ses usages et ses effets sur le travail, l’éducation, la culture et la démocratie, en interrogeant la place des langues, la diversité des savoirs et la responsabilité des acteurs.', 'signature': '', 'button': 'Intelligence artificielle', 'image': 'artificial-intelligence.webp', 'category': 'ia-intelligence-artificielle'},
    {'id': 'jeunesse', 'title': 'Jeunesse', 'question': 'Les jeunes, acteurs des mutations du monde', 'text': 'Comment permettre aux jeunes, dans la diversité de leurs cultures et de leurs parcours, de faire entendre leur voix et de participer aux décisions qui engagent leur avenir ?', 'text2': 'I-Dialogos souhaite ouvrir un espace de dialogue entre jeunes de différents pays et entre générations, autour de l’éducation, de l’emploi, de la culture, du numérique, de l’environnement et de la paix.', 'signature': '', 'button': 'Jeunesse', 'image': 'youth.webp', 'category': 'jeunesse'},
    {'id': 'ultramarins', 'title': 'Ultramarins', 'question': 'Territoires ultramarins : au cœur du dialogue géopolitique', 'text': 'Longtemps considérés comme périphériques, les territoires ultramarins occupent une place stratégique dans les équilibres géopolitiques, maritimes, environnementaux et culturels. À la rencontre de plusieurs mondes, ils constituent des espaces privilégiés de dialogue, de coopération régionale et de multilatéralisme.', 'text2': '', 'signature': '', 'button': 'Territoires ultramarins', 'image': 'ultramarins-map.webp', 'category': 'ultramarins'},
    {'id': 'eau', 'title': 'Eau, climat et environnement', 'question': 'L’eau, une réalité territoriale', 'text': 'Face au dérèglement climatique, sa gestion exige une gouvernance démocratique et partagée, associant États, territoires et sociétés civiles, au service de la coopération, de la paix et du développement durable.', 'text2': '', 'signature': 'Abdoulaye Sène', 'button': 'Eau, climat et environnement', 'image': 'water-environment.webp', 'category': 'eau-climat-et-environnement'},
    {'id': 'geopolitique', 'title': 'Géopolitique', 'question': 'Comprendre un monde en mutation', 'text': 'Dans un monde marqué par de profondes recompositions, I-Dialogos propose une lecture pluraliste des équilibres internationaux. En croisant les regards issus de différents continents, le think tank défend le dialogue, le multilatéralisme et le rôle des Nations unies dans la recherche de solutions pacifiques et partagées.', 'text2': '', 'signature': '', 'button': 'Géopolitique', 'image': 'geopolitics.webp', 'category': 'geopolitique'},
    {'id': 'sante', 'title': 'Santé et développement', 'question': 'Santé, territoires et coopérations', 'text': 'Une approche sociale, territoriale et internationale des politiques de santé, en lien avec la société civile et la démocratie.', 'text2': 'Une démarche comparative intégrant des conceptions adaptées du service public.', 'signature': '', 'button': 'Santé et développement', 'image': 'health.webp', 'category': 'sante-et-developpement'},
]
THEME_FIELDS = ('title', 'question', 'text', 'text2', 'button')
THEME_ALIASES = {'international': 'geopolitique'}  # anciennes adresses
# Sections fixes d'index.html traduites côté serveur (repères : commentaires HTML de la page).
STATIC_SECTIONS = [('<!-- 2 – Le réseau -->', '<!-- 7 à 13 – Thématiques -->'), ('<!-- 14 – Experts -->', '  </main>')]

MONTHS = {
    'fr': 'janvier février mars avril mai juin juillet août septembre octobre novembre décembre'.split(),
    'en': 'January February March April May June July August September October November December'.split(),
    'es': 'enero febrero marzo abril mayo junio julio agosto septiembre octubre noviembre diciembre'.split(),
    'it': 'gennaio febbraio marzo aprile maggio giugno luglio agosto settembre ottobre novembre dicembre'.split(),
}


def long_date(value: str, language: str) -> str:
    """« 2026-10-09… » → « 9 octobre 2026 » dans la langue d'affichage."""
    try:
        moment = datetime.fromisoformat(value[:10])
    except (TypeError, ValueError):
        return value[:10] if value else ''
    month = MONTHS.get(language, MONTHS['fr'])[moment.month - 1]
    if language == 'en':
        return f'{month} {moment.day}, {moment.year}'
    if language == 'es':
        return f'{moment.day} de {month} de {moment.year}'
    day = '1er' if language == 'fr' and moment.day == 1 else str(moment.day)
    return f'{day} {month} {moment.year}'


PRETRANSLATE_LOCK = threading.Lock()


def static_sections() -> list[str]:
    """Sections fixes d'index.html (en français) traduites côté serveur, entre leurs repères."""
    page = (ROOT / 'index.html').read_text(encoding='utf-8')
    sections = []
    for start, end in STATIC_SECTIONS:
        if start in page and end in page:
            sections.append(page.split(start, 1)[1].split(end, 1)[0])
    return sections


def pretranslate(article_ids: list[str] | None = None, event_ids: list[int] | None = None) -> None:
    """Prépare en arrière-plan les traductions des contenus publiés dans toutes les langues actives."""
    if not PRETRANSLATE_LOCK.acquire(blocking=False):
        return
    try:
        with connect() as db:
            targets = [row['code'] for row in db.execute('SELECT code FROM languages WHERE enabled=1').fetchall()]
            source = default_language(db)
            articles = db.execute("SELECT * FROM articles WHERE status='published'" + (' AND id = ANY(?)' if article_ids is not None else ''), (article_ids,) if article_ids is not None else ()).fetchall()
            events = db.execute("SELECT * FROM news_events WHERE status='published'" + (' AND id = ANY(?)' if event_ids is not None else ''), (event_ids,) if event_ids is not None else ()).fetchall()
            terms = [{**row, 'language': source} for row in db.execute('SELECT name FROM categories UNION SELECT name FROM tags').fetchall()]
            sections = [{'language': 'fr', 'html': html} for html in static_sections()] if article_ids is None and event_ids is None else []
            # 1) ce qu'affiche l'accueil (sections, thématiques, titres et chapôs), 2) les textes complets.
            for target in targets:
                translate_records(db, sections, target, (), ('html',))
                translate_records(db, [{**theme, 'language': 'fr'} for theme in THEMES], target, THEME_FIELDS)
                translate_records(db, terms, target, ('name',))
                translate_records(db, articles, target, ('title', 'excerpt'))
                translate_records(db, events, target, ('title', 'summary'))
                db.connection.commit()
            for target in targets:
                for start in range(0, len(articles), 10):
                    translate_records(db, articles[start:start + 10], target, (), ('body_html',))
                    db.connection.commit()
                translate_records(db, events, target, (), ('body_html',))
                db.connection.commit()
    except Exception as exc:
        print(f'Préparation des traductions interrompue : {exc}')
    finally:
        PRETRANSLATE_LOCK.release()


def pretranslate_in_background(article_ids: list[str] | None = None, event_ids: list[int] | None = None) -> None:
    threading.Thread(target=pretranslate, args=(article_ids, event_ids), daemon=True).start()


def content_lang(item: dict) -> str:
    """Langue dans laquelle le contenu est réellement affiché (pour l'attribut lang)."""
    return item.get('display_language') if item.get('translated_from') else item.get('language', '')


def slugify(value: str) -> str:
    normalized = unicodedata.normalize('NFKD', value).encode('ascii', 'ignore').decode('ascii').lower()
    result = re.sub(r'[^a-z0-9]+', '-', normalized).strip('-')
    return result[:90] or 'article'


def unique_slug(db: Database, title: str, article_id: str | None = None) -> str:
    root = slugify(title)
    candidate, number = root, 2
    while True:
        row = db.execute('SELECT id FROM articles WHERE slug=?', (candidate,)).fetchone()
        if not row or row['id'] == article_id:
            return candidate
        candidate, number = f'{root}-{number}', number + 1


def esc(value: object) -> str:
    return html.escape(str(value if value is not None else ''), quote=True)


def safe_rich_html(source: str) -> str:
    class Cleaner(HTMLParser):
        allowed = {'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'h2', 'h3', 'h4', 'blockquote', 'ul', 'ol', 'li', 'a', 'hr', 'div', 'span', 'font', 'img'}
        void = {'br', 'hr', 'img'}
        blocked = {'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form'}
        sizes = {'1': '10px', '2': '13px', '3': '16px', '4': '18px', '5': '24px', '6': '32px', '7': '48px'}

        def __init__(self) -> None:
            super().__init__(convert_charrefs=True)
            self.out: list[str] = []
            self.stack: list[str] = []
            self.block_depth = 0

        def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
            tag = tag.lower()
            if tag in self.blocked:
                self.block_depth += 1
                return
            if self.block_depth or tag not in self.allowed:
                return
            values = dict(attrs)
            rendered = tag
            safe_attrs = ''
            if tag == 'font':
                size = self.sizes.get(values.get('size', ''), '')
                rendered = 'span'
                styles = ['font-size:' + size] if size else []
                face = values.get('face', '')
                faces = {'Arial': 'Arial, sans-serif', 'Georgia': 'Georgia, serif', 'Times New Roman': 'Times New Roman, serif', 'Courier New': 'Courier New, monospace'}
                if face in faces:
                    styles.append('font-family:' + faces[face])
                color = values.get('color', '')
                if re.fullmatch(r'#[0-9a-fA-F]{3,8}', color):
                    styles.append('color:' + color)
                safe_attrs = f' style="{esc(";".join(styles))}"' if styles else ''
            elif tag == 'span':
                styles: list[str] = []
                style = values.get('style', '') or ''
                match = re.search(r'font-size\s*:\s*(\d{1,2}px|\d{1,2}(?:\.\d+)?(?:rem|em|%))', style, re.I)
                if match:
                    styles.append('font-size:' + match.group(1))
                family = re.search(r'font-family\s*:\s*(Arial|Georgia|Times New Roman|Courier New)', style, re.I)
                if family:
                    styles.append('font-family:' + family.group(1))
                color = re.search(r'color\s*:\s*(#[0-9a-f]{3,8}|(?:rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)))', style, re.I)
                if color:
                    styles.append('color:' + color.group(1))
                safe_attrs = f' style="{esc(";".join(styles))}"' if styles else ''
            elif tag in {'p', 'div', 'h2', 'h3', 'h4', 'blockquote'}:
                alignment = re.search(r'text-align\s*:\s*(left|center|right|justify)', values.get('style', '') or '', re.I)
                safe_attrs = f' style="text-align:{alignment.group(1).lower()}"' if alignment else ''
            elif tag == 'a':
                href = (values.get('href') or '').strip()
                if href.startswith(('https://', 'http://', 'mailto:', '/')):
                    safe_attrs = f' href="{esc(href)}" rel="noopener noreferrer"'
            elif tag == 'img':
                src = (values.get('src') or '').strip()
                if not (src.startswith('/uploads/') or src.startswith('https://')):
                    return
                safe_attrs = f' src="{esc(src)}" alt="{esc(values.get("alt") or "")}" loading="lazy"'
            self.out.append(f'<{rendered}{safe_attrs}>')
            if rendered not in self.void:
                self.stack.append(rendered)

        def handle_endtag(self, tag: str) -> None:
            tag = tag.lower()
            if tag in self.blocked and self.block_depth:
                self.block_depth -= 1
                return
            if self.block_depth:
                return
            if tag == 'font':
                tag = 'span'
            if tag not in self.stack:
                return
            while self.stack:
                current = self.stack.pop()
                self.out.append(f'</{current}>')
                if current == tag:
                    break

        def handle_data(self, data: str) -> None:
            if not self.block_depth:
                self.out.append(esc(data))

        def handle_entityref(self, name: str) -> None:
            if not self.block_depth:
                self.out.append(f'&amp;{esc(name)};')

        def finish(self) -> str:
            self.close()
            while self.stack:
                self.out.append(f'</{self.stack.pop()}>')
            return ''.join(self.out)

    cleaner = Cleaner()
    try:
        cleaner.feed(source[:500_000])
        return cleaner.finish()
    except Exception:
        return '<p>' + esc(re.sub(r'<[^>]*>', '', source[:20_000])) + '</p>'


class AppHandler(BaseHTTPRequestHandler):
    server_version = 'DialogosApp/1.0'

    def log_message(self, fmt: str, *args: object) -> None:
        print(f'{self.address_string()} - {fmt % args}')

    def healthz(self) -> None:
        """Contrôle de santé utilisé après chaque déploiement : base joignable et version en ligne."""
        report = {'status': 'ok', 'version': APP_VERSION, 'database': DB_KIND}
        try:
            with connect() as db:
                db.execute('SELECT 1').fetchone()
                report['schema'] = schema_version(db)
        except Exception as exc:  # noqa: BLE001 (toute erreur de base rend le site indisponible)
            report.update(status='error', error=type(exc).__name__)
        # Une base plus récente que le code est acceptée : c'est le cas juste après un retour arrière.
        status = 200 if report['status'] == 'ok' and report.get('schema', 0) >= max(v for v, _, _ in MIGRATIONS) else 503
        if status == 503 and report['status'] == 'ok':
            report.update(status='error', error='migrations en attente')
        self.send_body(json.dumps(report), status, 'application/json; charset=utf-8', [('Cache-Control', 'no-store')])

    def send_body(self, body: str | bytes, status: int = 200, content_type: str = 'text/html; charset=utf-8', headers: list[tuple[str, str]] | None = None) -> None:
        data = body.encode('utf-8') if isinstance(body, str) else body
        self.send_response(status)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(data)))
        self.send_header('X-Content-Type-Options', 'nosniff')
        self.send_header('X-Frame-Options', 'DENY')
        self.send_header('Referrer-Policy', 'same-origin')
        self.send_header('Content-Security-Policy', "default-src 'self'; img-src 'self' https: data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'")
        for key, value in headers or []:
            self.send_header(key, value)
        pending_cookie = getattr(self, '_new_cookie', None)
        if pending_cookie:
            self.send_header('Set-Cookie', pending_cookie)
            del self._new_cookie
        self.end_headers()
        self.wfile.write(data)

    def redirect(self, location: str, cookies: list[str] | None = None) -> None:
        self.send_response(HTTPStatus.SEE_OTHER)
        self.send_header('Location', location)
        self.send_header('Content-Length', '0')
        cookie_headers = list(cookies or [])
        pending_cookie = getattr(self, '_new_cookie', None)
        if pending_cookie and not cookie_headers:
            cookie_headers.append(pending_cookie)
        if pending_cookie and hasattr(self, '_new_cookie'):
            del self._new_cookie
        for cookie in cookie_headers:
            self.send_header('Set-Cookie', cookie)
        self.end_headers()

    def cookie_token(self) -> str:
        cookie = SimpleCookie()
        cookie.load(self.headers.get('Cookie', ''))
        morsel = cookie.get(COOKIE_NAME)
        return morsel.value if morsel else ''

    def selected_language(self, db: Database) -> str:
        """Langue demandée par ?lang=xx, sinon celle du cookie du sélecteur, sinon la langue par défaut."""
        cookie = SimpleCookie()
        cookie.load(self.headers.get('Cookie', ''))
        morsel = cookie.get('idialogos_lang')
        requested = parse_qs(urlparse(self.path).query).get('lang', [''])[0].strip().lower()
        for code in (requested, morsel.value if morsel else ''):
            if code and db.execute('SELECT code FROM languages WHERE code=? AND enabled=1', (code,)).fetchone():
                return code
        return default_language(db)

    def localize_terms(self, db: Database, rows: list[dict], target: str) -> list[dict]:
        """Traduit les noms de catégories ou d'étiquettes, saisis dans la langue par défaut du site."""
        source = default_language(db)
        return translate_records(db, [{**row, 'language': source} for row in rows], target, ('name',))

    def translation_notice(self, item: dict, target: str, href: str, showing_original: bool = False) -> str:
        source = item.get('language') or ''
        if showing_original:
            _, _, translation_link, _ = notice_texts(target, source)
            return f'<p class="translation-notice"><a href="{esc(href)}">{esc(translation_link)} →</a></p>'
        if item.get('translation_missing'):
            *_, unavailable = notice_texts(target, source)
            return f'<p class="translation-notice is-warning">{esc(unavailable)}</p>'
        if item.get('translated_from'):
            message, original_link, _, _ = notice_texts(target, source)
            separator = '&' if '?' in href else '?'
            return f'<p class="translation-notice">{esc(message)} <a href="{esc(href + separator)}original=1" lang="{esc(source)}">{esc(original_link)}</a></p>'
        return ''

    def language_selector(self) -> str:
        with connect() as db:
            languages = db.execute('SELECT code,label,is_default FROM languages WHERE enabled=1 ORDER BY is_default DESC,label').fetchall()
            selected = self.selected_language(db)
        options = ''.join(f'<option value="{esc(row["code"])}" {"selected" if row["code"] == selected else ""}>{esc(row["label"])}</option>' for row in languages)
        return f'<label class="language-picker"><span class="sr-only">Choisir la langue</span><select id="language-select" aria-label="Choisir la langue">{options}</select></label>'

    def session(self, create: bool = False) -> dict | None:
        token = self.cookie_token()
        token_hash = hashlib.sha256(token.encode()).hexdigest() if token else ''
        with connect() as db:
            row = db.execute('SELECT sessions.*, users.username FROM sessions LEFT JOIN users ON users.id=sessions.user_id WHERE token_hash=? AND expires_at>?', (token_hash, int(time.time()))).fetchone() if token_hash else None
            if row:
                return row
            if not create:
                return None
            token = secrets.token_urlsafe(32)
            csrf = secrets.token_urlsafe(32)
            db.execute('INSERT INTO sessions(token_hash,user_id,csrf,expires_at) VALUES(?,?,?,?)', (hashlib.sha256(token.encode()).hexdigest(), None, csrf, int(time.time()) + SESSION_TTL))
            self._new_cookie = f'{COOKIE_NAME}={token}; HttpOnly; SameSite=Lax; Path=/; Max-Age={SESSION_TTL}{COOKIE_SECURE}'
            return db.execute('SELECT sessions.*, NULL AS username FROM sessions WHERE token_hash=?', (hashlib.sha256(token.encode()).hexdigest(),)).fetchone()

    def auth(self) -> dict | None:
        session = self.session()
        return session if session and session['user_id'] and session['username'] else None

    def csrf_ok(self, form: dict[str, str]) -> bool:
        session = self.session()
        return bool(session and secrets.compare_digest(session['csrf'], form.get('_csrf', '')))

    def read_form(self) -> tuple['MultiFields', dict[str, tuple[str, str, bytes]]]:
        length = int(self.headers.get('Content-Length', '0'))
        if length < 0 or length > MAX_REQUEST:
            raise ValueError('La requête est trop volumineuse.')
        raw = self.rfile.read(length)
        content_type = self.headers.get('Content-Type', '')
        fields: dict[str, str] = {}
        multi: dict[str, list[str]] = {}
        files: dict[str, tuple[str, str, bytes]] = {}
        if content_type.startswith('multipart/form-data'):
            message = BytesParser(policy=policy.default).parsebytes(b'Content-Type: ' + content_type.encode() + b'\r\nMIME-Version: 1.0\r\n\r\n' + raw)
            for part in message.iter_parts():
                name = part.get_param('name', header='content-disposition')
                if not name:
                    continue
                filename = part.get_filename()
                payload = part.get_payload(decode=True) or b''
                if filename:
                    files[name] = (filename, part.get_content_type(), payload)
                else:
                    charset = part.get_content_charset() or 'utf-8'
                    value = payload.decode(charset, errors='replace')
                    fields[name] = value
                    multi.setdefault(name, []).append(value)
        else:
            for key, values in parse_qs(raw.decode('utf-8', errors='replace'), keep_blank_values=True).items():
                fields[key] = values[-1]
                multi[key] = values
        return MultiFields(fields, multi), files

    def do_GET(self) -> None:
        parsed = urlparse(self.path)
        path = unquote(parsed.path)
        if path == '/healthz':
            return self.healthz()
        if path == '/api/languages':
            with connect() as db:
                rows = db.execute('SELECT code,label,is_default FROM languages WHERE enabled=1 ORDER BY is_default DESC,label').fetchall()
            return self.send_body(json.dumps([dict(row) for row in rows], ensure_ascii=False), content_type='application/json; charset=utf-8')
        if path == '/':
            return self.public_home()
        if path.startswith('/assets/') or path in {'/style.css', '/app.js', '/admin.css', '/admin.js', '/i18n.js'}:
            return self.static_file(path)
        if path.startswith('/uploads/'):
            return self.upload_file(path)
        if path.startswith('/article/'):
            return self.public_article(path.removeprefix('/article/'), parse_qs(parsed.query))
        if path.startswith('/thematique/'):
            return self.public_theme(path.removeprefix('/thematique/'))
        if path == '/actualites':
            return self.public_news_events()
        if path.startswith('/actualite/'):
            return self.public_news_event(path.removeprefix('/actualite/'), parse_qs(parsed.query))
        if path == '/admin/setup':
            return self.setup_page()
        if path == '/admin/login':
            return self.login_page()
        if path == '/admin/logout':
            return self.redirect('/admin')
        if path == '/admin':
            return self.dashboard()
        if path == '/admin/articles':
            return self.dashboard('articles')
        if path == '/admin/events':
            return self.events_page()
        if path == '/admin/events/new':
            return self.event_form()
        if path.startswith('/admin/events/') and path.endswith('/edit'):
            return self.event_form(path.split('/')[-2])
        if path == '/admin/articles/new':
            return self.article_form()
        if path.startswith('/admin/articles/') and path.endswith('/edit'):
            return self.article_form(path.split('/')[-2])
        if path == '/admin/categories':
            return self.taxonomy_page('categories')
        if path == '/admin/tags':
            return self.taxonomy_page('tags')
        if path == '/admin/templates':
            return self.templates_page()
        if path == '/admin/comments':
            return self.comments_page()
        if path == '/admin/languages':
            return self.languages_page()
        if path.startswith('/admin'):
            return self.send_body(self.site_page('Page introuvable', '<main class="admin-shell"><h1>Page introuvable</h1><a href="/admin">Retour à l’administration</a></main>'), 404)
        return self.public_page('Page introuvable', '<main class="article-page"><h1>Page introuvable</h1><p>Cette page n’existe pas ou a été déplacée.</p><a class="theme-button" href="/">Retour à l’accueil</a></main>', 404)

    def do_POST(self) -> None:
        try:
            form, files = self.read_form()
        except ValueError as exc:
            return self.send_body(self.site_page('Erreur', f'<main class="admin-shell"><h1>{esc(exc)}</h1></main>'), 413)
        path = unquote(urlparse(self.path).path)
        if path in {'/admin/setup', '/admin/login'}:
            if not self.csrf_ok(form):
                return self.send_body(self.site_page('Session expirée', '<main class="admin-shell"><h1>Session expirée</h1><a href="/admin/login">Recommencer</a></main>'), 403)
            return self.setup_post(form) if path.endswith('setup') else self.login_post(form)
        if path.startswith('/article/') and path.endswith('/comment'):
            if not self.csrf_ok(form):
                return self.send_body(self.site_page('Session expirée', '<main class="admin-shell"><h1>Session expirée</h1><a href="/">Retour</a></main>'), 403)
            return self.comment_post(path.split('/')[-2], form)
        if not self.auth():
            return self.redirect('/admin/login')
        if not self.csrf_ok(form):
            return self.send_body(self.admin_page('Session expirée', '<main class="admin-main"><h1>Session expirée</h1><a href="/admin">Recommencer</a></main>'), 403)
        if path == '/admin/logout':
            return self.logout()
        if path == '/admin/articles/save':
            return self.save_article(form, files)
        if path == '/admin/events/save':
            return self.save_event(form)
        if path.startswith('/admin/events/') and path.endswith('/delete'):
            return self.delete_event(path.split('/')[-2])
        if path.startswith('/admin/articles/') and path.endswith('/delete'):
            return self.delete_article(path.split('/')[-2])
        if path == '/admin/categories':
            return self.taxonomy_post('categories', form)
        if path == '/admin/categories/delete':
            return self.taxonomy_post('categories/delete', form)
        if path == '/admin/tags':
            return self.taxonomy_post('tags', form)
        if path == '/admin/tags/delete':
            return self.taxonomy_post('tags/delete', form)
        if path == '/admin/templates':
            return self.templates_post(form)
        if path.startswith('/admin/comments/'):
            return self.comment_action(path.split('/')[-1], form)
        if path == '/admin/languages':
            return self.languages_post(form)
        return self.redirect('/admin')

    def static_file(self, path: str) -> None:
        candidate = (ROOT / path.lstrip('/')).resolve()
        base = ROOT / 'assets' if path.startswith('/assets/') else ROOT
        if base.resolve() not in candidate.parents or not candidate.is_file():
            return self.send_error(404)
        self.send_body(candidate.read_bytes(), content_type=mimetypes.guess_type(candidate.name)[0] or 'application/octet-stream')

    def upload_file(self, path: str) -> None:
        filename = Path(path).name
        if not re.fullmatch(r'[a-f0-9-]+\.(?:jpg|jpeg|png|gif|webp)', filename):
            return self.send_error(404)
        candidate = (UPLOAD_DIR / filename).resolve()
        if UPLOAD_DIR.resolve() not in candidate.parents or not candidate.is_file():
            return self.send_error(404)
        self.send_body(candidate.read_bytes(), content_type=mimetypes.guess_type(candidate.name)[0] or 'application/octet-stream', headers=[('Cache-Control', 'public, max-age=86400')])

    def site_page(self, title: str, content: str) -> str:
        return f'''<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{esc(title)} · I-Dialogos</title>{HEAD_ASSETS}<link rel="stylesheet" href="/style.css"><link rel="stylesheet" href="/admin.css"></head><body>{content}<script src="/admin.js" defer></script></body></html>'''

    def site_chrome(self, themes: list[dict]) -> tuple[str, str]:
        """En-tête et pied de page de l'accueil (index.html), réutilisés sur toutes les pages publiques."""
        page = (ROOT / 'index.html').read_text(encoding='utf-8')
        header = page[page.index('<div class="world-bar">'):page.index('</header>') + len('</header>')]
        footer = page[page.index('<footer'):page.index('</footer>') + len('</footer>')]
        header = header.replace('<div id="themes-menu"></div>', f'<div id="themes-menu">{self.theme_links(themes)}</div>')
        parts = []
        for part in (header, footer):
            # Les ancres de l'accueil (#apropos…) pointent vers la page d'accueil depuis les autres pages.
            part = part.replace('href="#atelier"', 'href="/admin"').replace('href="#', 'href="/#')
            parts.append(part.replace('href="/#accueil" class="back-top"', 'href="#accueil" class="back-top"'))
        return parts[0], parts[1]

    def public_page(self, title: str, content: str, status: int = 200) -> None:
        """Page publique complète : en-tête du site, contenu, pied de page et scripts de la vitrine."""
        with connect() as db:
            language = self.selected_language(db)
            themes = self.localized_themes(db, language)
        header, footer = self.site_chrome(themes)
        document = f'''<!doctype html><html lang="{esc(language)}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{esc(title)} · I-Dialogos</title>{HEAD_ASSETS}<link rel="stylesheet" href="/style.css"><link rel="stylesheet" href="/admin.css"></head><body class="public-page">{header}{content}{footer}<script src="/app.js" defer></script><script src="/i18n.js" defer></script></body></html>'''
        self.send_body(document, status)

    def admin_page(self, title: str, content: str, active: str = '') -> str:
        session = self.auth()
        user = esc(session['username']) if session else ''
        links = [('Accueil', '/admin', 'home'), ('Articles', '/admin/articles', 'articles'), ('Actualités et événements', '/admin/events', 'events'), ('Catégories', '/admin/categories', 'categories'), ('Étiquettes', '/admin/tags', 'tags'), ('Modèles', '/admin/templates', 'templates'), ('Commentaires', '/admin/comments', 'comments'), ('Langues', '/admin/languages', 'languages')]
        nav = ''.join(f'<a class="{"active" if key == active else ""}" href="{href}">{label}</a>' for label, href, key in links)
        logout = f'''<form method="post" action="/admin/logout">{self.csrf_field()}<button class="nav-logout">Déconnexion</button></form>'''
        shell = f'''<header class="admin-topbar"><a class="admin-brand" href="/">I<span>·</span>Dialogos <small>Administration</small></a><div><a href="/" class="view-site">Voir le site ↗</a><span class="admin-user">{user}</span>{logout}</div></header><div class="admin-layout"><aside class="admin-sidebar"><span class="sidebar-label">ESPACE ÉDITORIAL</span>{nav}</aside>{content}</div>'''
        return self.site_page(title, shell)

    def csrf_field(self) -> str:
        session = self.session(create=True)
        return f'<input type="hidden" name="_csrf" value="{esc(session["csrf"])}">'

    def setup_page(self) -> None:
        with connect() as db:
            exists = db.execute('SELECT 1 FROM users LIMIT 1').fetchone()
        if exists:
            return self.redirect('/admin/login')
        body = f'''<main class="auth-wrap"><a class="admin-brand" href="/">I<span>·</span>Dialogos</a><section class="auth-card"><span class="eyebrow">Première configuration</span><h1>Créer le compte administrateur</h1><p>Ce compte sera le seul à pouvoir administrer le site. Choisissez un mot de passe long et unique.</p><form method="post" action="/admin/setup">{self.csrf_field()}<label>Nom d’utilisateur<input name="username" required minlength="3" maxlength="60" autocomplete="username"></label><label>Mot de passe<input type="password" name="password" required minlength="12" autocomplete="new-password"><small>12 caractères minimum</small></label><label>Confirmer le mot de passe<input type="password" name="confirm" required minlength="12" autocomplete="new-password"></label><button class="primary-button">Créer le compte</button></form></section></main>'''
        self.send_body(self.site_page('Configuration administrateur', body))

    def setup_post(self, form: dict[str, str]) -> None:
        username, password = form.get('username', '').strip(), form.get('password', '')
        if len(username) < 3 or len(password) < 12 or password != form.get('confirm'):
            return self.send_body(self.site_page('Configuration', '<main class="auth-wrap"><div class="auth-card"><h1>Vérifiez vos informations</h1><p>Le nom doit contenir 3 caractères et le mot de passe au moins 12 caractères. Les deux mots de passe doivent correspondre.</p><a href="/admin/setup">Retour</a></div></main>'), 400)
        salt = secrets.token_bytes(16)
        digest = hashlib.pbkdf2_hmac('sha256', password.encode(), salt, 310_000)
        try:
            with connect() as db:
                db.execute('INSERT INTO users(username,password_hash,password_salt,created_at) VALUES(?,?,?,?)', (username, digest.hex(), salt.hex(), now_iso()))
                user_id = db.execute('SELECT id FROM users WHERE username=?', (username,)).fetchone()['id']
                self.create_authenticated_session(db, user_id)
        except DBIntegrityError:
            return self.send_body(self.site_page('Configuration', '<main class="auth-wrap"><div class="auth-card"><h1>Un compte existe déjà</h1><a href="/admin/login">Se connecter</a></div></main>'), 409)
        return self.redirect('/admin', [self._new_cookie])

    def create_authenticated_session(self, db: Database, user_id: int) -> None:
        token = secrets.token_urlsafe(32)
        csrf = secrets.token_urlsafe(32)
        db.execute('INSERT INTO sessions(token_hash,user_id,csrf,expires_at) VALUES(?,?,?,?)', (hashlib.sha256(token.encode()).hexdigest(), user_id, csrf, int(time.time()) + SESSION_TTL))
        self._new_cookie = f'{COOKIE_NAME}={token}; HttpOnly; SameSite=Lax; Path=/; Max-Age={SESSION_TTL}{COOKIE_SECURE}'

    def login_page(self) -> None:
        with connect() as db:
            exists = db.execute('SELECT 1 FROM users LIMIT 1').fetchone()
        if not exists:
            return self.redirect('/admin/setup')
        body = f'''<main class="auth-wrap"><a class="admin-brand" href="/">I<span>·</span>Dialogos</a><section class="auth-card"><span class="eyebrow">Administration</span><h1>Connexion</h1><p>Connectez-vous pour gérer les articles et les contenus du site.</p>{'<p class="form-error">Nom d’utilisateur ou mot de passe incorrect.</p>' if 'error=1' in self.path else ''}<form method="post" action="/admin/login">{self.csrf_field()}<label>Nom d’utilisateur<input name="username" required autocomplete="username"></label><label>Mot de passe<input type="password" name="password" required autocomplete="current-password"></label><button class="primary-button">Se connecter</button></form><a class="back-public" href="/">← Retour au site</a></section></main>'''
        self.send_body(self.site_page('Connexion administrateur', body))

    def login_post(self, form: dict[str, str]) -> None:
        ip = self.client_address[0]
        attempts = [stamp for stamp in LOGIN_ATTEMPTS.get(ip, []) if time.time() - stamp < 900]
        if len(attempts) >= 8:
            LOGIN_ATTEMPTS[ip] = attempts
            return self.send_body(self.site_page('Trop de tentatives', '<main class="auth-wrap"><section class="auth-card"><h1>Connexion temporairement bloquée</h1><p>Réessayez dans quelques minutes.</p></section></main>'), 429)
        with connect() as db:
            user = db.execute('SELECT * FROM users WHERE username=?', (form.get('username', '').strip(),)).fetchone()
            valid = False
            if user:
                actual = hashlib.pbkdf2_hmac('sha256', form.get('password', '').encode(), bytes.fromhex(user['password_salt']), 310_000).hex()
                valid = secrets.compare_digest(actual, user['password_hash'])
            if valid:
                LOGIN_ATTEMPTS.pop(ip, None)
                db.execute('DELETE FROM sessions WHERE expires_at<=?', (int(time.time()),))
                self.create_authenticated_session(db, user['id'])
                return self.redirect('/admin', [self._new_cookie])
        attempts.append(time.time())
        LOGIN_ATTEMPTS[ip] = attempts
        return self.redirect('/admin/login?error=1')

    def logout(self) -> None:
        token = self.cookie_token()
        if token:
            with connect() as db:
                db.execute('DELETE FROM sessions WHERE token_hash=?', (hashlib.sha256(token.encode()).hexdigest(),))
        return self.redirect('/', [f'{COOKIE_NAME}=; HttpOnly; SameSite=Lax; Path=/; Max-Age=0{COOKIE_SECURE}'])

    def public_home(self) -> None:
        with connect() as db:
            language = self.selected_language(db)
            articles = db.execute("SELECT * FROM articles WHERE status='published' ORDER BY published_at DESC, created_at DESC").fetchall()
            categories = self.localize_terms(db, db.execute('SELECT * FROM categories ORDER BY name').fetchall(), language)
            tags = self.localize_terms(db, db.execute('SELECT * FROM tags ORDER BY name').fetchall(), language)
            news_events = db.execute("SELECT * FROM news_events WHERE status='published' ORDER BY CASE WHEN starts_at='' THEN created_at ELSE starts_at END DESC LIMIT 3").fetchall()
            ids = [article['id'] for article in articles]
            category_links = db.execute('SELECT article_id,category_id FROM article_categories WHERE article_id = ANY(?)', (ids,)).fetchall()
            tag_links = db.execute('SELECT article_id,tag_id FROM article_tags WHERE article_id = ANY(?)', (ids,)).fetchall()
            articles = translate_records(db, articles, language, ('title', 'excerpt'))
            news_events = translate_records(db, news_events, language, ('title', 'summary'))
            themes = self.localized_themes(db, language)
        categories_by_id = {row['id']: row for row in categories}
        tags_by_id = {row['id']: row for row in tags}
        article_cats: dict[str, list[dict]] = {}
        article_tags: dict[str, list[dict]] = {}
        for link in category_links:
            article_cats.setdefault(link['article_id'], []).append(categories_by_id[link['category_id']])
        for link in tag_links:
            article_tags.setdefault(link['article_id'], []).append(tags_by_id[link['tag_id']])
        categories.sort(key=lambda row: row['name'].casefold())
        tags.sort(key=lambda row: row['name'].casefold())
        cards = ''.join(self.public_card(article, sorted(article_cats.get(article['id'], []), key=lambda row: row['name'].casefold()), sorted(article_tags.get(article['id'], []), key=lambda row: row['name'].casefold())) for article in articles)
        cards = cards or '<div class="empty-state" data-empty="articles"></div>'
        feed = f'<div class="public-article-grid" id="public-feed">{cards}</div>'
        theme_options = '<option value="">Tous les thèmes</option>' + ''.join(f'<option value="{esc(row["slug"])}">{esc(row["name"])}</option>' for row in categories)
        tag_options = '<option value="">Tous les tags</option>' + ''.join(f'<option value="{esc(row["slug"])}">{esc(row["name"])}</option>' for row in tags)
        page = (ROOT / 'index.html').read_text(encoding='utf-8')
        featured = self.public_featured_article(articles[0]) if articles else '<div class="public-empty-featured" data-empty="articles"></div>'
        page = page.replace('<div id="database-featured-article"></div>', featured)
        analysis_items = ''.join(self.public_analysis_article(article) for article in articles[1:11])
        if not analysis_items:
            analysis_items = '<p class="public-empty-analysis" data-empty="articles"></p>'
        page = page.replace('<div id="database-analysis-articles"></div>', analysis_items)
        event_items = ''.join(self.public_news_event_card(item) for item in news_events)
        page = page.replace('<div id="database-news-events"></div>', event_items or '<p class="public-empty-analysis" data-empty="events"></p>')
        page = re.sub(r'\s*<section class="section editorial-workspace" id="atelier">.*?</section>\s*', '\n', page, flags=re.S)
        page = re.sub(r'<dialog class="modal-dialog.*?</dialog>\s*', '', page, flags=re.S)
        page = page.replace('href="#atelier"', 'href="/admin"')
        page = page.replace('id="public-theme-filter"><option value="">Tous les thèmes</option></select>', f'id="public-theme-filter">{theme_options}</select>')
        page = page.replace('id="public-tag-filter"><option value="">Tous les tags</option></select>', f'id="public-tag-filter">{tag_options}</select>')
        page = page.replace('<div class="public-article-grid" id="public-feed"></div>', feed)
        page = page.replace('<div id="themes-menu"></div>', f'<div id="themes-menu">{self.theme_links(themes)}</div>')
        if language != 'fr':
            with connect() as db:
                page = self.translate_static_sections(db, page, language)
        page = page.replace('<div id="database-explore-themes"></div>', self.explore_themes(themes, 'Explorer nos thématiques'))
        page = page.replace('<div id="database-theme-blocks"></div>', ''.join(self.theme_block(theme, f'/thematique/{quote(theme["id"])}') for theme in themes))
        self.send_body(page)

    def translate_static_sections(self, db: Database, page: str, language: str) -> str:
        """Traduit les sections fixes d'index.html (rédigées en français), entre leurs commentaires de repère."""
        for start, end in STATIC_SECTIONS:
            if start not in page or end not in page:
                continue
            head, rest = page.split(start, 1)
            section, tail = rest.split(end, 1)
            translated = translate_records(db, [{'language': 'fr', 'html': section}], language, (), ('html',))[0]
            page = head + start + translated['html'] + end + tail
        return page

    def localized_themes(self, db: Database, language: str) -> list[dict]:
        return translate_records(db, [{**theme, 'language': 'fr'} for theme in THEMES], language, THEME_FIELDS)

    def theme_links(self, themes: list[dict], current: str = '') -> str:
        return ''.join(f'<a href="/thematique/{quote(theme["id"])}" lang="{esc(content_lang(theme))}">{esc(theme["title"])}</a>' for theme in themes if theme['id'] != current)

    def explore_themes(self, themes: list[dict], heading: str, current: str = '') -> str:
        return f'<section class="morethemes" id="explorer-thematiques"><h2>{esc(heading)}</h2><div class="themegrid">{self.theme_links(themes, current)}</div></section>'

    def theme_block(self, theme: dict, more_href: str, title_tag: str = 'h2') -> str:
        """Bloc d'une thématique selon les maquettes 7 à 13 : image à gauche, question et texte à droite."""
        question = f'<h3>{esc(theme["question"])}</h3>' if theme['question'] else ''
        paragraphs = ''.join(f'<p>{esc(theme[key])}</p>' for key in ('text', 'text2') if theme[key])
        signature = f'<p class="mk-signature">{esc(theme["signature"])}</p>' if theme['signature'] else ''
        return f'''<article class="mk-theme" id="theme-{esc(theme['id'])}" lang="{esc(content_lang(theme))}"><{title_tag} class="mk-page-title">{esc(theme['title'])}</{title_tag}><div class="mk-split"><img src="/assets/{esc(theme['image'])}" alt="Illustration : {esc(theme['title'])}" loading="lazy"><div class="mk-theme-text">{question}{paragraphs}{signature}<a class="mk-more" href="{esc(more_href)}">Voir plus d’informations<br>{esc(theme['button'])}</a></div></div></article>'''

    def public_theme(self, theme_id: str) -> None:
        if not theme_id.strip('/'):
            return self.redirect('/#thematiques')
        if theme_id in THEME_ALIASES:
            return self.redirect(f'/thematique/{THEME_ALIASES[theme_id]}')
        theme = next((item for item in THEMES if item['id'] == theme_id), None)
        if not theme:
            return self.public_page('Thématique introuvable', '<main class="article-page"><h1>Thématique introuvable</h1><a href="/#thematiques">Retour à l’accueil</a></main>', 404)
        with connect() as db:
            language = self.selected_language(db)
            themes = self.localized_themes(db, language)
            articles = db.execute("SELECT articles.* FROM articles JOIN article_categories ON article_categories.article_id=articles.id JOIN categories ON categories.id=article_categories.category_id WHERE articles.status='published' AND categories.slug=? ORDER BY articles.published_at DESC, articles.created_at DESC", (theme['category'],)).fetchall()
            taxonomy = {article['id']: self.get_article_taxonomy(db, article['id']) for article in articles}
            all_cats = {row['id']: row for cats, _ in taxonomy.values() for row in cats}
            all_tags = {row['id']: row for _, tags in taxonomy.values() for row in tags}
            cats_by_id = {row['id']: row for row in self.localize_terms(db, list(all_cats.values()), language)}
            tags_by_id = {row['id']: row for row in self.localize_terms(db, list(all_tags.values()), language)}
            articles = translate_records(db, articles, language, ('title', 'excerpt'))
        current = next(item for item in themes if item['id'] == theme_id)
        cards = ''.join(self.public_card(article, [cats_by_id[row['id']] for row in taxonomy[article['id']][0]], [tags_by_id[row['id']] for row in taxonomy[article['id']][1]]) for article in articles)
        listing = f'<div class="public-article-grid theme-articles">{cards}</div>' if cards else '<p class="theme-empty">Aucune publication pour cette thématique pour le moment.</p>'
        body = f'''<main class="article-page theme-page">{self.theme_block(current, '#theme-articles', 'h1')}<section id="theme-articles"><h2 class="theme-subtitle">Analyses et contributions</h2>{listing}<a class="theme-button" href="/#articles">Toutes les analyses →</a></section>{self.explore_themes(themes, 'Autres thématiques', theme_id)}</main>'''
        self.public_page(current['title'], body)

    def public_featured_article(self, article: dict) -> str:
        image = f'<img class="featured-image" src="{esc(article["featured_image"])}" alt="Image mise en avant de {esc(article["title"])}">' if article['featured_image'] else '<span class="featured-image-placeholder">I·D</span>'
        date = long_date(article['published_at'] or article['created_at'], article.get('display_language', 'fr'))
        return f'''<article class="featured-article" lang="{esc(content_lang(article))}"><a href="/article/{quote(article['slug'])}" class="image-link">{image}</a><h1><a href="/article/{quote(article['slug'])}">{esc(article['title'])}</a></h1><p class="featured-author">{esc(article['author'])}</p><p>{esc(article['excerpt'])} <time class="mk-date">{esc(date)}</time></p></article>'''

    def public_analysis_article(self, article: dict) -> str:
        image = f'<img src="{esc(article["featured_image"])}" alt="Image mise en avant de {esc(article["title"])}" loading="lazy">' if article['featured_image'] else '<span class="analysis-image-placeholder">I·D</span>'
        date = long_date(article['published_at'] or article['created_at'], article.get('display_language', 'fr'))
        return f'''<article class="analysis-item" lang="{esc(content_lang(article))}"><a href="/article/{quote(article['slug'])}" tabindex="-1">{image}</a><div><h3><a href="/article/{quote(article['slug'])}">{esc(article['title'])}</a> <span class="analysis-author">{esc(article['author'])}</span></h3><p>{esc(article['excerpt'])}</p><time class="mk-date">{esc(date)}</time></div></article>'''

    def public_news_event_card(self, item: dict) -> str:
        label = 'Événement' if item['kind'] == 'event' else 'Actualité'
        date = item['starts_at'] or item['created_at']
        when = f'<time>{esc(date[:10])}</time>' if date else ''
        location = f'<span>{esc(item["location"])}</span>' if item['location'] else ''
        return f'''<article class="event-entry" lang="{esc(content_lang(item))}"><span class="event-kind">{label}</span><h3><a href="/actualite/{quote(item['slug'])}">{esc(item['title'])}</a></h3><p>{esc(item['summary'])}</p><div class="event-meta">{when}{location}</div></article>'''

    def public_news_events(self) -> None:
        with connect() as db:
            language = self.selected_language(db)
            items = db.execute("SELECT * FROM news_events WHERE status='published' ORDER BY CASE WHEN starts_at='' THEN created_at ELSE starts_at END DESC").fetchall()
            items = translate_records(db, items, language, ('title', 'summary'))
        cards = ''.join(self.public_news_event_card(item) for item in items) or '<div class="public-empty-featured" data-empty="events"></div>'
        body = f'''<main class="article-page"><span class="eyebrow">I-Dialogos</span><h1>Actualités et événements</h1><section class="event-list">{cards}</section></main>'''
        self.public_page('Actualités et événements', body)

    def public_news_event(self, slug: str, query: dict[str, list[str]]) -> None:
        with connect() as db:
            item = db.execute("SELECT * FROM news_events WHERE slug=? AND status='published'", (slug,)).fetchone()
            if not item:
                return self.public_page('Contenu introuvable', '<main class="article-page"><h1>Contenu introuvable</h1><a href="/actualites">Retour aux actualités</a></main>', 404)
            language = self.selected_language(db)
            show_original = bool(query.get('original'))
            if not show_original:
                item = translate_records(db, [item], language, ('title', 'summary'), ('body_html',))[0]
        page_href = f'/actualite/{quote(slug)}' + (f'?lang={quote(query["lang"][0])}' if query.get('lang') else '')
        notice = self.translation_notice(item, language, page_href, show_original and item['language'] != language)
        label = 'Événement' if item['kind'] == 'event' else 'Actualité'
        image = f'<img class="article-featured" src="{esc(item["image_url"])}" alt="Illustration : {esc(item["title"])}">' if item['image_url'] else ''
        dates = ' · '.join(esc(value[:10]) for value in (item['starts_at'], item['ends_at']) if value)
        location = f'<span>{esc(item["location"])}</span>' if item['location'] else ''
        body = f'''<main class="article-page" lang="{esc(content_lang(item) if not show_original else item['language'])}">{notice}<span class="eyebrow">{label}</span><h1>{esc(item['title'])}</h1><div class="article-byline">{dates}{' · ' if dates and location else ''}{location}</div>{image}<p class="article-excerpt">{esc(item['summary'])}</p><div class="article-rich-content">{item['body_html']}</div><a class="back-public" href="/actualites">← Toutes les actualités</a></main>'''
        self.public_page(item['title'], body)

    def get_article_taxonomy(self, db: Database, article_id: str) -> tuple[list[dict], list[dict]]:
        cats = db.execute('SELECT categories.* FROM categories JOIN article_categories ON categories.id=article_categories.category_id WHERE article_categories.article_id=? ORDER BY categories.name', (article_id,)).fetchall()
        tags = db.execute('SELECT tags.* FROM tags JOIN article_tags ON tags.id=article_tags.tag_id WHERE article_tags.article_id=? ORDER BY tags.name', (article_id,)).fetchall()
        return cats, tags

    def public_card(self, article: dict, cats: list[dict], tags: list[dict]) -> str:
        image = f'<img src="{esc(article["featured_image"])}" alt="" loading="lazy">' if article['featured_image'] else '<span class="cover-placeholder">I·D</span>'
        category = ', '.join(row['name'] for row in cats) or 'Les Notes'
        category_slugs = ' '.join(row['slug'] for row in cats)
        tag_slugs = ' '.join(row['slug'] for row in tags)
        tag_html = ''.join(f'<a href="/?tag={quote(row["slug"])}#articles">#{esc(row["name"])}</a>' for row in tags)
        date = article['published_at'] or article['created_at']
        return f'''<article class="public-article-card" lang="{esc(content_lang(article))}" data-categories="{esc(category_slugs)}" data-tags="{esc(tag_slugs)}"><a class="public-card-image" href="/article/{quote(article['slug'])}">{image}</a><div class="public-card-body"><div class="article-card-meta"><span>{esc(category)}</span><time>{esc(date[:10])}</time></div><h3><a href="/article/{quote(article['slug'])}">{esc(article['title'])}</a></h3><p>{esc(article['excerpt'])}</p><div class="tag-chips">{tag_html}</div><a class="read-link" href="/article/{quote(article['slug'])}">Lire l’article <span>→</span></a></div></article>'''

    def public_article(self, slug: str, query: dict[str, list[str]]) -> None:
        with connect() as db:
            article = db.execute("SELECT * FROM articles WHERE slug=? AND status='published'", (slug,)).fetchone()
            if not article:
                return self.public_page('Article introuvable', '<main class="article-page"><h1>Article introuvable</h1><a href="/">Retour à l’accueil</a></main>', 404)
            cats, tags = self.get_article_taxonomy(db, article['id'])
            comments = db.execute("SELECT * FROM comments WHERE article_id=? AND status='approved' ORDER BY created_at", (article['id'],)).fetchall()
            language = self.selected_language(db)
            show_original = bool(query.get('original'))
            if not show_original:
                article = translate_records(db, [article], language, ('title', 'excerpt'), ('body_html',))[0]
                cats = self.localize_terms(db, cats, language)
                tags = self.localize_terms(db, tags, language)
        page_href = f'/article/{quote(slug)}' + (f'?lang={quote(query["lang"][0])}' if query.get('lang') else '')
        notice = self.translation_notice(article, language, page_href, show_original and article['language'] != language)
        topic_chips = ''.join(f'<a href="/?category={quote(row["slug"])}#articles">{esc(row["name"])}</a>' for row in cats)
        tags_html = ''.join(f'<a href="/?tag={quote(row["slug"])}#articles">#{esc(row["name"])}</a>' for row in tags)
        image = f'<img class="article-featured" src="{esc(article["featured_image"])}" alt="Image mise en avant de l’article">' if article['featured_image'] else ''
        comments_block = ''
        if article['comments_enabled']:
            self.session(create=True)  # crée la session porteuse du jeton anti-falsification du formulaire
            comment_items = ''.join(f'<article class="comment"><strong>{esc(row["author"])}</strong><time>{esc(row["created_at"][:10])}</time><p>{esc(row["body"])}</p></article>' for row in comments)
            form = f'''<form class="comment-form" method="post" action="/article/{quote(slug)}/comment">{self.csrf_field()}<h3>Laisser un commentaire</h3><label>Nom<input name="author" required maxlength="100"></label><label>Adresse e-mail (facultatif)<input type="email" name="email" maxlength="200"></label><label>Commentaire<textarea name="body" required maxlength="4000" rows="5"></textarea></label><button class="primary-button">Envoyer pour modération</button><small>Votre commentaire sera visible après validation par l’équipe.</small></form>'''
            flash = '<p class="success-message">Votre commentaire a été envoyé et sera publié après modération.</p>' if query.get('sent') else ''
            comments_block = f'<section id="comments" class="comments-area"><h2>Commentaires ({len(comments)})</h2>{flash}{comment_items or "<p>Soyez le premier à commenter cet article.</p>"}{form}</section>'
        body = f'''<main class="article-page" lang="{esc(content_lang(article) if not show_original else article['language'])}">{notice}<div class="article-topic-chips">{topic_chips}</div><h1>{esc(article['title'])}</h1><div class="article-byline">{esc(article['author'])} · {esc(article['published_at'][:10] if article['published_at'] else article['created_at'][:10])}</div>{image}<p class="article-excerpt">{esc(article['excerpt'])}</p><div class="article-rich-content">{article['body_html']}</div><div class="article-topic-chips article-tag-list">{tags_html}</div>{comments_block}<a class="back-public" href="/">← Retour à l’accueil</a></main>'''
        self.public_page(article['title'], body)

    def comment_post(self, slug: str, form: dict[str, str]) -> None:
        with connect() as db:
            article = db.execute("SELECT id,comments_enabled FROM articles WHERE slug=? AND status='published'", (slug,)).fetchone()
            if not article or not article['comments_enabled']:
                return self.send_body('Commentaires fermés.', 403, 'text/plain; charset=utf-8')
            author = form.get('author', '').strip()[:100]
            email = form.get('email', '').strip()[:200]
            body = form.get('body', '').strip()[:4000]
            if author and body:
                db.execute('INSERT INTO comments(article_id,author,email,body,status,created_at) VALUES(?,?,?,?,?,?)', (article['id'], author, email, body, 'pending', now_iso()))
        return self.redirect(f'/article/{quote(slug)}?sent=1#comments')

    def require_admin(self) -> dict | None:
        session = self.auth()
        if not session:
            self.redirect('/admin/login')
        return session

    def dashboard(self, active: str = 'home') -> None:
        session = self.auth()
        if not session:
            with connect() as db:
                exists = db.execute('SELECT 1 FROM users LIMIT 1').fetchone()
            return self.redirect('/admin/login' if exists else '/admin/setup')
        with connect() as db:
            articles = db.execute('''SELECT articles.*, templates.name AS template_name FROM articles LEFT JOIN templates ON templates.id=articles.template_id ORDER BY updated_at DESC''').fetchall()
            count_published = db.execute("SELECT COUNT(*) AS total FROM articles WHERE status='published'").fetchone()['total']
            count_drafts = db.execute("SELECT COUNT(*) AS total FROM articles WHERE status='draft'").fetchone()['total']
            count_comments = db.execute("SELECT COUNT(*) AS total FROM comments WHERE status='pending'").fetchone()['total']
        rows = ''.join(self.admin_article_row(article) for article in articles) or '<div class="admin-empty">Aucun article. Créez votre première publication.</div>'
        content = f'''<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Bonjour {esc(session['username'])}</span><h1>Tableau de bord</h1><p>Gérez vos articles et leur publication.</p></div><a class="primary-button" href="/admin/articles/new">+ Nouvel article</a></div><div class="admin-stats"><div><strong>{count_published}</strong><span>Publiés</span></div><div><strong>{count_drafts}</strong><span>Brouillons</span></div><div><strong>{count_comments}</strong><span>Commentaires à modérer</span></div><div><strong>{len(articles)}</strong><span>Total articles</span></div></div><section class="admin-panel"><div class="panel-heading"><h2>Articles</h2><input type="search" id="admin-filter" placeholder="Filtrer les articles"></div><div class="admin-table-wrap"><table><thead><tr><th>Titre</th><th>État</th><th>Auteur</th><th>Modèle</th><th>Dernière modification</th><th>Actions</th></tr></thead><tbody id="admin-articles">{rows}</tbody></table></div></section></main>'''
        self.send_body(self.admin_page('Tableau de bord', content, active))

    def events_page(self) -> None:
        if not self.auth():
            return self.redirect('/admin/login')
        with connect() as db:
            items = db.execute("SELECT * FROM news_events ORDER BY updated_at DESC").fetchall()
        rows = ''.join(
            f'''<tr><td><strong>{esc(item['title'])}</strong><small>{'Événement' if item['kind']=='event' else 'Actualité'} · {esc(item['starts_at'] or 'Sans date')}</small></td><td><span class="status-badge {item['status']}">{'Publié' if item['status']=='published' else 'Brouillon'}</span></td><td>{esc(item['location'] or '—')}</td><td class="table-actions"><a href="/admin/events/{item['id']}/edit">Modifier</a>{f'<a href="/actualite/{quote(item["slug"])}" target="_blank">Voir</a>' if item['status']=='published' else ''}<form method="post" action="/admin/events/{item['id']}/delete" onsubmit="return confirm('Supprimer ce contenu ?')">{self.csrf_field()}<button>Supprimer</button></form></td></tr>'''
            for item in items
        ) or '<tr><td colspan="4">Aucune actualité ni aucun événement.</td></tr>'
        content = f'''<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Publication</span><h1>Actualités et événements</h1><p>Publiez les nouvelles et rendez-vous d’I-Dialogos.</p></div><a class="primary-button" href="/admin/events/new">+ Nouveau contenu</a></div><section class="admin-panel"><div class="admin-table-wrap"><table><thead><tr><th>Titre</th><th>État</th><th>Lieu</th><th>Actions</th></tr></thead><tbody>{rows}</tbody></table></div></section></main>'''
        self.send_body(self.admin_page('Actualités et événements', content, 'events'))

    def languages_page(self) -> None:
        if not self.auth():
            return self.redirect('/admin/login')
        with connect() as db:
            rows = db.execute('SELECT * FROM languages ORDER BY is_default DESC,label').fetchall()
            cached = db.execute('SELECT COUNT(*) AS total FROM translations').fetchone()['total']
            per_language = db.execute("SELECT language, COUNT(*) AS total FROM (SELECT language FROM articles WHERE status='published' UNION ALL SELECT language FROM news_events WHERE status='published') AS content GROUP BY language ORDER BY total DESC").fetchall()
        query = parse_qs(urlparse(self.path).query)
        flash = ''
        if query.get('detected'):
            flash = f'<p class="success-message">Détection terminée : {esc(query["detected"][0])} contenu(s) mis à jour.</p>'
        elif query.get('translating'):
            flash = '<p class="success-message">La traduction des contenus publiés a démarré en arrière-plan. Les pages traduites seront ensuite affichées instantanément.</p>'
        sources = ', '.join(f'{esc(row["language"].upper())} : {row["total"]}' for row in per_language) or 'aucun contenu publié'
        translation_panel = f'''<section class="admin-panel"><h2>Traduction automatique des publications</h2><p>Chaque article et chaque actualité est affiché dans la langue choisie par le visiteur. Sa langue d’origine est détectée automatiquement, puis le titre, le résumé, le contenu, les catégories et les étiquettes sont traduits et mis en cache.</p><p class="field-help">Service utilisé : <strong>{esc(provider_name())}</strong> · {cached} segment(s) traduit(s) en cache · Langues d’origine des contenus publiés : {sources}.</p><div class="side-actions"><form method="post" action="/admin/languages">{self.csrf_field()}<input type="hidden" name="action" value="detect"><button class="plain-button">Détecter la langue des contenus existants</button></form><form method="post" action="/admin/languages">{self.csrf_field()}<input type="hidden" name="action" value="pretranslate"><button class="primary-button">Traduire tous les contenus maintenant</button></form></div></section>'''
        items = ''.join(
            f'''<tr><td><strong>{esc(row['label'])}</strong></td><td><code>{esc(row['code'].upper())}</code></td><td>{'Langue par défaut' if row['is_default'] else ''}</td><td><form method="post" action="/admin/languages">{self.csrf_field()}<input type="hidden" name="code" value="{esc(row['code'])}"><input type="hidden" name="action" value="{'disable' if row['enabled'] else 'enable'}"><button class="plain-button">{'Désactiver' if row['enabled'] else 'Activer'}</button></form></td><td><form method="post" action="/admin/languages">{self.csrf_field()}<input type="hidden" name="code" value="{esc(row['code'])}"><input type="hidden" name="action" value="default"><button class="plain-button" {'disabled' if row['is_default'] or not row['enabled'] else ''}>Définir par défaut</button></form></td></tr>'''
            for row in rows
        )
        content = f'''<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Configuration du site</span><h1>Langues</h1><p>Activez les langues proposées aux visiteurs et choisissez la langue par défaut.</p></div></div><section class="admin-panel"><div class="admin-table-wrap"><table><thead><tr><th>Langue</th><th>Code</th><th>Par défaut</th><th>Disponibilité</th><th></th></tr></thead><tbody>{items}</tbody></table></div></section>{flash}{translation_panel}</main>'''
        self.send_body(self.admin_page('Gestion des langues', content, 'languages'))

    def languages_post(self, form: dict[str, str]) -> None:
        code = form.get('code', '').strip().lower()
        action = form.get('action')
        if action == 'detect':
            return self.redirect(f'/admin/languages?detected={self.detect_existing_languages()}')
        if action == 'pretranslate':
            pretranslate_in_background()
            return self.redirect('/admin/languages?translating=1')
        with connect() as db:
            language = db.execute('SELECT * FROM languages WHERE code=?', (code,)).fetchone()
            if language:
                if action == 'default' and language['enabled']:
                    db.execute('UPDATE languages SET is_default=0')
                    db.execute('UPDATE languages SET is_default=1 WHERE code=?', (code,))
                elif action == 'enable':
                    db.execute('UPDATE languages SET enabled=1 WHERE code=?', (code,))
                elif action == 'disable' and not language['is_default']:
                    db.execute('UPDATE languages SET enabled=0 WHERE code=?', (code,))
        return self.redirect('/admin/languages')

    def language_options(self, languages: list[dict], current: str) -> str:
        known = {row['code'] for row in languages}
        selected = current if current in known else 'auto'
        options = [f'<option value="auto" {"selected" if selected == "auto" else ""}>Détection automatique</option>']
        options += [f'<option value="{esc(row["code"])}" {"selected" if row["code"] == selected else ""}>{esc(row["label"])}</option>' for row in languages]
        return ''.join(options)

    def translations_panel(self, languages: list[dict], article: dict | None) -> str:
        if not article:
            return '<section class="side-panel"><h2>Traductions</h2><p class="field-help">Après publication, l’article sera traduit automatiquement dans chaque langue active du site.</p></section>'
        items = []
        for row in languages:
            if not row['enabled']:
                continue
            if row['code'] == article['language']:
                state = '<span class="default-mark">Langue d’origine</span>'
            elif article['status'] == 'published':
                state = f'<a href="/article/{quote(article["slug"])}?lang={quote(row["code"])}" target="_blank">Aperçu traduit ↗</a>'
            else:
                state = '<small>Disponible après publication</small>'
            items.append(f'<li><strong>{esc(row["label"])}</strong> {state}</li>')
        return f'<section class="side-panel"><h2>Traductions</h2><ul class="translation-list">{"".join(items)}</ul><small class="field-help">Les traductions sont mises à jour automatiquement à chaque modification.</small></section>'

    def detect_existing_languages(self) -> int:
        """Recalcule la langue d'origine de tous les articles et actualités à partir de leur texte."""
        changed = 0
        with connect() as db:
            for table, summary in (('articles', 'excerpt'), ('news_events', 'summary')):
                for row in db.execute(f'SELECT id,title,{summary} AS summary,body_html,language FROM {table}').fetchall():
                    detected = detect_language('\n'.join((row['title'], row['summary'], html_to_text(row['body_html']))))
                    if detected and detected != row['language']:
                        db.execute(f'UPDATE {table} SET language=? WHERE id=?', (detected, row['id']))
                        changed += 1
        return changed

    def event_form(self, event_id: str | None = None, error: str = '') -> None:
        if not self.auth():
            return self.redirect('/admin/login')
        item = None
        with connect() as db:
            languages = db.execute('SELECT code,label,is_default,enabled FROM languages ORDER BY is_default DESC,label').fetchall()
        if event_id:
            try:
                numeric_id = int(event_id)
            except ValueError:
                return self.redirect('/admin/events')
            with connect() as db:
                item = db.execute('SELECT * FROM news_events WHERE id=?', (numeric_id,)).fetchone()
            if not item:
                return self.redirect('/admin/events')
        get = lambda key, default='': item[key] if item else default
        language_options = self.language_options(languages, item['language'] if item else '')
        content = f'''<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">{'Modifier' if item else 'Nouveau'}</span><h1>{'Modifier le contenu' if item else 'Créer une actualité ou un événement'}</h1></div><a class="plain-button" href="/admin/events">← Retour</a></div>{f'<div class="form-error">{esc(error)}</div>' if error else ''}<section class="admin-panel"><form method="post" action="/admin/events/save" class="event-admin-form">{self.csrf_field()}<input type="hidden" name="id" value="{esc(get('id'))}"><label class="admin-field">Langue d’origine<select name="language">{language_options}</select><small class="field-help">Les visiteurs des autres langues voient une traduction automatique.</small></label><label class="admin-field">Type<select name="kind"><option value="news" {'selected' if get('kind','news')=='news' else ''}>Actualité</option><option value="event" {'selected' if get('kind')=='event' else ''}>Événement</option></select></label><label class="admin-field">Titre<input name="title" required maxlength="180" value="{esc(get('title'))}"></label><label class="admin-field">Résumé<textarea name="summary" rows="3" maxlength="500">{esc(get('summary'))}</textarea></label><label class="admin-field">Contenu<textarea name="body" rows="10" placeholder="Détails de l’actualité ou de l’événement">{esc(re.sub(r'<[^>]*>', '', get('body_html')))}</textarea></label><label class="admin-field">Image (URL https:// ou image déjà téléversée)<input name="image_url" type="url" value="{esc(get('image_url'))}" placeholder="https://..."></label><label class="admin-field">Lieu<input name="location" maxlength="180" value="{esc(get('location'))}"></label><div class="event-date-fields"><label class="admin-field">Date de début<input name="starts_at" type="date" value="{esc(get('starts_at'))}"></label><label class="admin-field">Date de fin<input name="ends_at" type="date" value="{esc(get('ends_at'))}"></label></div><label class="admin-field">État<select name="status"><option value="draft" {'selected' if get('status','draft')=='draft' else ''}>Brouillon</option><option value="published" {'selected' if get('status')=='published' else ''}>Publié</option></select></label><div class="side-actions"><button class="primary-button" type="submit">Enregistrer</button><a class="plain-button" href="/admin/events">Annuler</a></div></form></section></main>'''
        self.send_body(self.admin_page('Éditeur actualités et événements', content, 'events'))

    def save_event(self, form: dict[str, str]) -> None:
        title = form.get('title', '').strip()[:180]
        if not title:
            return self.event_form(error='Le titre est obligatoire.')
        raw_id = form.get('id', '').strip()
        try:
            event_id = int(raw_id) if raw_id else None
        except ValueError:
            return self.redirect('/admin/events')
        kind = 'event' if form.get('kind') == 'event' else 'news'
        status = 'published' if form.get('status') == 'published' else 'draft'
        image_url = form.get('image_url', '').strip()[:1000]
        if image_url and not (image_url.startswith('https://') or image_url.startswith('/uploads/')):
            image_url = ''
        starts_at = form.get('starts_at', '').strip()[:10]
        ends_at = form.get('ends_at', '').strip()[:10]
        location = form.get('location', '').strip()[:180]
        language = form.get('language', 'auto')
        summary = form.get('summary', '').strip()[:500]
        paragraphs = ''.join(f'<p>{line}</p>' for line in form.get('body', '').splitlines() if line.strip())
        body_html = safe_rich_html(paragraphs)
        now = now_iso()
        with connect() as db:
            existing = db.execute('SELECT * FROM news_events WHERE id=?', (event_id,)).fetchone() if event_id else None
            if event_id and not existing:
                return self.redirect('/admin/events')
            root = slugify(title)
            slug, suffix = root, 2
            while db.execute('SELECT id FROM news_events WHERE slug=? AND id<>?', (slug, event_id or 0)).fetchone():
                slug, suffix = f'{root}-{suffix}', suffix + 1
            language = resolve_language(db, language, title, summary, html_to_text(body_html))
            values = (kind, title, slug, summary, body_html, image_url, location, starts_at, ends_at, status, now, language)
            if existing:
                db.execute('UPDATE news_events SET kind=?,title=?,slug=?,summary=?,body_html=?,image_url=?,location=?,starts_at=?,ends_at=?,status=?,updated_at=?,language=? WHERE id=?', (*values, event_id))
            else:
                event_id = db.execute('INSERT INTO news_events(kind,title,slug,summary,body_html,image_url,location,starts_at,ends_at,status,created_at,updated_at,language) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id', (*values[:10], now, now, language)).fetchone()['id']
        if status == 'published':
            pretranslate_in_background(article_ids=[], event_ids=[event_id])
        return self.redirect('/admin/events')

    def delete_event(self, event_id: str) -> None:
        try:
            numeric_id = int(event_id)
        except ValueError:
            return self.redirect('/admin/events')
        with connect() as db:
            db.execute('DELETE FROM news_events WHERE id=?', (numeric_id,))
        return self.redirect('/admin/events')

    def admin_article_row(self, article: dict) -> str:
        with connect() as db:
            cats, tags = self.get_article_taxonomy(db, article['id'])
        taxonomy = ', '.join([row['name'] for row in cats] + ['#' + row['name'] for row in tags])
        status = 'Publié' if article['status'] == 'published' else 'Brouillon'
        href = f'/article/{quote(article["slug"])}'
        return f'''<tr data-search="{esc((article['title']+' '+article['author']+' '+taxonomy).lower())}"><td><strong>{esc(article['title'])}</strong><small><code>{esc(article['language'].upper())}</code> {esc(taxonomy)}</small></td><td><span class="status-badge {article['status']}">{status}</span></td><td>{esc(article['author'])}</td><td>{esc(article['template_name'] or '—')}</td><td>{esc(article['updated_at'][:10])}</td><td class="table-actions"><a href="/admin/articles/{quote(article['id'])}/edit">Modifier</a>{f'<a href="{href}" target="_blank">Voir</a>' if article['status']=='published' else ''}<form method="post" action="/admin/articles/{quote(article['id'])}/delete" onsubmit="return confirm('Supprimer cet article ?')">{self.csrf_field()}<button>Supprimer</button></form></td></tr>'''

    def article_form(self, article_id: str | None = None, error: str = '') -> None:
        session = self.auth()
        if not session:
            return self.redirect('/admin/login')
        with connect() as db:
            article = db.execute('SELECT * FROM articles WHERE id=?', (article_id,)).fetchone() if article_id else None
            if article_id and not article:
                return self.redirect('/admin')
            categories = db.execute('SELECT * FROM categories ORDER BY name').fetchall()
            tags = db.execute('SELECT * FROM tags ORDER BY name').fetchall()
            templates = db.execute('SELECT * FROM templates ORDER BY is_default DESC,name').fetchall()
            languages = db.execute('SELECT code,label,is_default,enabled FROM languages ORDER BY is_default DESC,label').fetchall()
            chosen_categories, chosen_tags = self.get_article_taxonomy(db, article_id) if article else ([], [])
        checked_cats = {row['id'] for row in chosen_categories}
        checked_tags = {row['id'] for row in chosen_tags}
        cat_options = ''.join(f'<label class="check-option"><input type="checkbox" name="categories" value="{row["id"]}" {"checked" if row["id"] in checked_cats else ""}>{esc(row["name"])}</label>' for row in categories) or '<p>Aucune catégorie. <a href="/admin/categories">En ajouter une</a>.</p>'
        tag_options = ''.join(f'<label class="check-option"><input type="checkbox" name="tags" value="{row["id"]}" {"checked" if row["id"] in checked_tags else ""}>#{esc(row["name"])}</label>' for row in tags)
        template_options = ''.join(f'<option value="{row["id"]}" data-body="{esc(row["body_html"])}" {"selected" if (article and article["template_id"]==row["id"]) or (not article and row["is_default"]) else ""}>{esc(row["name"])}{" · modèle par défaut" if row["is_default"] else ""}</option>' for row in templates)
        language_options = self.language_options(languages, article['language'] if article else '')
        translations_panel = self.translations_panel(languages, article)
        selected_status = article['status'] if article else 'draft'
        body_html = article['body_html'] if article else (templates[0]['body_html'] if templates else '')
        title = article['title'] if article else ''
        image = article['featured_image'] if article else ''
        comments_enabled = article['comments_enabled'] if article else 1
        content = f'''<main class="admin-main editor-main"><div class="admin-title-row"><div><span class="eyebrow">{ 'Modifier la publication' if article else 'Nouvelle publication' }</span><h1>{esc(title or 'Rédiger un article')}</h1></div><a class="plain-button" href="/admin">← Tableau de bord</a></div>{f'<div class="form-error">{esc(error)}</div>' if error else ''}<form method="post" action="/admin/articles/save" enctype="multipart/form-data" id="article-editor-form">{self.csrf_field()}<input type="hidden" name="id" value="{esc(article['id'] if article else '')}"><label class="admin-field">Langue d’origine<select name="language">{language_options}</select><small class="field-help">Laissez « Détection automatique » pour reconnaître la langue du texte. Les visiteurs des autres langues voient une traduction automatique.</small></label><div class="article-edit-layout"><div class="article-edit-main"><label class="admin-field">Titre<input name="title" required maxlength="180" value="{esc(title)}" placeholder="Titre de l’article"></label><label class="admin-field">Ajouter un contenu<div class="rich-toolbar" role="toolbar" aria-label="Mise en forme du texte"><button type="button" data-command="bold" title="Gras"><b>G</b></button><button type="button" data-command="italic" title="Italique"><i>I</i></button><button type="button" data-command="underline" title="Souligné"><u>S</u></button><button type="button" data-command="strikeThrough" title="Barré"><s>ab</s></button><span></span><select id="font-size" aria-label="Taille de police"><option value="3">Taille normale</option><option value="2">Petite</option><option value="4">Grande</option><option value="5">Très grande</option><option value="6">Titre</option></select><select id="block-format" aria-label="Style de paragraphe"><option value="p">Paragraphe</option><option value="h2">Titre 2</option><option value="h3">Titre 3</option><option value="blockquote">Citation</option></select><button type="button" data-command="insertUnorderedList" title="Liste à puces">• Liste</button><button type="button" data-command="insertOrderedList" title="Liste numérotée">1. Liste</button><button type="button" data-command="justifyLeft" title="Aligner à gauche">≡</button><button type="button" data-command="justifyCenter" title="Centrer">☰</button><button type="button" data-command="justifyRight" title="Aligner à droite">≡</button><label class="color-tool" title="Couleur du texte">A<input id="font-color" type="color" value="#28232e" aria-label="Couleur du texte"></label><button type="button" id="link-tool" title="Insérer un lien">↗</button><button type="button" data-command="removeFormat" title="Effacer la mise en forme">Tx</button></div><div id="rich-editor" class="rich-editor" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Commencez à rédiger votre article…">{body_html}</div><textarea name="body_html" id="body-html" hidden></textarea><small class="field-help">Sélectionnez un passage puis choisissez sa mise en forme. Le texte est nettoyé à l’enregistrement.</small><label class="admin-field excerpt-field">Chapô / résumé<textarea name="excerpt" rows="4" maxlength="500" placeholder="Quelques phrases pour présenter l’article">{esc(article['excerpt'] if article else '')}</textarea></label></label></div><aside class="article-edit-sidebar"><section class="side-panel"><h2>Publication</h2><label class="admin-field">État<select name="status"><option value="draft" {"selected" if selected_status=='draft' else ""}>Brouillon</option><option value="published" {"selected" if selected_status=='published' else ""}>Publier</option></select></label><label class="admin-field">Auteur/autrice<input name="author" required maxlength="120" value="{esc(article['author'] if article else session['username'])}"></label><div class="side-actions"><button class="primary-button" type="submit">{ 'Enregistrer' if article else 'Enregistrer l’article' }</button><a class="plain-button" href="/admin">Annuler</a></div></section>{translations_panel}<section class="side-panel"><h2>Image mise en avant</h2>{f'<img class="featured-preview" src="{esc(image)}" alt="Image actuelle">' if image else '<div class="image-placeholder">Aucune image sélectionnée</div>'}<label class="admin-field">Choisir une image<input type="file" name="featured_image" accept="image/jpeg,image/png,image/webp,image/gif"></label><small class="field-help">JPG, PNG, WebP ou GIF · 8 Mo maximum.</small></section><section class="side-panel"><h2>Catégories</h2><div class="check-list">{cat_options}</div><a class="small-admin-link" href="/admin/categories">+ Ajouter une catégorie</a></section><section class="side-panel"><h2>Étiquettes</h2><div class="check-list">{tag_options}</div><label class="admin-field">Ajouter des étiquettes<input name="new_tags" placeholder="Séparées par des virgules"></label><a class="small-admin-link" href="/admin/tags">Gérer toutes les étiquettes</a></section><section class="side-panel"><h2>Modèle</h2><label class="admin-field">Modèle<select name="template_id">{template_options}</select></label><a class="small-admin-link" href="/admin/templates">Gérer les modèles</a></section><section class="side-panel"><h2>Commentaires</h2><label class="check-option"><input type="checkbox" name="comments_enabled" value="1" {"checked" if comments_enabled else ""}>Autoriser les commentaires</label><a class="small-admin-link" href="/admin/comments">Modérer les commentaires</a></section></aside></div></form></main>'''
        self.send_body(self.admin_page('Éditeur d’article', content, 'articles'))

    def save_article(self, form: dict[str, str], files: dict[str, tuple[str, str, bytes]]) -> None:
        article_id = form.get('id', '').strip()
        title = form.get('title', '').strip()[:180]
        excerpt = form.get('excerpt', '').strip()[:500]
        body = safe_rich_html(form.get('body_html', ''))
        author = form.get('author', '').strip()[:120]
        language = form.get('language', 'auto')
        status = 'published' if form.get('status') == 'published' else 'draft'
        if not title or not author:
            return self.article_form(article_id or None, 'Le titre et le nom de l’auteur sont obligatoires.')
        image_path = ''
        uploaded = files.get('featured_image')
        if uploaded and uploaded[2]:
            original, mime, data = uploaded
            if len(data) > 8 * 1024 * 1024:
                return self.article_form(article_id or None, 'L’image dépasse la limite de 8 Mo.')
            ext = Path(original).suffix.lower()
            signatures = {'.jpg': (b'\xff\xd8\xff', 'image/jpeg'), '.jpeg': (b'\xff\xd8\xff', 'image/jpeg'), '.png': (b'\x89PNG\r\n\x1a\n', 'image/png'), '.gif': (b'GIF8', 'image/gif'), '.webp': (b'RIFF', 'image/webp')}
            signature, expected_mime = signatures.get(ext, (b'', ''))
            valid_header = data.startswith(signature) and (ext != '.webp' or data[8:12] == b'WEBP')
            if not signature or not valid_header or mime != expected_mime:
                return self.article_form(article_id or None, 'Choisissez une image JPEG, PNG, GIF ou WebP valide.')
            filename = f'{uuid.uuid4()}{ext}'
            (UPLOAD_DIR / filename).write_bytes(data)
            image_path = '/uploads/' + filename
        with connect() as db:
            existing = db.execute('SELECT * FROM articles WHERE id=?', (article_id,)).fetchone() if article_id else None
            article_id = existing['id'] if existing else uuid.uuid4().hex
            slug = unique_slug(db, title, article_id)
            now = now_iso()
            published_at = existing['published_at'] if existing else None
            if status == 'published' and not published_at:
                published_at = now
            if status == 'draft':
                published_at = None
            image_path = image_path or (existing['featured_image'] if existing else '')
            template_id = form.get('template_id') or None
            try:
                template_id = int(template_id) if template_id else None
            except ValueError:
                template_id = None
            template_exists = db.execute('SELECT id FROM templates WHERE id=?', (template_id,)).fetchone() if template_id else None
            if not template_exists:
                template = db.execute('SELECT id FROM templates WHERE is_default=1 LIMIT 1').fetchone()
                template_id = template['id'] if template else None
            language = resolve_language(db, language, title, excerpt, html_to_text(body))
            values = (title, slug, excerpt, body, image_path, status, author, template_id, 1 if form.get('comments_enabled') else 0, now, published_at, language)
            if existing:
                db.execute('UPDATE articles SET title=?,slug=?,excerpt=?,body_html=?,featured_image=?,status=?,author=?,template_id=?,comments_enabled=?,updated_at=?,published_at=?,language=? WHERE id=?', (*values, article_id))
            else:
                db.execute('INSERT INTO articles(id,title,slug,excerpt,body_html,featured_image,status,author,template_id,comments_enabled,created_at,updated_at,published_at,language) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)', (article_id, *values[:9], now, now, published_at, language))
            db.execute('DELETE FROM article_categories WHERE article_id=?', (article_id,))
            db.execute('DELETE FROM article_tags WHERE article_id=?', (article_id,))
            categories = form.getlist('categories')
            tags_selected = form.getlist('tags')
            for raw_id in categories:
                if raw_id.isdigit():
                    db.execute('INSERT OR IGNORE INTO article_categories(article_id,category_id) VALUES(?,?)', (article_id, int(raw_id)))
            names = [item.strip().lstrip('#')[:60] for item in form.get('new_tags', '').split(',') if item.strip()]
            for tag_id in tags_selected:
                if tag_id.isdigit():
                    db.execute('INSERT OR IGNORE INTO article_tags(article_id,tag_id) VALUES(?,?)', (article_id, int(tag_id)))
            for name in names:
                if not name:
                    continue
                row = db.execute('SELECT id FROM tags WHERE LOWER(name)=LOWER(?)', (name,)).fetchone()
                if not row:
                    root = slugify(name)
                    tag_slug, suffix = root, 2
                    while db.execute('SELECT 1 FROM tags WHERE slug=?', (tag_slug,)).fetchone():
                        tag_slug, suffix = f'{root}-{suffix}', suffix + 1
                    db.execute('INSERT INTO tags(name,slug,created_at) VALUES(?,?,?)', (name, tag_slug, now))
                    row = db.execute('SELECT id FROM tags WHERE LOWER(name)=LOWER(?)', (name,)).fetchone()
                db.execute('INSERT OR IGNORE INTO article_tags(article_id,tag_id) VALUES(?,?)', (article_id, row['id']))
        if status == 'published':
            pretranslate_in_background(article_ids=[article_id], event_ids=[])
        return self.redirect('/admin')

    def delete_article(self, article_id: str) -> None:
        with connect() as db:
            db.execute('DELETE FROM articles WHERE id=?', (article_id,))
        return self.redirect('/admin')

    def taxonomy_page(self, kind: str) -> None:
        session = self.auth()
        if not session:
            return self.redirect('/admin/login')
        table = 'categories' if kind == 'categories' else 'tags'
        title, label = ('Catégories', 'catégorie') if table == 'categories' else ('Étiquettes', 'étiquette')
        with connect() as db:
            rows = db.execute(f'SELECT {table}.*, COUNT({"article_categories" if table=="categories" else "article_tags"}.article_id) AS uses FROM {table} LEFT JOIN {"article_categories" if table=="categories" else "article_tags"} ON {table}.id={"article_categories" if table=="categories" else "article_tags"}.{"category_id" if table=="categories" else "tag_id"} GROUP BY {table}.id ORDER BY {table}.name').fetchall()
        items = ''.join(f'''<tr><td><strong>{esc(row['name'])}</strong></td><td>{row['uses']}</td><td><form method="post" action="/admin/{table}/delete">{self.csrf_field()}<input type="hidden" name="id" value="{row['id']}"><button class="danger-button" onclick="return confirm('Supprimer cette {label} ?')">Supprimer</button></form></td></tr>''' for row in rows)
        content = f'''<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Classement</span><h1>{title}</h1><p>Organisez les publications par {label}s.</p></div></div><section class="admin-panel taxonomy-admin-panel"><form method="post" action="/admin/{table}" class="inline-create">{self.csrf_field()}<label>{'Nom de la catégorie' if table=='categories' else 'Nom de l’étiquette'}<input name="name" required maxlength="80" placeholder="Nouvelle {label}"></label><button class="primary-button">Ajouter</button></form><div class="admin-table-wrap"><table><thead><tr><th>Nom</th><th>Articles liés</th><th></th></tr></thead><tbody>{items or '<tr><td colspan="3">Aucune donnée pour le moment.</td></tr>'}</tbody></table></div></section></main>'''
        self.send_body(self.admin_page(title, content, kind))

    def taxonomy_post(self, kind: str, form: dict[str, str]) -> None:
        if kind.endswith('/delete'):
            table = kind.split('/')[0]
            rel, fk = ('article_categories', 'category_id') if table == 'categories' else ('article_tags', 'tag_id')
            with connect() as db:
                db.execute(f'DELETE FROM {rel} WHERE {fk}=?', (form.get('id', ''),))
                db.execute(f'DELETE FROM {table} WHERE id=?', (form.get('id', ''),))
            return self.redirect('/admin/' + table)
        name = form.get('name', '').strip()[:80]
        table = 'categories' if kind == 'categories' else 'tags'
        if name:
            with connect() as db:
                exists = db.execute(f'SELECT id FROM {table} WHERE LOWER(name)=LOWER(?)', (name,)).fetchone()
                if not exists:
                    root = slugify(name)
                    slug, n = root, 2
                    while db.execute(f'SELECT 1 FROM {table} WHERE slug=?', (slug,)).fetchone():
                        slug, n = f'{root}-{n}', n + 1
                    db.execute(f'INSERT INTO {table}(name,slug,created_at) VALUES(?,?,?)', (name, slug, now_iso()))
        return self.redirect('/admin/' + table)

    def templates_page(self) -> None:
        if not self.auth():
            return self.redirect('/admin/login')
        with connect() as db:
            rows = db.execute('SELECT templates.*,COUNT(articles.id) AS uses FROM templates LEFT JOIN articles ON articles.template_id=templates.id GROUP BY templates.id ORDER BY is_default DESC,name').fetchall()
        items = ''.join(f'''<tr><td><strong>{esc(row['name'])}</strong>{'<span class="default-mark">Par défaut</span>' if row['is_default'] else ''}</td><td>{row['uses']}</td><td><form method="post" action="/admin/templates">{self.csrf_field()}<input type="hidden" name="action" value="default"><input type="hidden" name="id" value="{row['id']}"><button class="plain-button">Définir par défaut</button></form></td><td>{'' if row['is_default'] else f'<form method="post" action="/admin/templates">{self.csrf_field()}<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="{row["id"]}"><button class="danger-button" onclick="return confirm(&#39;Supprimer ce modèle ?&#39;)">Supprimer</button></form>'}</td></tr>''' for row in rows)
        content = f'''<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Structure des articles</span><h1>Modèles</h1><p>Choisissez le modèle proposé par défaut à la création d’un article.</p></div></div><section class="admin-panel"><form method="post" action="/admin/templates" class="template-create">{self.csrf_field()}<input type="hidden" name="action" value="create"><label class="admin-field">Nom du modèle<input name="name" required maxlength="80" placeholder="Ex. Analyse, entretien"></label><label class="admin-field">Contenu de départ <textarea name="body_html" rows="4" placeholder="Structure HTML facultative à insérer dans l’éditeur"></textarea></label><label class="check-option"><input type="checkbox" name="make_default" value="1">Définir comme modèle par défaut</label><button class="primary-button">Créer le modèle</button></form><div class="admin-table-wrap"><table><thead><tr><th>Modèle</th><th>Articles</th><th>Par défaut</th><th></th></tr></thead><tbody>{items}</tbody></table></div></section></main>'''
        self.send_body(self.admin_page('Modèles', content, 'templates'))

    def templates_post(self, form: dict[str, str]) -> None:
        action = form.get('action')
        with connect() as db:
            if action == 'create':
                name = form.get('name', '').strip()[:80]
                if name and not db.execute('SELECT 1 FROM templates WHERE LOWER(name)=LOWER(?)', (name,)).fetchone():
                    make_default = form.get('make_default') == '1'
                    if make_default:
                        db.execute('UPDATE templates SET is_default=0')
                    db.execute('INSERT INTO templates(name,body_html,is_default,created_at) VALUES(?,?,?,?)', (name, safe_rich_html(form.get('body_html', '')), int(make_default), now_iso()))
            elif action == 'default':
                db.execute('UPDATE templates SET is_default=0')
                db.execute('UPDATE templates SET is_default=1 WHERE id=?', (form.get('id', ''),))
            elif action == 'delete':
                row = db.execute('SELECT is_default FROM templates WHERE id=?', (form.get('id', ''),)).fetchone()
                if row and not row['is_default']:
                    db.execute('DELETE FROM templates WHERE id=?', (form.get('id', ''),))
        return self.redirect('/admin/templates')

    def comments_page(self) -> None:
        if not self.auth():
            return self.redirect('/admin/login')
        with connect() as db:
            rows = db.execute("SELECT comments.*,articles.title,articles.slug FROM comments JOIN articles ON articles.id=comments.article_id ORDER BY CASE comments.status WHEN 'pending' THEN 0 ELSE 1 END,comments.created_at DESC").fetchall()
        items = ''.join(f'''<article class="comment-admin-card"><div class="comment-admin-head"><strong>{esc(row['author'])}</strong><span class="status-badge {row['status']}">{'À modérer' if row['status']=='pending' else 'Publié'}</span><time>{esc(row['created_at'][:10])}</time></div><a href="/article/{quote(row['slug'])}#comments">Sur « {esc(row['title'])} »</a><p>{esc(row['body'])}</p><div class="comment-actions">{f'<form method="post" action="/admin/comments/{row["id"]}">{self.csrf_field()}<input type="hidden" name="action" value="approve"><button class="primary-button">Approuver</button></form>' if row['status']=='pending' else ''}<form method="post" action="/admin/comments/{row['id']}">{self.csrf_field()}<input type="hidden" name="action" value="delete"><button class="danger-button">Supprimer</button></form></div></article>''' for row in rows)
        content = f'''<main class="admin-main"><div class="admin-title-row"><div><span class="eyebrow">Modération</span><h1>Commentaires</h1><p>Les commentaires restent invisibles jusqu’à leur approbation.</p></div></div><section class="comments-admin-list">{items or '<div class="admin-empty">Aucun commentaire reçu.</div>'}</section></main>'''
        self.send_body(self.admin_page('Commentaires', content, 'comments'))

    def comment_action(self, comment_id: str, form: dict[str, str]) -> None:
        with connect() as db:
            if form.get('action') == 'approve':
                db.execute("UPDATE comments SET status='approved' WHERE id=?", (comment_id,))
            else:
                db.execute('DELETE FROM comments WHERE id=?', (comment_id,))
        return self.redirect('/admin/comments')


class MultiFields(dict):
    def __init__(self, values: dict[str, str], multi: dict[str, list[str]]) -> None:
        super().__init__(values)
        self._multi = multi

    def getlist(self, key: str) -> list[str]:
        return self._multi.get(key, [])


def run() -> None:
    initialize()
    # Prépare les traductions manquantes (accueil, thématiques, publications) pour que le changement de langue soit immédiat.
    pretranslate_in_background()
    host, port = '127.0.0.1', int(os.environ.get('PORT', '8000'))
    server = ThreadingHTTPServer((host, port), AppHandler)
    print(f'I-Dialogos est disponible sur http://{host}:{port}')
    print('Au premier lancement, ouvrez /admin/setup pour créer le compte administrateur.')
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print('\nArrêt du serveur.')
    finally:
        server.server_close()


if __name__ == '__main__':
    run()
