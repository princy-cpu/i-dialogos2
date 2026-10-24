#!/usr/bin/env bash
# Remet en ligne la version précédente (exécuté sur LWS via SSH).
#
#   bash remote_rollback.sh <dossier_du_site>
#
# Seul le code revient en arrière. La base reste dans son état actuel : les migrations doivent donc
# rester compatibles avec la version précédente (ajouter des colonnes plutôt qu'en supprimer).
# En cas de besoin, une sauvegarde de la base faite avant chaque déploiement est dans backups/.
set -euo pipefail

SITE="$HOME/${1:?dossier du site manquant}"
STAMP="$(date +%Y%m%d-%H%M%S)"
PREVIOUS="$(ls -d "$SITE/releases/prev-"* 2>/dev/null | sort | tail -n 1 || true)"

if [ -z "$PREVIOUS" ]; then
  echo "Aucune version précédente disponible dans $SITE/releases." >&2
  exit 1
fi
FROM="$(cat "$SITE/app/VERSION" 2>/dev/null || echo inconnue)"
TO="$(cat "$PREVIOUS/VERSION" 2>/dev/null || echo inconnue)"
if [ -d "$SITE/app" ]; then
  mv "$SITE/app" "$SITE/releases/failed-$STAMP"
fi
mv "$PREVIOUS" "$SITE/app"
# Version installée à la main avant la mise en place du déploiement automatique : images partagées.
if [ -d "$SITE/app/uploads" ] && [ ! -L "$SITE/app/uploads" ]; then
  cp -an "$SITE/app/uploads/." "$SITE/shared/uploads/" && rm -rf "$SITE/app/uploads"
  ln -s "$SITE/shared/uploads" "$SITE/app/uploads"
fi
mkdir -p "$SITE/app/tmp"
touch "$SITE/app/tmp/restart.txt"
printf '%s %s %s\n' "$STAMP" "$TO" "rollback-from-${FROM:0:8}" >> "$SITE/deploy.log"
# On garde seulement les 3 dernières versions écartées, pour analyse.
ls -d "$SITE/releases/failed-"* 2>/dev/null | sort | head -n -3 | xargs -r rm -rf || true
echo "Retour à la version ${TO:0:8} (version écartée : ${FROM:0:8})."
echo "VERSION=$TO"
