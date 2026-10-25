#!/bin/bash
# Sauvegarde de la base faite par la version en ligne chez LWS, sans passer par le web. Affiche le nom du fichier créé.
set -euo pipefail
cd "$HOME/htdocs/.app"
read -r version < current
php_bin=""
for candidate in /usr/bin/php8* /usr/local/bin/php8*; do
  [[ "${candidate##*/}" =~ ^php8(\.[0-9]+)?$ ]] && [ -x "$candidate" ] && php_bin="$candidate"
done
[ -n "$php_bin" ] || { echo "PHP 8 introuvable" >&2; exit 1; }
RELEASE="$PWD/releases/$version" "$php_bin" -r '
$release = getenv("RELEASE");
foreach (["config", "html", "db", "ops"] as $file) {
    require $release . "/src/" . $file . ".php";
}
echo basename(backup_database(db(), "transfert")), "\n";
'
