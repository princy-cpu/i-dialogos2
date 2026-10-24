#!/usr/bin/env bash
# Déploie le commit courant sur l'hébergement LWS, vérifie /healthz et revient en arrière en cas de problème.
# Utilisé par GitHub Actions ; utilisable aussi à la main (Git Bash ou Linux) en dépannage.
#
#   deploy/deploy.sh deploy     déploie le commit courant
#   deploy/deploy.sh rollback   remet la version précédente
#
# Variables :
#   SSH_HOST, SSH_USER    serveur et identifiant SSH (ex. 185.98.131.155, i-dia2811273)
#   SSH_PORT              facultatif (22 par défaut)
#   SSH_KEY_FILE          facultatif : clé privée à utiliser
#   SSH_PASSWORD          facultatif : mot de passe SSH, quand le serveur n'accepte pas de clé (offre LWS Starter)
#   DEPLOY_PATH           dossier web dans le compte, ex. htdocs
#   SITE_URL              adresse publique du site, ex. https://i-dialogos.com
#   DEPLOY_TOKEN          jeton partagé avec le serveur (même valeur que dans .app/shared/app.env)
#   SITE_IP               facultatif : adresse IP du serveur, pour joindre le site avant que le domaine pointe vers LWS
#   HEALTH_TRIES          facultatif : nombre d'essais du contrôle de santé (20 par défaut)
set -euo pipefail

ACTION="${1:-deploy}"
: "${SSH_HOST:?SSH_HOST manquant}" "${SSH_USER:?SSH_USER manquant}" "${DEPLOY_PATH:?DEPLOY_PATH manquant}" "${SITE_URL:?SITE_URL manquant}"
SSH_PORT="${SSH_PORT:-22}"
SITE_URL="${SITE_URL%/}"
HERE="$(cd "$(dirname "$0")" && pwd)"
SSH_OPTS=(-p "$SSH_PORT" -o ConnectTimeout=20 -o ServerAliveInterval=15)
SSH=(ssh)
if [ -n "${SSH_PASSWORD:-}" ]; then
  # Connexion par mot de passe : sshpass le fournit à ssh sans qu'il apparaisse dans la ligne de commande.
  command -v sshpass >/dev/null || { echo "sshpass est nécessaire pour la connexion par mot de passe." >&2; exit 1; }
  export SSHPASS="$SSH_PASSWORD"
  SSH=(sshpass -e ssh)
  # shellcheck disable=SC2054 # la virgule fait partie de la valeur de l option
  SSH_OPTS+=(-o PreferredAuthentications=password,keyboard-interactive -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1)
else
  SSH_OPTS+=(-o BatchMode=yes)
  if [ -n "${SSH_KEY_FILE:-}" ]; then SSH_OPTS+=(-i "$SSH_KEY_FILE"); fi
fi
TARGET="$SSH_USER@$SSH_HOST"

# Avant la bascule du domaine, les requêtes HTTP sont envoyées directement à l'adresse IP du serveur LWS.
CURL_OPTS=(-sS --max-time 120 -H 'Cache-Control: no-cache')
if [ -n "${SITE_IP:-}" ]; then
  host_port="${SITE_URL#*://}"; host_port="${host_port%%/*}"
  host="${host_port%%:*}"
  port="${host_port##*:}"; [ "$port" = "$host_port" ] && { [ "${SITE_URL%%://*}" = https ] && port=443 || port=80; }
  CURL_OPTS+=(--resolve "$host:$port:$SITE_IP")
fi

remote() { # remote <script local> <arguments...> : exécute un script local sur le serveur
  local script="$1"; shift
  "${SSH[@]}" "${SSH_OPTS[@]}" "$TARGET" "KEEP_RELEASES='${KEEP_RELEASES:-5}' bash -s -- $(printf '%q ' "$@")" < "$script"
}

ops() { # ops <backup|migrate> [paramètres] : opération de déploiement exécutée par l'application elle-même
  : "${DEPLOY_TOKEN:?DEPLOY_TOKEN manquant}"
  curl "${CURL_OPTS[@]}" -X POST -H "X-Deploy-Token: $DEPLOY_TOKEN" -w '\n%{http_code}' "$SITE_URL/_ops/$1${2:+?$2}"
}

health() { # health [commit attendu] : attend que /healthz réponde « ok » (et annonce la bonne version)
  local expected="${1:-}" tries="${HEALTH_TRIES:-20}" body=""
  for attempt in $(seq 1 "$tries"); do
    body="$(curl "${CURL_OPTS[@]}" --max-time 15 "$SITE_URL/healthz" 2>/dev/null || true)"
    if [[ "$body" == *'"status": "ok"'* ]] && { [ -z "$expected" ] || [[ "$body" == *"\"version\": \"$expected\""* ]]; }; then
      echo "Contrôle de santé réussi (essai $attempt) : $body"
      return 0
    fi
    sleep 3
  done
  echo "Contrôle de santé en échec après $tries essais. Dernière réponse : ${body:-aucune}" >&2
  return 1
}

rollback() {
  local output version
  output="$(remote "$HERE/remote_rollback.sh" "$DEPLOY_PATH")" || return 1
  echo "$output"
  version="${output##*VERSION=}"
  health "$version"
}

case "$ACTION" in
  deploy)
    SHA="$(git rev-parse HEAD)"
    SHORT="${SHA:0:8}"
    ARCHIVE="/tmp/release-$SHORT.tar.gz"
    git archive --format=tar.gz -o "$ARCHIVE" HEAD
    echo "Archive : $(du -h "$ARCHIVE" | cut -f1) (commit $SHORT)"
    # Envoi par le canal SSH, décompressé à la réception (ni SFTP ni « cat » ne sont nécessaires sur le serveur).
    incoming="$DEPLOY_PATH/.app/releases/incoming-$SHORT"
    if ! "${SSH[@]}" "${SSH_OPTS[@]}" "$TARGET" "rm -rf ~/$(printf '%q' "$incoming") && mkdir -p ~/$(printf '%q' "$incoming") && tar -xzf - -C ~/$(printf '%q' "$incoming")" < "$ARCHIVE"; then
      echo "Envoi impossible : connexion SSH refusée (identifiant, mot de passe ou empreinte du serveur) ou dossier $DEPLOY_PATH absent. Le site n'a pas changé." >&2
      exit 1
    fi
    rm -f "$ARCHIVE"

    if ! prepared="$(remote "$HERE/remote_deploy.sh" prepare "$DEPLOY_PATH" "incoming-$SHORT" "$SHA")"; then
      echo "$prepared" >&2
      echo "Version refusée avant mise en ligne : le site n'a pas changé." >&2
      exit 1
    fi
    echo "$prepared"
    VERSION="${prepared##*VERSION=}"

    echo "Sauvegarde de la base avant mise en ligne"
    backup="$(ops backup "label=avant-$SHORT" || true)"
    if [ "${backup##*$'\n'}" = 200 ]; then
      echo "${backup%$'\n'*}"
    elif [ "${ALLOW_NO_BACKUP:-0}" = 1 ]; then
      echo "Sauvegarde impossible (réponse : ${backup##*$'\n'}), poursuite autorisée (première mise en place)." >&2
    else
      echo "Sauvegarde impossible (HTTP ${backup##*$'\n'}) : déploiement interrompu, le site n'a pas changé." >&2
      echo "Pour la toute première mise en place, relancer avec ALLOW_NO_BACKUP=1." >&2
      exit 1
    fi

    remote "$HERE/remote_deploy.sh" activate "$DEPLOY_PATH" "$VERSION"

    echo "Migrations"
    migrated="$(ops migrate || true)"
    echo "${migrated%$'\n'*} (HTTP ${migrated##*$'\n'})"

    if ! health "$SHA"; then
      echo "Retour automatique à la version précédente." >&2
      rollback || echo "ATTENTION : le retour arrière n'a pas abouti ; vérifier le site immédiatement." >&2
      exit 1
    fi
    ;;
  rollback)
    rollback
    ;;
  *)
    echo "Action inconnue : $ACTION (deploy ou rollback)" >&2; exit 2 ;;
esac
