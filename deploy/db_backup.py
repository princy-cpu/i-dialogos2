"""Sauvegarde compressée de la base avant un déploiement (mysqldump ou pg_dump selon DATABASE_URL).

    python deploy/db_backup.py /chemin/sauvegarde.sql.gz [--garder 10]

Le mot de passe passe par un fichier temporaire (jamais sur la ligne de commande, visible des autres processus).
"""
from __future__ import annotations

import argparse
import gzip
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
from urllib.parse import unquote, urlparse

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

import envfile  # noqa: E402

envfile.load(ROOT)


def dump_command(url: str, workdir: str) -> tuple[list[str], dict[str, str]]:
    parsed = urlparse(url)
    env = dict(os.environ)
    if parsed.scheme.startswith(('mysql', 'mariadb')):
        options = Path(workdir) / 'client.cnf'
        password = unquote(parsed.password or '').replace('\\', '\\\\').replace('"', '\\"')
        options.write_text(f'[client]\nuser="{unquote(parsed.username or "")}"\npassword="{password}"\n'
                           f'host="{parsed.hostname or "localhost"}"\nport={parsed.port or 3306}\n', encoding='utf-8')
        options.chmod(0o600)
        return ([shutil.which('mysqldump') or 'mysqldump', f'--defaults-extra-file={options}', '--single-transaction',
                 '--routines', '--default-character-set=utf8mb4', '--no-tablespaces', unquote(parsed.path.lstrip('/'))], env)
    if parsed.password:
        env['PGPASSWORD'] = unquote(parsed.password)
    host = parsed.netloc.rsplit('@', 1)[-1]
    user = parsed.netloc.rsplit('@', 1)[0].split(':', 1)[0] if '@' in parsed.netloc else ''
    clean = parsed._replace(netloc=f'{user}@{host}' if user else host)  # adresse sans le mot de passe
    return ([shutil.which('pg_dump') or 'pg_dump', '--no-owner', clean.geturl()], env)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('destination')
    parser.add_argument('--garder', type=int, default=10, help='nombre de sauvegardes conservées dans le dossier')
    args = parser.parse_args()
    url = os.environ.get('DATABASE_URL', '').strip()
    if not url:
        print('DATABASE_URL absente : sauvegarde impossible.', file=sys.stderr)
        return 1
    destination = Path(args.destination)
    destination.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory() as workdir:
        command, env = dump_command(url, workdir)
        partial = destination.with_suffix(destination.suffix + '.part')
        with gzip.open(partial, 'wb') as out:
            process = subprocess.Popen(command, stdout=subprocess.PIPE, env=env)
            assert process.stdout is not None
            shutil.copyfileobj(process.stdout, out)
            if process.wait() != 0:
                partial.unlink(missing_ok=True)
                print('Échec de la sauvegarde de la base.', file=sys.stderr)
                return 1
        partial.rename(destination)
    destination.chmod(0o600)
    backups = sorted(destination.parent.glob('*.sql.gz'))
    for old in backups[:-args.garder] if args.garder > 0 else []:
        old.unlink()
    print(f'Sauvegarde : {destination} ({destination.stat().st_size / 1_000_000:.1f} Mo)')
    return 0


if __name__ == '__main__':
    sys.exit(main())
