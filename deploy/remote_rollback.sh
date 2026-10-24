#!/usr/bin/env bash
# Remet en ligne la version précédente (exécuté sur le serveur via SSH).
#
#   bash remote_rollback.sh <dossier_web>
#
# Seul le code revient en arrière : la base reste dans son état actuel. Les migrations doivent donc rester
# compatibles avec la version précédente (ajouter des colonnes plutôt qu'en supprimer).
# Une sauvegarde de la base faite avant chaque déploiement est disponible dans .app/backups/.
# N'utilise que les fonctions intégrées de bash, cp et mv.
set -euo pipefail

WEB="$HOME/${1:?dossier web manquant}"
BASE="$WEB/.app"
printf -v STAMP '%(%Y%m%d-%H%M%S)T' -1
read_file() { local line=''; IFS= read -r line < "$1" || true; printf '%s' "$line"; }

CURRENT="$(read_file "$BASE/current" 2>/dev/null || true)"
[ -n "$CURRENT" ] || { echo "Aucune version en ligne." >&2; exit 1; }

# Version précédente : la plus récente de l'historique, différente de la version en ligne et encore présente.
mapfile -t history < "$BASE/history"
PREVIOUS=""
for (( i = ${#history[@]} - 1; i >= 0; i-- )); do
  candidate="${history[i]}"
  if [ "$candidate" != "$CURRENT" ] && [ -f "$BASE/releases/$candidate/src/front.php" ]; then
    PREVIOUS="$candidate"
    break
  fi
done
[ -n "$PREVIOUS" ] || { echo "Aucune version précédente disponible." >&2; exit 1; }

cp "$BASE/releases/$PREVIOUS/public/index.php" "$WEB/index.php.tmp-$STAMP" && mv -f "$WEB/index.php.tmp-$STAMP" "$WEB/index.php"
cp "$BASE/releases/$PREVIOUS/public/.htaccess" "$WEB/.htaccess.tmp-$STAMP" && mv -f "$WEB/.htaccess.tmp-$STAMP" "$WEB/.htaccess"
printf '%s\n' "$PREVIOUS" > "$BASE/current.tmp-$STAMP"
mv -f "$BASE/current.tmp-$STAMP" "$BASE/current"
# L'historique est réécrit sans la version écartée : un second retour arrière remonte encore d'un cran.
: > "$BASE/history.tmp-$STAMP"
for version in "${history[@]}"; do
  [ "$version" = "$CURRENT" ] || printf '%s\n' "$version" >> "$BASE/history.tmp-$STAMP"
done
mv -f "$BASE/history.tmp-$STAMP" "$BASE/history"
SHA="$(read_file "$BASE/releases/$PREVIOUS/VERSION")"
printf '%s %s %s\n' "$STAMP" "$SHA" "rollback-from-$CURRENT" >> "$BASE/deploy.log"
echo "Retour à la version $PREVIOUS (version écartée : $CURRENT)."
echo "VERSION=$SHA"
