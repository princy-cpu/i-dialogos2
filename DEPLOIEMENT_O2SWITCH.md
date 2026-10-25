# Mise en production chez o2switch (offre unique)

L'application et le CI/CD restent les mêmes : seul l'hébergeur change. Ce guide part du site déjà installé chez LWS
et le transfère chez o2switch, données et images comprises.

Dans les commandes, remplacer :

| Repère | Valeur | Où la trouver |
|---|---|---|
| `IDENTIFIANT` | identifiant cPanel, par exemple `idia1234` | e-mail de bienvenue o2switch |
| `SERVEUR` | nom du serveur, par exemple `exemple.o2switch.net` | e-mail de bienvenue, ou cPanel > colonne de droite « Informations générales » |
| `IP_O2SWITCH` | adresse IP du serveur, par exemple `109.234.160.10` | même endroit (« Adresse IP partagée ») |

Ce qu'o2switch change par rapport à LWS :
- le dossier du site s'appelle `public_html` (et non `htdocs`) ;
- une **clé SSH** est possible : GitHub n'a plus besoin du mot de passe ;
- le pare-feu n'accepte en SSH que les adresses IP autorisées (5 au maximum). Le CI/CD autorise lui-même l'adresse de
  GitHub le temps du déploiement, grâce à un jeton d'API cPanel, puis la retire ;
- la base PostgreSQL est en version 9.6, testée par la CI à chaque modification ; elle est sur le serveur
  lui-même (`localhost`).

---

## 1. Commander l'hébergement

1. Commander l'offre unique sur o2switch.fr.
2. À l'étape du nom de domaine, choisir **« J'ai déjà un nom de domaine »** et saisir `i-dialogos.com`. Rien n'est
   transféré : le domaine reste chez son prestataire actuel, et le site ne sera visible à cette adresse qu'après la
   modification des DNS (étape 12).
3. Garder précieusement l'e-mail de bienvenue : adresse du cPanel, `IDENTIFIANT`, mot de passe, `SERVEUR`, `IP_O2SWITCH`.

## 2. Autoriser ton ordinateur en SSH

cPanel > section **Outils** (ou recherche) > **Autorisation SSH** :
1. ton adresse IP actuelle est proposée : port **22**, puis **Ajouter** ;
2. attendre une minute, puis tester dans PowerShell :

```powershell
ssh IDENTIFIANT@SERVEUR "echo connexion-ok; php -v | head -1; command -v psql"
```

Accepter l'empreinte (`yes`), puis taper le mot de passe cPanel. Le résultat attendu : `connexion-ok`, une version de PHP,
et un chemin vers `psql`. Si tu changes de réseau (box, 4G, autre lieu), ton adresse change : la rajouter dans cet outil.
Une erreur « Connection timed out » veut dire que l'adresse n'est pas autorisée.

## 3. Choisir la version de PHP

cPanel > **Sélectionner une version de PHP** (selon l'interface : « Select PHP Version » ou « MultiPHP Manager ») :
1. version **8.3** ;
2. dans les extensions, cocher **pdo_pgsql**, **pgsql**, **mbstring**, **curl**, **intl** (les autres cochées par défaut
   peuvent rester), puis enregistrer.

## 4. Créer la base PostgreSQL

cPanel > **Bases de données PostgreSQL** :
1. **Créer une base** : `idialogos`. cPanel ajoute ton identifiant devant : la base s'appelle `IDENTIFIANT_idialogos`.
2. **Ajouter un utilisateur** : `idialogos`, devenu `IDENTIFIANT_idialogos`, avec un mot de passe long fait seulement
   de lettres et de chiffres (sinon il faut encoder les caractères spéciaux dans l'adresse de la base).
3. **Ajouter l'utilisateur à la base**, avec **tous les privilèges**.

L'adresse de la base, utilisée plus bas :

```
postgresql://IDENTIFIANT_idialogos:MOT_DE_PASSE_BASE@localhost:5432/IDENTIFIANT_idialogos
```

## 5. Clé SSH pour GitHub

Dans PowerShell, sur ton PC. Créer une clé réservée au déploiement (appuyer deux fois sur Entrée pour ne pas mettre de
phrase de passe, GitHub ne pourrait pas la taper) :

```powershell
ssh-keygen -t ed25519 -C "github-actions-idialogos" -f "$HOME\.ssh\idialogos_o2switch"
```

L'installer sur le serveur (mot de passe cPanel demandé une dernière fois) :

```powershell
cmd /c "ssh IDENTIFIANT@SERVEUR ""mkdir -p ~/.ssh && chmod 700 ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"" < %USERPROFILE%\.ssh\idialogos_o2switch.pub"
```

Vérifier que la clé suffit (aucun mot de passe ne doit être demandé) :

```powershell
ssh -i "$HOME\.ssh\idialogos_o2switch" IDENTIFIANT@SERVEUR "echo cle-ok"
```

Relever l'empreinte du serveur pour GitHub (garder les lignes affichées) :

```powershell
Get-Content "$HOME\.ssh\known_hosts" | Select-String "SERVEUR"
```

## 6. Jeton d'API cPanel

Il permet à GitHub d'autoriser sa propre adresse IP dans le pare-feu SSH, le temps d'un déploiement.

cPanel > section **Sécurité** > **Gérer les jetons d'API** (« Manage API Tokens ») > **Créer** : nom `github-actions`,
sans date d'expiration. **Copier le jeton tout de suite** : il ne sera plus jamais affiché.

Dans l'outil **Autorisation SSH**, garder au moins une place libre sur les 5 pour GitHub.

## 7. Réglages du site (`app.env`)

Générer un nouveau jeton de déploiement (PowerShell) et le garder pour GitHub :

```powershell
-join ((48..57) + (65..90) + (97..122) | Get-Random -Count 40 | ForEach-Object { [char]$_ })
```

Créer le fichier sur le serveur, en remplaçant le mot de passe de la base et le jeton :

```powershell
ssh -i "$HOME\.ssh\idialogos_o2switch" IDENTIFIANT@SERVEUR "mkdir -p ~/public_html/.app/shared && printf '%s\n' 'DATABASE_URL=postgresql://IDENTIFIANT_idialogos:MOT_DE_PASSE_BASE@localhost:5432/IDENTIFIANT_idialogos' 'DEPLOY_TOKEN=LE_NOUVEAU_JETON' > ~/public_html/.app/shared/app.env && chmod 600 ~/public_html/.app/shared/app.env && cat ~/public_html/.app/shared/app.env"
```

Si le fichier `app.env` de LWS contient d'autres réglages (par exemple `DEEPL_API_KEY=...`), les ajouter de la même façon
avec `printf '%s\n' 'DEEPL_API_KEY=...' >> ~/public_html/.app/shared/app.env`. Pour les voir chez LWS :
`ssh i-dia2811273@185.98.131.155 "sed -n p htdocs/.app/shared/app.env"`.

## 8. Transférer les données depuis LWS

Dans PowerShell, dans un dossier de travail (par exemple le dossier du projet).

**a. Sauvegarde fraîche faite par le site LWS** (avec le `DEPLOY_TOKEN` de LWS, celui qui est encore dans GitHub) :

```powershell
curl.exe -sS -X POST -H "X-Deploy-Token: JETON_LWS" --resolve i-dialogos.com:80:185.98.131.155 "http://i-dialogos.com/_ops/backup?label=transfert"
```

La réponse donne le nom du fichier : `{"status":"ok","file":"20261025-101500-transfert.sql.gz",...}`.

**b. La télécharger** (remplacer `FICHIER` par ce nom) :

```powershell
cmd /c "ssh i-dia2811273@185.98.131.155 tar -cf - -C htdocs/.app/backups FICHIER > sauvegarde.tar"
tar -xf sauvegarde.tar
```

**c. L'importer chez o2switch** :

```powershell
scp -i "$HOME\.ssh\idialogos_o2switch" FICHIER IDENTIFIANT@SERVEUR:
ssh -i "$HOME\.ssh\idialogos_o2switch" IDENTIFIANT@SERVEUR "gunzip -c FICHIER | psql -q -v ON_ERROR_STOP=1 'postgresql://IDENTIFIANT_idialogos:MOT_DE_PASSE_BASE@localhost:5432/IDENTIFIANT_idialogos' && rm FICHIER"
```

Vérifier :

```powershell
ssh -i "$HOME\.ssh\idialogos_o2switch" IDENTIFIANT@SERVEUR "psql -tA 'postgresql://IDENTIFIANT_idialogos:MOT_DE_PASSE_BASE@localhost:5432/IDENTIFIANT_idialogos' -c 'SELECT COUNT(*) FROM articles' -c 'SELECT COUNT(*) FROM translations'"
```

Les nombres doivent être ceux du site LWS (au moins 234 articles et 2602 traductions).

S'il n'y a pas de `psql` sur le serveur : décompresser le fichier `.sql.gz` avec 7-Zip et l'importer dans **phpPgAdmin**
(cPanel > phpPgAdmin > base `IDENTIFIANT_idialogos` > SQL), connecté avec l'utilisateur `IDENTIFIANT_idialogos`.

**d. Les images**, depuis le dossier du projet sur ton PC (ou depuis LWS :
`cmd /c "ssh i-dia2811273@185.98.131.155 tar -cf - -C htdocs uploads > uploads.tar"`) :

```powershell
tar -cf uploads.tar uploads
scp -i "$HOME\.ssh\idialogos_o2switch" uploads.tar IDENTIFIANT@SERVEUR:
ssh -i "$HOME\.ssh\idialogos_o2switch" IDENTIFIANT@SERVEUR "tar -xf uploads.tar -C public_html && rm uploads.tar && ls public_html/uploads | wc -l"
```

Le nombre affiché doit être proche de 822.

## 9. Configurer GitHub pour o2switch

Dépôt `princy-cpu/i-dialogos2` > **Settings** > **Secrets and variables** > **Actions**.

**Onglet Secrets** :

| Secret | Action | Valeur |
|---|---|---|
| `SSH_PASSWORD` | **supprimer** (sinon GitHub continue d'utiliser un mot de passe) | |
| `SSH_PRIVATE_KEY` | créer | tout le contenu de la clé privée, lignes `-----BEGIN` et `-----END` comprises. Pour la copier : `Get-Content "$HOME\.ssh\idialogos_o2switch" -Raw \| Set-Clipboard` |
| `SSH_KNOWN_HOSTS` | modifier (**Update**) | les lignes relevées à l'étape 5 |
| `DEPLOY_TOKEN` | modifier | le nouveau jeton de l'étape 7 |
| `CPANEL_API_TOKEN` | créer | le jeton d'API cPanel de l'étape 6 |

**Onglet Variables** (bouton crayon pour modifier) :

| Variable | Valeur |
|---|---|
| `SSH_HOST` | `SERVEUR` (exactement le même nom qu'à l'étape 5) |
| `SSH_USER` | `IDENTIFIANT` |
| `SSH_PORT` | `22` |
| `PROD_DEPLOY_PATH` | `public_html` |
| `PROD_SITE_URL` | `http://i-dialogos.com` |
| `PROD_SITE_IP` | `IP_O2SWITCH` (à supprimer après la modification des DNS) |

À partir de là, les déploiements vont chez o2switch. Le site LWS reste tel quel, mais n'est plus mis à jour.

## 10. Première mise en ligne

Actions > **CI/CD** > **Run workflow** : branche `main`, environnement `production`, cocher **Première mise en place**.

Dans le job **Déploiement production**, on doit voir dans l'ordre :
1. `Autorisation de l'adresse … sur le port 22` ;
2. `Syntaxe PHP vérifiée (8.…)` ;
3. `Sauvegarde impossible …, poursuite autorisée (première mise en place)` : normal, le site n'existe pas encore ;
4. `Ancien contenu du site mis de côté dans …` : la page d'attente d'o2switch ;
5. `Version … en ligne` ;
6. `{"status":"ok","applied":[],...}` : la base importée est déjà à jour ;
7. `Contrôle de santé réussi` ;
8. `Autorisation de … retirée.`

## 11. Vérifier avant la modification des DNS

Dans le fichier `C:\Windows\System32\drivers\etc\hosts` (Bloc-notes lancé en administrateur), remplacer la ligne LWS par :

```
IP_O2SWITCH  i-dialogos.com  www.i-dialogos.com
```

Puis `ipconfig /flushdns`, fermer et rouvrir le navigateur, et vérifier en `http://` : `/healthz`, la page d'accueil et
les images, un article, le changement de langue, `/admin/login`.

## 12. Faire pointer le domaine vers o2switch

Chez le prestataire actuel du domaine (accès détenu par l'ancien collaborateur), dans la zone DNS de `i-dialogos.com` :

| Type | Nom | Valeur |
|---|---|---|
| A | `@` | `IP_O2SWITCH` |
| A | `www` | `IP_O2SWITCH` |

Supprimer les autres enregistrements A ou AAAA de `@` et `www`. Ne pas toucher aux enregistrements MX (e-mails).
La prise en compte prend de quelques minutes à quelques heures. Retirer ensuite la ligne du fichier hosts.

## 13. HTTPS et réglages finaux

1. Le certificat Let's Encrypt est installé automatiquement par o2switch une fois les DNS pris en compte (cPanel >
   **Let's Encrypt SSL** pour vérifier, ou le demander). Tester `https://i-dialogos.com/healthz`.
2. Rediriger tout le trafic vers HTTPS : cPanel > **Domaines** > activer **Force HTTPS Redirect** pour `i-dialogos.com`.
3. Cookies réservés au HTTPS :

```powershell
ssh -i "$HOME\.ssh\idialogos_o2switch" IDENTIFIANT@SERVEUR "printf '%s\n' 'COOKIE_SECURE=1' >> ~/public_html/.app/shared/app.env"
```

4. GitHub > Variables : `PROD_SITE_URL` = `https://i-dialogos.com`, et **supprimer** `PROD_SITE_IP`.
5. Relancer un déploiement (Actions > CI/CD > Run workflow, sans cocher « Première mise en place ») : il doit être vert,
   avec cette fois la ligne de sauvegarde de la base.

Ensuite, tout fonctionne comme décrit dans [CICD.md](CICD.md) : pull request, merge, publication d'une version.
L'hébergement LWS peut être résilié une fois le site vérifié chez o2switch.

## Dépannage

| Symptôme | Cause probable |
|---|---|
| `Autorisation SSH refusée par o2switch` | `CPANEL_API_TOKEN` faux, ou plus de place libre dans l'outil Autorisation SSH (5 au maximum) |
| `Envoi impossible : connexion SSH refusée` | `SSH_PRIVATE_KEY` incomplète, `SSH_PASSWORD` encore présent, ou clé publique absente de `~/.ssh/authorized_keys` |
| `Host key verification failed` | `SSH_KNOWN_HOSTS` relevé avec un autre nom que `SSH_HOST` |
| `Contrôle de santé en échec` avec « could not find driver » | extension `pdo_pgsql` non cochée (étape 3) |
| `Contrôle de santé en échec` avec une erreur de connexion à la base | adresse de la base ou mot de passe faux dans `app.env`, ou utilisateur non ajouté à la base (étape 4) |
