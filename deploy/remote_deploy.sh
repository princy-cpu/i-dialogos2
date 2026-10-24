#!/usr/bin/env bash
# Installe une nouvelle version sur le serveur (exécuté sur LWS par deploy/deploy.sh, via SSH).
#
#   bash remote_deploy.sh <dossier_du_site> <archive.tar.gz (dans releases/)> <commit>
#
# Organisation du dossier du site (par exemple ~/idialogos-prod) :
#   app/              dossier de l'application déclaré dans Setup Python App (version en ligne)
#   shared/app.env    réglages du serveur (DATABASE_URL...), jamais dans Git
#   shared/uploads/   images téléversées, conservées d'une version à l'autre
#   releases/         versions précédentes (pour le retour arrière) et version en préparation
#   backups/          sauvegardes de la base faites avant chaque déploiement
#   deploy.log        historique des déploiements
set -euo pipefail

SITE="$HOME/${1:?dossier du site manquant}"
ARCHIVE="${2:?archive manquante}"
SHA="${3:?commit manquant}"
case "$ARCHIVE" in /*) ;; *) ARCHIVE="$SITE/releases/$ARCHIVE" ;; esac
KEEP_RELEASES="${KEEP_RELEASES:-5}"
STAMP="$(date +%Y%m%d-%H%M%S)"
SHORT="${SHA:0:8}"
NEW="$SITE/releases/new-$STAMP-$SHORT"

log() { printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
fail() { log "ÉCHEC : $*"; rm -rf "$NEW"; exit 1; }

mkdir -p "$SITE/shared/uploads" "$SITE/releases" "$SITE/backups"
export APP_ENV_FILE="$SITE/shared/app.env"
[ -f "$APP_ENV_FILE" ] || fail "fichier $APP_ENV_FILE absent : le créer avec DATABASE_URL (voir CICD.md)."

# Environnement Python créé par Setup Python App : ~/virtualenv/<dossier>/app/<version>/
if [ -n "${VENV_PATH:-}" ]; then
  VENV="$HOME/${VENV_PATH#"$HOME"/}"
else
  VENV="$(ls -d "$HOME/virtualenv/${1}/app/"*/ 2>/dev/null | sort -V | tail -n 1 || true)"
  VENV="${VENV%/}"
fi
PYTHON="$VENV/bin/python"
[ -x "$PYTHON" ] || fail "environnement Python introuvable ($VENV). Créer l'application dans Setup Python App avec le dossier ${1}/app."

log "Déploiement de $SHORT dans $SITE"
mkdir -p "$NEW"
tar -xzf "$ARCHIVE" -C "$NEW" || fail "archive illisible"
rm -f "$ARCHIVE"
printf '%s\n' "$SHA" > "$NEW/VERSION"
mkdir -p "$NEW/tmp"

# Première mise en place : les images envoyées à la main dans app/uploads rejoignent shared/uploads.
if [ -d "$SITE/app/uploads" ] && [ ! -L "$SITE/app/uploads" ]; then
  log "Déplacement des images existantes vers shared/uploads"
  cp -an "$SITE/app/uploads/." "$SITE/shared/uploads/"
fi
rm -rf "$NEW/uploads"
ln -s "$SITE/shared/uploads" "$NEW/uploads"

log "Dépendances Python ($("$PYTHON" --version 2>&1))"
"$PYTHON" -m pip install --quiet --disable-pip-version-check -r "$NEW/requirements.txt" || fail "installation des dépendances"

log "Vérification du chargement de l'application"
(cd "$NEW" && "$PYTHON" -c "import envfile, server, passenger_wsgi") || fail "l'application ne se charge pas"

log "Sauvegarde de la base"
"$PYTHON" "$NEW/deploy/db_backup.py" "$SITE/backups/$STAMP-avant-$SHORT.sql.gz" --garder 10 || fail "sauvegarde de la base"

log "Migrations"
(cd "$NEW" && "$PYTHON" migrate.py) || fail "migrations (la base peut être restaurée depuis $SITE/backups)"

log "Mise en ligne"
if [ -d "$SITE/app" ]; then
  mv "$SITE/app" "$SITE/releases/prev-$STAMP"
fi
mv "$NEW" "$SITE/app"
touch "$SITE/app/tmp/restart.txt"
printf '%s %s %s\n' "$STAMP" "$SHA" "deploy" >> "$SITE/deploy.log"

# Ménage : on garde les KEEP_RELEASES versions précédentes les plus récentes.
ls -d "$SITE/releases/prev-"* 2>/dev/null | sort | head -n "-$KEEP_RELEASES" | xargs -r rm -rf || true
ls -d "$SITE/releases/new-"* 2>/dev/null | xargs -r rm -rf || true
log "Version $SHORT en ligne (redémarrage de Passenger demandé)"
