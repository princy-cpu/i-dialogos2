"""Lecture du fichier de configuration du serveur (shared/app.env), hors du code déployé.

Format : une variable par ligne, `NOM=valeur` (les lignes vides et celles commençant par # sont ignorées,
des guillemets autour de la valeur sont acceptés). Une variable déjà définie dans l'environnement
(par exemple dans « Setup Python App ») est prioritaire sur le fichier.

Emplacement : variable APP_ENV_FILE, sinon `../shared/app.env` à côté du dossier de l'application.
"""
from __future__ import annotations

import os
from pathlib import Path


def env_file_path(app_root: Path) -> Path:
    custom = os.environ.get('APP_ENV_FILE', '').strip()
    return Path(custom) if custom else app_root.parent / 'shared' / 'app.env'


def parse(text: str) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        name, value = line.split('=', 1)
        name, value = name.strip(), value.strip()
        if name.startswith('export '):
            name = name[len('export '):].strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in '"\'':
            value = value[1:-1]
        values[name] = value
    return values


def load(app_root: Path) -> Path | None:
    """Charge le fichier s'il existe. Renvoie son chemin, ou None s'il est absent."""
    path = env_file_path(app_root)
    if not path.is_file():
        return None
    for name, value in parse(path.read_text(encoding='utf-8')).items():
        os.environ.setdefault(name, value)
    return path
