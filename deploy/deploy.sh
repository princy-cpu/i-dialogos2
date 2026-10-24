#!/usr/bin/env bash
# Déploie le commit courant sur un environnement LWS, puis vérifie /healthz et revient en arrière si besoin.
# Utilisé par GitHub Actions ; utilisable aussi à la main (Git Bash ou Linux) en dépannage.
#
#   deploy/deploy.sh deploy     # déploie le commit courant
#   deploy/deploy.sh rollback   # remet la version précédente
#
# Variables attendues :
#   SSH_HOST, SSH_USER         serveur et compte cPanel
#   SSH_PORT                   facultatif (22 par défaut)
#   SSH_KEY_FILE               facultatif : clé privée à utiliser
#   DEPLOY_PATH                dossier du site dans le compte, ex. idialogos-prod
#   SITE_URL                   adresse publique, ex. https://preprod.exemple.fr
#   VENV_PATH                  facultatif : environnement Python si la détection automatique échoue
#   HEALTH_TRIES               facultatif : nombre d'essais du contrôle de santé (20 par défaut)
set -euo pipefail

ACTION="${1:-deploy}"
: "${SSH_HOST:?SSH_HOST manquant}" "${SSH_USER:?SSH_USER manquant}" "${DEPLOY_PATH:?DEPLOY_PATH manquant}" "${SITE_URL:?SITE_URL manquant}"
SSH_PORT="${SSH_PORT:-22}"
HERE="$(cd "$(dirname "$0")" && pwd)"
SSH_OPTS=(-p "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=20 -o ServerAliveInterval=15)
if [ -n "${SSH_KEY_FILE:-}" ]; then SSH_OPTS+=(-i "$SSH_KEY_FILE"); fi
TARGET="$SSH_USER@$SSH_HOST"

remote() { # remote <script local> <arguments...> : exécute un script local sur le serveur
  local script="$1"; shift
  ssh "${SSH_OPTS[@]}" "$TARGET" "KEEP_RELEASES='${KEEP_RELEASES:-5}' VENV_PATH='${VENV_PATH:-}' bash -s -- $(printf '%q ' "$@")" < "$script"
}

health() { # health [commit attendu] : attend que /healthz réponde (et annonce la bonne version)
  local expected="${1:-}" tries="${HEALTH_TRIES:-20}" body=""
  for attempt in $(seq 1 "$tries"); do
    body="$(curl -fsS --max-time 15 -H 'Cache-Control: no-cache' "${SITE_URL%/}/healthz" 2>/dev/null || true)"
    if [ -n "$body" ] && printf '%s' "$body" | grep -q '"status": "ok"'; then
      if [ -z "$expected" ] || printf '%s' "$body" | grep -q "\"version\": \"$expected\""; then
        echo "Contrôle de santé réussi (essai $attempt) : $body"
        return 0
      fi
    fi
    sleep 3
  done
  echo "Contrôle de santé en échec après $tries essais. Dernière réponse : ${body:-aucune}" >&2
  return 1
}

case "$ACTION" in
  deploy)
    SHA="$(git rev-parse HEAD)"
    ARCHIVE="release-${SHA:0:12}.tar.gz"
    git archive --format=tar.gz -o "/tmp/$ARCHIVE" HEAD
    echo "Archive : $(du -h "/tmp/$ARCHIVE" | cut -f1) (commit ${SHA:0:8})"
    # Envoi par le canal SSH lui-même (ne dépend pas de SFTP, parfois désactivé sur les hébergements mutualisés).
    ssh "${SSH_OPTS[@]}" "$TARGET" "mkdir -p ~/$(printf '%q' "$DEPLOY_PATH")/releases && cat > ~/$(printf '%q' "$DEPLOY_PATH")/releases/$ARCHIVE" < "/tmp/$ARCHIVE"
    rm -f "/tmp/$ARCHIVE"
    remote "$HERE/remote_deploy.sh" "$DEPLOY_PATH" "$ARCHIVE" "$SHA"
    if ! health "$SHA"; then
      echo "Retour automatique à la version précédente." >&2
      rollback_to="$(remote "$HERE/remote_rollback.sh" "$DEPLOY_PATH" | tee /dev/stderr | sed -n 's/^VERSION=//p' || true)"
      health "$rollback_to" || echo "ATTENTION : le site ne répond pas non plus après le retour arrière." >&2
      exit 1
    fi
    ;;
  rollback)
    rollback_to="$(remote "$HERE/remote_rollback.sh" "$DEPLOY_PATH" | tee /dev/stderr | sed -n 's/^VERSION=//p')"
    health "$rollback_to"
    ;;
  *)
    echo "Action inconnue : $ACTION (deploy ou rollback)" >&2; exit 2 ;;
esac
