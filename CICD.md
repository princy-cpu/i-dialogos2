# Intégration et déploiement continus (CI/CD)

## Le circuit

```
branche de travail ──► pull request ──► CI : Ruff + ShellCheck + tests (Python 3.9 / 3.13 × MariaDB / PostgreSQL)
                                              │ tout est vert
                                              ▼
                         merge sur main ──► déploiement PRÉPRODUCTION (automatique)
                                              │ /healthz OK
                                              ▼
                       publication d'une version (v1.2.0)
                                              ▼
                                   déploiement PRODUCTION
```

À chaque déploiement, sur le serveur (`deploy/remote_deploy.sh`) :

1. la nouvelle version est décompressée à part, dans `releases/` ;
2. les dépendances sont installées et on vérifie que l'application se charge : **en cas d'échec, rien n'est mis en ligne** ;
3. **sauvegarde de la base** dans `backups/` (les 10 dernières sont gardées) ;
4. migrations (`python migrate.py`) ;
5. bascule : l'ancienne version passe dans `releases/prev-…`, la nouvelle devient `app/`, Passenger redémarre ;
6. depuis GitHub, contrôle de `/healthz` : base joignable **et** bonne version en ligne.
   **Sinon, retour automatique à la version précédente** et le déploiement est marqué en échec.

Les images (`shared/uploads/`) et les réglages (`shared/app.env`) ne sont jamais touchés par un déploiement.

## Organisation sur le serveur LWS

Un dossier par environnement dans le compte cPanel :

```
~/idialogos-preprod/                 ~/idialogos-prod/
├── app/            version en ligne (dossier déclaré dans Setup Python App)
├── shared/
│   ├── app.env     DATABASE_URL et autres réglages (droits 600, jamais dans Git)
│   └── uploads/    images téléversées
├── releases/       versions précédentes, pour le retour arrière
├── backups/        sauvegardes de la base avant chaque déploiement
└── deploy.log      historique : date, commit, deploy ou rollback
```

---

## Mise en place (une seule fois)

### 1. Vérifier l'accès SSH chez LWS

Dans l'espace client LWS, activer l'accès SSH de l'hébergement s'il ne l'est pas. Relever **le serveur**, **l'identifiant** et **le port**
(cPanel > Accès SSH, ou e-mail d'ouverture du compte). Test depuis PowerShell :

```powershell
ssh -p 22 moncompte@serveur.lws-hosting.com
```

Si LWS n'autorise le SSH que depuis des adresses IP déclarées, les machines de GitHub (adresses variables) seront refusées :
me le signaler, il faudra alors un déploiement par FTP à la place.

### 2. Créer chaque environnement dans cPanel (préproduction, puis production)

Exemple pour la préproduction ; refaire la même chose avec `idialogos-prod` et le domaine définitif.

1. **Sous-domaine** : cPanel > Domaines > créer `preprod.<un domaine du compte>` (pour la production : `i-dialogos.com`,
   une fois le domaine récupéré ; en attendant, voir `DEPLOIEMENT_LWS.md`, partie domaine).
2. **Base MySQL** : cPanel > Bases de données MySQL > base `idialogos_preprod`, utilisateur dédié, **tous les privilèges**.
   Importer les données avec phpMyAdmin (fichier produit par `python export_mysql.py`, voir `DEPLOIEMENT_LWS.md`).
3. **Application Python** : cPanel > Setup Python App > Create Application
   - Python version : la plus récente proposée (3.9 minimum)
   - Application root : `idialogos-preprod/app`
   - Application URL : le sous-domaine
   - Application startup file : `passenger_wsgi.py` ; Application Entry point : `application`
   - aucune variable à saisir ici : elles sont dans `shared/app.env`.
4. **Fichier de réglages** : créer `~/idialogos-preprod/shared/app.env` (gestionnaire de fichiers cPanel, ou SSH) :

   ```
   # Base MySQL de cet environnement (caractères spéciaux du mot de passe encodés : @ → %40, : → %3A, % → %25, # → %23)
   DATABASE_URL=mysql://moncompte_site:MOT_DE_PASSE@localhost:3306/moncompte_idialogos_preprod
   # À mettre à 1 une fois le HTTPS actif sur le domaine
   COOKIE_SECURE=1
   # Facultatif : traduction DeepL (recommandé en production)
   # DEEPL_API_KEY=xxxxxxxx:fx
   ```

   puis restreindre ses droits : `chmod 600 ~/idialogos-preprod/shared/app.env` (ou « Permissions » 600 dans le gestionnaire de fichiers).
5. **Images** : envoyer `idialogos_uploads.zip` dans `~/idialogos-preprod/shared/` et l'extraire : on doit obtenir
   `shared/uploads/*.webp`.

Le premier déploiement remplacera la page d'exemple créée par Setup Python App.

### 3. Créer la clé de déploiement

Sur ton PC (PowerShell), une clé réservée à GitHub, sans phrase de passe :

```powershell
ssh-keygen -t ed25519 -C "github-deploy-idialogos" -f "$HOME\.ssh\idialogos_deploy" -N '""'
```

- **Clé publique** (`idialogos_deploy.pub`) : cPanel > Accès SSH > Gérer les clés SSH > **Importer une clé**, coller le contenu,
  puis **Gérer > Autoriser**.
- **Empreinte du serveur**, pour que GitHub refuse un serveur qui se ferait passer pour LWS :

  ```powershell
  ssh-keyscan -p 22 serveur.lws-hosting.com
  ```

  Garder toute la sortie (les lignes qui commencent par le nom du serveur).

### 4. Configurer GitHub (dépôt `princy-cpu/i-dialogos2`)

Tout se règle au niveau du dépôt, ce qui fonctionne avec toutes les offres GitHub (y compris un dépôt privé sur un compte gratuit).

**Settings > Secrets and variables > Actions > onglet Secrets > New repository secret**

| Secret | Contenu |
|---|---|
| `SSH_PRIVATE_KEY` | tout le fichier `idialogos_deploy` (clé **privée**, de `-----BEGIN` à `END-----`) |
| `SSH_KNOWN_HOSTS` | la sortie de `ssh-keyscan` |

**Même page, onglet Variables > New repository variable**

| Variable | Exemple |
|---|---|
| `SSH_HOST` | `serveur.lws-hosting.com` |
| `SSH_USER` | identifiant cPanel |
| `SSH_PORT` | `22` (ou le port fourni par LWS) |
| `PREPROD_DEPLOY_PATH` | `idialogos-preprod` |
| `PREPROD_SITE_URL` | `https://preprod.<domaine>` |
| `PROD_DEPLOY_PATH` | `idialogos-prod` |
| `PROD_SITE_URL` | `https://i-dialogos.com` |
| `PREPROD_VENV_PATH`, `PROD_VENV_PATH` | facultatives (voir dépannage) |

**Protéger `main`** : Settings > Rules > Rulesets > New branch ruleset (cible : branche par défaut) : cocher
« Require a pull request before merging » et « Require status checks to pass », puis ajouter `Analyse statique` et les
quatre `Tests (…)` (ils apparaissent dans la liste après la première exécution de la CI).
Sur un dépôt privé avec un compte gratuit, GitHub n'applique pas ces règles : il suffit alors de toujours passer par une
pull request et d'attendre la coche verte avant de merger.

### 5. Nettoyer le dépôt (une fois)

Les images et les fichiers compilés ne doivent plus être suivis par Git (ils restent sur ton disque) :

```powershell
cd "C:\Users\PRINCY\Documents\Projet\Mes projets\idialogos.2"
git rm -r --cached uploads __pycache__
git add -A
git commit -m "CI/CD : tests, déploiement automatique LWS, migrations ; images hors de Git"
```

Sauvegarder le dossier `uploads/` du PC ailleurs (disque externe, cloud) : il n'est plus dans Git.

---

## Au quotidien

1. Travailler sur une branche, pousser, ouvrir une **pull request** vers `main` : la CI tourne, le résultat s'affiche dans la PR.
2. **Merge** : la préproduction se met à jour toute seule. Vérifier le site de préproduction.
3. **Mettre en production** = publier une version : GitHub > **Releases** > **Draft a new release** > « Choose a tag » :
   saisir une nouvelle version (`v1.0.0`, puis `v1.0.1` pour une correction, `v1.1.0` pour une nouveauté), cible `main`,
   **Generate release notes**, **Publish release**. Le déploiement de production démarre (onglet Actions).
   En ligne de commande : `git tag v1.0.0 ; git push origin v1.0.0`.
   Une version qui n'est pas sur `main` est refusée.

Redéployer sans changement de code : Actions > CI/CD > **Run workflow** > choisir l'environnement.

## Retour arrière

- **Automatique** si `/healthz` ne répond pas correctement après une mise en ligne.
- **Manuel** : Actions > **Retour arrière** > Run workflow > choisir l'environnement. Remet la version précédente du code.

La base n'est **pas** remise en arrière automatiquement. Si nécessaire : phpMyAdmin > Importer le fichier voulu de
`backups/` (fichiers `.sql.gz`, datés et nommés d'après le commit déployé juste après).

## Faire évoluer la base : les migrations

Ajouter une entrée à la fin de la liste `MIGRATIONS` de `server.py`, avec un numéro croissant et la requête pour chaque moteur :

```python
(2, 'Sous-titre des articles', {
    'postgresql': ["ALTER TABLE articles ADD COLUMN IF NOT EXISTS subtitle TEXT NOT NULL DEFAULT ''"],
    'mysql': ["ALTER TABLE articles ADD COLUMN subtitle VARCHAR(300) NOT NULL DEFAULT ''"],
}),
```

Règles :

- ne jamais modifier une migration déjà déployée : en ajouter une nouvelle ;
- rester compatible avec la version précédente du code (ajouter des colonnes avec une valeur par défaut, ne pas en supprimer ni
  en renommer dans le même déploiement), pour que le retour arrière reste possible ;
- `python migrate.py --statut` affiche la version de la base.

## Lancer les contrôles sur ton PC

Avec une base **dédiée aux tests** (elle est vidée à chaque lancement) :

```powershell
python -m pip install -r requirements-dev.txt
$env:DATABASE_URL = "postgresql://postgres:mot_de_passe@localhost:5432/idialogos_test"
python -m pytest -q tests
ruff check .
```

## Dépannage

| Message dans le journal GitHub | Cause probable |
|---|---|
| `Permission denied (publickey)` | clé publique non autorisée dans cPanel, ou `SSH_USER` incorrect |
| `Host key verification failed` | `SSH_KNOWN_HOSTS` manquant ou serveur différent : refaire `ssh-keyscan` |
| `Connection timed out` | port SSH incorrect ou accès SSH filtré par adresse IP chez LWS |
| `environnement Python introuvable` | l'application n'est pas créée dans Setup Python App avec le dossier `<DEPLOY_PATH>/app`, ou son environnement est ailleurs : renseigner `PREPROD_VENV_PATH` / `PROD_VENV_PATH` (chemin affiché en haut de la page de l'application, par exemple `virtualenv/idialogos-prod/app/3.11`) |
| `fichier …/shared/app.env absent` | étape 2.4 non faite pour cet environnement |
| `Échec de la sauvegarde de la base` | `DATABASE_URL` incorrecte dans `app.env`, ou `mysqldump` indisponible |
| `Contrôle de santé en échec` | ouvrir `SITE_URL/healthz` ; consulter le journal d'erreurs cPanel. Le site est déjà revenu à la version précédente. |

Déploiement manuel de dépannage depuis Git Bash ou Linux (variables équivalentes à celles de GitHub) :

```bash
SSH_HOST=… SSH_USER=… DEPLOY_PATH=idialogos-preprod SITE_URL=https://preprod.… SSH_KEY_FILE=~/.ssh/idialogos_deploy bash deploy/deploy.sh deploy
```
