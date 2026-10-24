"""Crée les tables manquantes et applique les migrations en attente (lancé à chaque déploiement).

    python migrate.py            # applique
    python migrate.py --statut   # affiche la version de la base sans rien modifier
"""
from __future__ import annotations

import sys
from pathlib import Path

import envfile

envfile.load(Path(__file__).resolve().parent)

import server  # noqa: E402


def main() -> int:
    if '--statut' in sys.argv:
        with server.connect() as db:
            try:
                current = server.schema_version(db)
            except Exception:  # noqa: BLE001 (table absente : base jamais migrée)
                current = 0
        latest = max(version for version, _, _ in server.MIGRATIONS)
        print(f'Base {server.DB_KIND} : version {current}, dernière migration disponible : {latest}')
        return 0 if current == latest else 1
    server.create_baseline()
    applied = server.apply_migrations()
    print('Migrations appliquées : ' + (', '.join(map(str, applied)) if applied else 'aucune (base à jour)'))
    return 0


if __name__ == '__main__':
    sys.exit(main())
