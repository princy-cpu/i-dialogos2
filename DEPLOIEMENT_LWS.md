# Installation sur l'hébergement LWS (offre Starter)

L'offre Starter fournit **PHP**, **PostgreSQL** (base joignable uniquement depuis les serveurs de LWS) et un **accès SSH** très réduit. Le site tourne donc en PHP, avec la même base PostgreSQL qu'en local.

À faire une seule fois. Ensuite, chaque mise en ligne est automatique : voir [CICD.md](CICD.md).

## Repères

| Élément | Valeur |
|---|---|
| Accès SSH | `ssh i-dia2811273@185.98.131.155` (identifiants du compte FTP) |
| Racine du site | dossier `~/htdocs` (celui où arrive la connexion SSH) |
| Base PostgreSQL | base et utilisateur `idial2811273`, serveur `pgsql3001.lwspanel.com`, port 5432 |
| Réglages du site | `htdocs/.app/shared/app.env` (jamais accessible depuis le web) |

## 1. Version de PHP

Dans l'espace client LWS, rubrique de configuration PHP de l'hébergement : choisir **PHP 8.3** (8.0 au minimum).
Extensions nécessaires : `pdo_pgsql`, `mbstring`, `zlib` et `curl` (traduction automatique). Après la mise en ligne,
l'adresse `/healthz` indique ce qui manque éventuellement.

## 2. Base de données

Déjà faite : la base `idial2811273` contient les articles, les traductions et le compte administrateur.

Pour la remplir de nouveau à partir de la base locale : `python export_postgres_sql.py` sur le poste, puis dans PhpPgAdmin,
base `idial2811273` > **SQL** : exécuter les fichiers du dossier `export_lws` dans l'ordre (01, 02...).

## 3. Fichier de réglages

Générer d'abord un jeton de déploiement (PowerShell) et le garder pour GitHub (CICD.md) :

```powershell
-join ((48..57) + (97..122) | Get-Random -Count 48 | ForEach-Object { [char]$_ })
```

Puis, connecté en SSH, créer le fichier (les commandes `printf` et `mkdir` existent sur le serveur, `cat` non) :

```bash
mkdir -p ~/htdocs/.app/shared
printf '%s\n' \
  'DATABASE_URL=postgresql://idial2811273:MOT_DE_PASSE@pgsql3001.lwspanel.com:5432/idial2811273' \
  'DEPLOY_TOKEN=LE_JETON_GENERE' \
  > ~/htdocs/.app/shared/app.env
```

Si le mot de passe de la base contient
des caractères spéciaux, les encoder : `@` → `%40`, `:` → `%3A`, `/` → `%2F`, `%` → `%25`, `#` → `%23`, `?` → `%3F`.

Réglages facultatifs, une ligne chacun : `DEEPL_API_KEY=...` (traduction DeepL, recommandée), `COOKIE_SECURE=1`
(à ajouter **seulement une fois le HTTPS actif**, voir étape 6).

## 4. Images

Les images téléversées ne sont pas dans Git. Pour envoyer le dossier `uploads` du poste vers `htdocs/uploads`, depuis
PowerShell, dans le dossier du projet (Windows fournit `tar`) :

```powershell
tar -cf uploads.tar uploads
cmd /c "ssh i-dia2811273@185.98.131.155 tar -xf - -C htdocs < uploads.tar"
```

(le passage par `cmd` garantit que le fichier est transmis tel quel ; `uploads.tar` peut ensuite être supprimé)

## 5. Première mise en ligne

Avec GitHub Actions (recommandé, voir CICD.md) : onglet **Actions** > **CI/CD** > **Run workflow**, environnement
`production`, cocher **Première mise en place**.

Ce premier déploiement met de côté tout ce que contenait `htdocs` (ancien site) dans `htdocs/.app/ancien-site-<date>/`,
hors d'atteinte du web : rien n'est supprimé. Le dossier `.well-known` (validation du certificat HTTPS) est conservé.

## 6. Domaine et HTTPS

**Tant que i-dialogos.com pointe vers l'ancien hébergeur**, le site LWS se consulte depuis votre PC en ajoutant cette ligne
au fichier `C:\Windows\System32\drivers\etc\hosts` (Bloc-notes lancé en administrateur) :

```
185.98.131.155  i-dialogos.com  www.i-dialogos.com
```

puis en ouvrant `http://i-dialogos.com` (sans « s » : le certificat n'existe pas encore). Retirer la ligne après les essais.
Le déploiement automatique, lui, joint le serveur par son adresse IP grâce à la variable `PROD_SITE_IP` (CICD.md).

**Le jour de la bascule** (accès au domaine récupéré) :

1. Chez le gestionnaire du domaine, faire pointer `i-dialogos.com` et `www` vers LWS (serveurs DNS de LWS, ou enregistrement A
   vers `185.98.131.155`).
2. Après la propagation (quelques heures), activer le certificat SSL gratuit dans l'espace client LWS.
3. Ajouter `COOKIE_SECURE=1` dans `app.env` : `printf '%s\n' 'COOKIE_SECURE=1' >> ~/htdocs/.app/shared/app.env`.
4. Dans GitHub, passer `PROD_SITE_URL` à `https://i-dialogos.com` et supprimer `PROD_SITE_IP`.

## 7. Sauvegardes et journal

- Base : une sauvegarde est faite avant chaque déploiement dans `htdocs/.app/backups/` (les 10 dernières sont gardées).
  Pour restaurer : télécharger le fichier `.sql.gz` (FTP), le décompresser (7-Zip), puis l'exécuter dans PhpPgAdmin (SQL).
  Les sauvegardes quotidiennes de LWS restent disponibles en plus.
- Images : `htdocs/uploads/`, à sauvegarder régulièrement (FTP, ou depuis PowerShell : `cmd /c "ssh i-dia2811273@185.98.131.155 tar -cf - -C htdocs uploads > uploads.tar"`).
- Historique des mises en ligne : `htdocs/.app/deploy.log`.
