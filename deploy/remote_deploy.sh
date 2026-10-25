#!/usr/bin/env bash
# Installe une version sur l'hébergement mutualisé (LWS, o2switch...). Exécuté par deploy/deploy.sh via SSH.
#
#   bash remote_deploy.sh prepare  <dossier_web> <dossier_reçu> <commit>     valide la version reçue, sans la mettre en ligne
#   bash remote_deploy.sh activate <dossier_web> <version>                    met cette version en ligne
#
# Organisation (dossier_web = htdocs, la racine du site) :
#   htdocs/index.php, htdocs/.htaccess   chargeur et règles Apache (mis à jour à chaque déploiement)
#   htdocs/uploads/                      images téléversées, jamais touchées par un déploiement
#   htdocs/.app/current                  nom de la version en ligne (changer ce fichier bascule le site)
#   htdocs/.app/history                  versions mises en ligne successivement (pour le retour arrière)
#   htdocs/.app/releases/<version>/      code de chaque version (les 5 précédentes sont gardées)
#   htdocs/.app/shared/app.env           réglages : DATABASE_URL, DEPLOY_TOKEN... (jamais dans Git)
#   htdocs/.app/backups/                 sauvegardes de la base faites avant chaque déploiement
#
# Le serveur mutualisé ne fournit que peu de commandes : ce script s'appuie sur les fonctions intégrées de bash
# et seulement sur mkdir, cp, mv et rm (tar sert à la réception de l'archive, dans deploy.sh).
set -euo pipefail

ACTION="${1:?action manquante (prepare ou activate)}"
WEB="$HOME/${2:?dossier web manquant}"
BASE="$WEB/.app"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
printf -v STAMP '%(%Y%m%d-%H%M%S)T' -1

log() { local now; printf -v now '%(%H:%M:%S)T' -1; printf '[%s] %s\n' "$now" "$*"; }
fail() { log "ÉCHEC : $*"; exit 1; }
read_file() { local line=''; IFS= read -r line < "$1" || true; printf '%s' "$line"; }

[ -d "$WEB" ] || fail "dossier $WEB introuvable"
mkdir -p "$BASE/releases" "$BASE/shared" "$BASE/backups" "$WEB/uploads"
[ -f "$BASE/shared/app.env" ] || fail "fichier $BASE/shared/app.env absent : le créer avec DATABASE_URL et DEPLOY_TOKEN (voir CICD.md)."

case "$ACTION" in
  prepare)
    INCOMING="${3:?dossier reçu manquant}"
    SHA="${4:?commit manquant}"
    INCOMING="$BASE/releases/${INCOMING##*/}"
    [ -d "$INCOMING" ] || fail "version reçue introuvable ($INCOMING)"
    VERSION="$STAMP-${SHA:0:8}"
    TARGET="$BASE/releases/$VERSION"
    log "Préparation de la version $VERSION"
    mv "$INCOMING" "$TARGET"
    printf '%s\n' "$SHA" > "$TARGET/VERSION"
    for file in src/front.php public/index.php public/.htaccess public/app.htaccess public/uploads.htaccess index.html; do
      [ -f "$TARGET/$file" ] || { rm -rf "$TARGET"; fail "fichier $file absent de la version reçue"; }
    done
    # Programme PHP pour vérifier la syntaxe : version 8 ou plus. Sur LWS, « php » n'est qu'un alias du terminal
    # interactif ; sur cPanel (o2switch), la version de la ligne de commande peut différer de celle du site.
    PHP_BIN="${PHP_BIN:-}"
    if [ -z "$PHP_BIN" ]; then
      shopt -s nullglob
      for candidate in "$(command -v php 2>/dev/null || true)" /usr/bin/php[0-9]* /usr/local/bin/php[0-9]* \
          /opt/cpanel/ea-php8*/root/usr/bin/php /opt/alt/php8*/usr/bin/php; do
        [ -n "$candidate" ] && [ -x "$candidate" ] || continue
        [[ "$("$candidate" -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null)" =~ ^[89]$ ]] && PHP_BIN="$candidate"
      done
      shopt -u nullglob
    fi
    if [ -n "$PHP_BIN" ]; then
      for file in "$TARGET"/src/*.php "$TARGET"/public/index.php; do
        "$PHP_BIN" -l "$file" >/dev/null || { rm -rf "$TARGET"; fail "erreur de syntaxe PHP dans ${file#"$TARGET"/} : version non mise en ligne"; }
      done
      log "Syntaxe PHP vérifiée ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"
    else
      log "PHP 8 introuvable en ligne de commande : vérification de syntaxe sautée (le contrôle de santé reste actif)"
    fi
    echo "VERSION=$VERSION"
    ;;

  activate)
    VERSION="${3:?version manquante}"
    TARGET="$BASE/releases/$VERSION"
    [ -f "$TARGET/src/front.php" ] || fail "version $VERSION introuvable"

    # Bloc ajouté par cPanel (MultiPHP) dans le .htaccess pour choisir la version de PHP : conservé en tête du nouveau.
    handler=''
    if [ -f "$WEB/.htaccess" ]; then
      inside=0
      while IFS= read -r line || [ -n "$line" ]; do
        [[ "$line" == '# php -- BEGIN cPanel-generated handler'* ]] && inside=1
        if [ "$inside" = 1 ]; then handler+="$line"$'\n'; fi
        [[ "$line" == '# php -- END cPanel-generated handler'* ]] && inside=0
      done < "$WEB/.htaccess"
    fi

    # Première mise en place : l'ancien contenu du site (WordPress...) est mis de côté, hors d'atteinte du web.
    if [ ! -f "$BASE/current" ]; then
      OLD="$BASE/ancien-site-$STAMP"
      moved=0
      shopt -s dotglob nullglob
      for entry in "$WEB"/*; do
        case "${entry##*/}" in .app|uploads|.well-known) continue ;; esac
        mkdir -p "$OLD"
        mv "$entry" "$OLD/"
        moved=1
      done
      shopt -u dotglob nullglob
      if [ "$moved" = 1 ]; then log "Ancien contenu du site mis de côté dans $OLD"; fi
    fi

    # Fichiers de la racine du site, remplacés de façon atomique (copie à part puis renommage).
    install_file() { cp "$1" "$2.tmp-$STAMP" && mv -f "$2.tmp-$STAMP" "$2"; }
    install_file "$TARGET/public/app.htaccess" "$BASE/.htaccess"
    install_file "$TARGET/public/uploads.htaccess" "$WEB/uploads/.htaccess"
    install_file "$TARGET/public/index.php" "$WEB/index.php"
    {
      if [ -n "$handler" ]; then printf '%s\n' "$handler"; fi
      while IFS= read -r line || [ -n "$line" ]; do printf '%s\n' "$line"; done < "$TARGET/public/.htaccess"
    } > "$WEB/.htaccess.tmp-$STAMP"
    mv -f "$WEB/.htaccess.tmp-$STAMP" "$WEB/.htaccess"

    printf '%s\n' "$VERSION" > "$BASE/current.tmp-$STAMP"
    mv -f "$BASE/current.tmp-$STAMP" "$BASE/current"
    printf '%s\n' "$VERSION" >> "$BASE/history"
    printf '%s %s %s\n' "$STAMP" "$(read_file "$TARGET/VERSION")" "deploy" >> "$BASE/deploy.log"
    log "Version $VERSION en ligne"

    # Ménage : on garde la version en ligne et les KEEP_RELEASES précédentes de l'historique.
    mapfile -t history < "$BASE/history"
    declare -A keep=()
    for (( i = ${#history[@]} - 1, n = 0; i >= 0 && n <= KEEP_RELEASES; i-- )); do
      if [ -z "${keep[${history[i]}]:-}" ]; then keep[${history[i]}]=1; n=$((n + 1)); fi
    done
    shopt -s nullglob
    for release in "$BASE"/releases/*/; do
      name="${release%/}"; name="${name##*/}"
      [ -n "${keep[$name]:-}" ] || rm -rf "$release"
    done
    ;;

  *) fail "action inconnue : $ACTION" ;;
esac
