"""Exporte la base PostgreSQL locale en fichiers SQL à importer dans PhpPgAdmin (base PostgreSQL de LWS).

À utiliser quand la base de destination n'accepte pas les connexions depuis l'extérieur (cas de LWS).

    $env:DATABASE_URL = "postgresql://postgres:mot_de_passe@localhost:5432/idialogos"
    python export_postgres_sql.py                    # crée le dossier export_lws/
    python export_postgres_sql.py --taille 500       # fichiers plus petits (en kilo-octets)

Les fichiers sont à exécuter DANS L'ORDRE (01, 02, 03...) : le premier crée les tables et VIDE celles qui existent,
les suivants ajoutent les données, le dernier recale les compteurs d'identifiants.
Chaque fichier est une transaction : s'il échoue, rien n'est écrit, on peut le relancer.
"""
from __future__ import annotations

import argparse
import os
import sys
from pathlib import Path

if not os.environ.get('DATABASE_URL', '').lower().startswith(('postgres://', 'postgresql://')):
    sys.exit('Indiquez la base PostgreSQL source dans DATABASE_URL (postgresql://…).')

from psycopg import sql  # noqa: E402

import server  # noqa: E402

TABLES = ['users', 'languages', 'templates', 'categories', 'tags', 'articles', 'article_categories',
          'article_tags', 'comments', 'news_events', 'translations', 'schema_version']
SERIAL_TABLES = ['users', 'templates', 'categories', 'tags', 'comments', 'news_events']
HEADER = "SET client_encoding = 'UTF8';\nBEGIN;\n"
FOOTER = 'COMMIT;\n'


class SchemaRecorder:
    """Se fait passer pour une connexion afin de récupérer les CREATE/ALTER de l'application, sans les exécuter."""

    def __init__(self) -> None:
        self.statements: list[str] = []

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False

    def execute(self, query: str, params=()):
        text = ' '.join(query.split())
        if text.upper().startswith(('CREATE', 'ALTER')):
            self.statements.append(text)
        return self

    def fetchone(self):
        return {'total': 1, 'version': 0}


def schema_statements() -> list[str]:
    recorder = SchemaRecorder()
    real_connect = server.connect
    server.connect = lambda: recorder
    try:
        server.create_baseline()
    finally:
        server.connect = real_connect
    recorder.statements.append('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER PRIMARY KEY, '
                               'description VARCHAR(255) NOT NULL, applied_at VARCHAR(40) NOT NULL)')
    return recorder.statements


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--dossier', default='export_lws')
    parser.add_argument('--taille', type=int, default=900, help='taille maximale de chaque fichier, en kilo-octets')
    args = parser.parse_args()
    limit = args.taille * 1024
    folder = Path(args.dossier)
    folder.mkdir(exist_ok=True)
    for old in folder.glob('*.sql'):
        old.unlink()

    parts: list[str] = []
    first = HEADER + ''.join(s + ';\n' for s in schema_statements())
    first += 'TRUNCATE ' + ', '.join(['sessions', *TABLES]) + ' RESTART IDENTITY CASCADE;\n' + FOOTER
    parts.append(first)

    totals: dict[str, int] = {}
    with server.connect() as db:
        connection = db.connection
        existing = {r['table_name'] for r in db.execute(
            "SELECT table_name FROM information_schema.tables WHERE table_schema='public'").fetchall()}
        current, size = [], 0
        for table in [t for t in TABLES if t in existing]:
            rows = db.execute(f'SELECT * FROM {table}').fetchall()
            totals[table] = len(rows)
            for row in rows:
                columns = sql.SQL(', ').join(sql.Identifier(c) for c in row)
                values = sql.SQL(', ').join(sql.Literal(v) for v in row.values())
                statement = sql.SQL('INSERT INTO {} ({}) VALUES ({});\n').format(sql.Identifier(table), columns, values).as_string(connection)
                if current and size + len(statement.encode()) > limit:
                    parts.append(HEADER + ''.join(current) + FOOTER)
                    current, size = [], 0
                current.append(statement)
                size += len(statement.encode())
        if current:
            parts.append(HEADER + ''.join(current) + FOOTER)

    last = HEADER
    for table in SERIAL_TABLES:
        last += f"SELECT setval(pg_get_serial_sequence('{table}', 'id'), COALESCE((SELECT MAX(id) FROM {table}), 0) + 1, false);\n"
    parts.append(last + FOOTER)

    width = max(2, len(str(len(parts))))
    for index, content in enumerate(parts, start=1):
        (folder / f'{index:0{width}d}_idialogos.sql').write_text(content, encoding='utf-8', newline='\n')

    for table, total in totals.items():
        print(f'{table:20} {total:6} ligne(s)')
    print(f'\n{len(parts)} fichiers créés dans {folder.resolve()}')
    print('Dans PhpPgAdmin : base > SQL > envoyer chaque fichier dans l\'ordre (01, 02...) > Exécuter.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
