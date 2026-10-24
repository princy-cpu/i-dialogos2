"""Exporte la base PostgreSQL locale vers un fichier SQL à importer dans MySQL (phpMyAdmin de LWS).

Utilisation (PowerShell, sur le poste où se trouve la base PostgreSQL) :

    $env:DATABASE_URL = "postgresql://postgres:mot_de_passe@localhost:5432/idialogos"
    python export_mysql.py                      # crée idialogos_mysql.sql
    python export_mysql.py --sortie autre.sql

Le fichier SUPPRIME puis recrée les tables du site dans la base MySQL où il est importé :
à importer dans la base de production vide (ou pour la remplacer entièrement).
Les sessions de connexion ne sont pas exportées (il faudra se reconnecter).
"""
from __future__ import annotations

import argparse
import os
import sys

if not os.environ.get('DATABASE_URL', '').lower().startswith(('postgres://', 'postgresql://')):
    sys.exit('Indiquez la base PostgreSQL source dans DATABASE_URL (postgresql://…).')

import pymysql.converters  # noqa: E402  (échappement des valeurs au format MySQL)

import server  # noqa: E402

# Ordre d'insertion compatible avec les clés étrangères.
TABLES = ['users', 'languages', 'templates', 'categories', 'tags', 'articles', 'article_categories',
          'article_tags', 'comments', 'news_events', 'translations']
DROP_ORDER = ['sessions', 'schema_version', *reversed(TABLES)]
BATCH_BYTES = 400_000


def literal(value: object) -> str:
    if value is None:
        return 'NULL'
    if isinstance(value, bool):
        return '1' if value else '0'
    if isinstance(value, (int, float)):
        return str(value)
    return pymysql.converters.escape_item(str(value), 'utf8mb4')


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--sortie', default='idialogos_mysql.sql', help='fichier SQL à créer')
    args = parser.parse_args()

    totals: dict[str, int] = {}
    with open(args.sortie, 'w', encoding='utf-8', newline='\n') as out, server.connect() as db:
        out.write('-- Export I-Dialogos : PostgreSQL vers MySQL\n')
        out.write('SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n')
        for table in DROP_ORDER:
            out.write(f'DROP TABLE IF EXISTS `{table}`;\n')
        for statement in server.MYSQL_SCHEMA.split(';'):
            if statement.strip():
                out.write(statement.strip() + ';\n')
        for table in TABLES:
            rows = db.execute(f'SELECT * FROM {table}').fetchall()
            totals[table] = len(rows)
            if not rows:
                continue
            columns = list(rows[0].keys())
            prefix = f'INSERT INTO `{table}` (' + ','.join(f'`{c}`' for c in columns) + ') VALUES\n'
            batch: list[str] = []
            size = 0
            for row in rows:
                values = '(' + ','.join(literal(row[c]) for c in columns) + ')'
                batch.append(values)
                size += len(values)
                if size >= BATCH_BYTES:
                    out.write(prefix + ',\n'.join(batch) + ';\n')
                    batch, size = [], 0
            if batch:
                out.write(prefix + ',\n'.join(batch) + ';\n')
        out.write('SET FOREIGN_KEY_CHECKS=1;\n')

    for table, total in totals.items():
        print(f'{table:20} {total:6} ligne(s)')
    print(f'\nFichier créé : {args.sortie} ({os.path.getsize(args.sortie) / 1_000_000:.1f} Mo)')
    print('Importez-le dans phpMyAdmin (onglet « Importer ») sur la base MySQL de production.')


if __name__ == '__main__':
    main()
