# Intégration et déploiement continus (CI/CD)

## Le circuit

```
branche de travail ──► pull request ──► CI : syntaxe PHP, Ruff, ShellCheck, tests de bout en bout (PHP 8.0 et 8.3)
                                              │ tout est vert
                                              ▼
                                       merge sur main
                                              │
                       publication d'une version (v1.2.0)
                                              ▼
                                   déploiement PRODUCTION
```

Une préproduction est possible plus tard (variables `PREPROD_*`) : elle se met alors à jour à chaque merge sur `main`.

À chaque déploiement (`deploy/deploy.sh`, lancé par GitHub Actions) :

1. le code est envoyé par SSH et décompressé à part, dans `htdocs/.app/releases/<version>/` ;
2. **sauvegarde de la base** par la version en ligne (`htdocs/.app/backups/`, les 10 dernières sont gardées) ; si elle
   échoue, le déploiement s'arrête et le site ne change pas ;
3. bascule : le fichier `htdocs/.app/current` désigne la nouvelle version, tout le site change d'un coup ;
4. migrations de la base ;
5. contrôle de `/healthz` depuis GitHub : base joignable **et** bonne version en ligne. **Sinon, retour automatique à la
   version précédente** et le déploiement est marqué en échec.

Les images (`htdocs/uploads/`) et les réglages (`htdocs/.app/shared/app.env`) ne sont jamais touchés.
Sur le serveur, les scripts n'utilisent que `tar`, `mkdir`, `cp`, `mv` et `rm`, plus PHP s'il est trouvé pour vérifier la syntaxe avant la mise en ligne.

## Organisation sur le serveur

```
htdocs/
├── index.php, .htaccess   chargeur et règles Apache (installés par le déploiement)
├── uploads/               images téléversées
├── .well-known/           validation du certificat HTTPS
└── .app/                  inaccessible depuis le web
    ├── current            version en ligne
    ├── history            versions mises en ligne (pour le retour arrière)
    ├── releases/          code de la version en ligne et des 5 précédentes
    ├── shared/app.env     DATABASE_URL, DEPLOY_TOKEN… (jamais dans Git)
    ├── backups/           sauvegardes de la base
    ├── ancien-site-…/     contenu de htdocs mis de côté lors de la première mise en ligne
    └── deploy.log         historique : date, commit, deploy ou rollback
```

---

## Mise en place (une seule fois)

Prérequis : étapes 1 à 4 de [DEPLOIEMENT_LWS.md](DEPLOIEMENT_LWS.md) (version de PHP, base, `app.env`, images).

### 1. Accès SSH pour GitHub

L'offre Starter ne permet pas de déposer une clé SSH (le dossier personnel n'est pas modifiable) : GitHub se connecte
donc avec le **mot de passe SSH** du compte, conservé dans un secret chiffré (étape 2). Si un jour une clé est possible,
il suffira de renseigner le secret `SSH_PRIVATE_KEY` à la place.

Relever l'empreinte du serveur, pour que GitHub refuse un serveur qui se ferait passer pour LWS (PowerShell) :

```powershell
ssh-keyscan 185.98.131.155
```

Garder toutes les lignes affichées.

### 2. Configurer GitHub (dépôt `princy-cpu/i-dialogos2`)

**Settings > Secrets and variables > Actions > onglet Secrets > New repository secret**

| Secret | Contenu |
|---|---|
| `SSH_PASSWORD` | le mot de passe SSH du compte `i-dia2811273` (celui du compte FTP) |
| `SSH_KNOWN_HOSTS` | la sortie de `ssh-keyscan` |
| `DEPLOY_TOKEN` | le jeton écrit dans `app.env` (DEPLOIEMENT_LWS.md, étape 3) |

**Même page, onglet Variables > New repository variable**

| Variable | Valeur |
|---|---|
| `SSH_HOST` | `185.98.131.155` |
| `SSH_USER` | `i-dia2811273` |
| `SSH_PORT` | `22` |
| `PROD_DEPLOY_PATH` | `htdocs` |
| `PROD_SITE_URL` | `http://i-dialogos.com` (puis `https://…` une fois le certificat actif) |
| `PROD_SITE_IP` | `185.98.131.155`, **tant que le domaine pointe vers l'ancien hébergeur** ; à supprimer après la bascule |

**Protéger `main`** (si l'offre GitHub le permet) : Settings > Rules > Rulesets > New branch ruleset, cible la branche
par défaut, cocher « Require a pull request before merging » et « Require status checks to pass » avec `Analyse statique`
et les deux `Tests (…)`. Sinon, toujours passer par une pull request et attendre la coche verte avant de merger.

### 3. Première mise en ligne

Onglet **Actions** > **CI/CD** > **Run workflow** : branche `main`, environnement `production`, cocher
**Première mise en place** (la version actuelle du serveur ne sait pas encore faire de sauvegarde). Suivre le déroulé ;
à la fin, `http://i-dialogos.com/healthz` (avec la ligne du fichier hosts, voir DEPLOIEMENT_LWS.md) répond `"status": "ok"`.

---

## Au quotidien

1. Travailler sur une branche, pousser, ouvrir une **pull request** vers `main` : la CI tourne, le résultat s'affiche dans la PR.
2. **Merge** quand tout est vert.
3. **Mettre en production** = publier une version : GitHub > **Releases** > **Draft a new release** > « Choose a tag » :
   nouvelle version (`v1.0.0`, puis `v1.0.1` pour une correction, `v1.1.0` pour une nouveauté), cible `main`,
   **Generate release notes**, **Publish release**. Le déploiement démarre (onglet Actions).
   En ligne de commande : `git tag v1.0.0 ; git push origin v1.0.0`. Une version qui n'est pas sur `main` est refusée.

Redéployer sans changement de code : Actions > CI/CD > **Run workflow**.

## Retour arrière

- **Automatique** si `/healthz` ne répond pas correctement après une mise en ligne.
- **Manuel** : Actions > **Retour arrière** > Run workflow. Remet la version précédente du code (un second lancement
  remonte encore d'une version).

La base n'est **pas** remise en arrière : si nécessaire, restaurer une sauvegarde de `htdocs/.app/backups/`
(DEPLOIEMENT_LWS.md, étape 7).

## Faire évoluer la base : les migrations

Ajouter une entrée à la fin de `MIGRATIONS` dans `src/db.php`, avec le numéro suivant :

```php
3 => ['Sous-titre des articles', [
    "ALTER TABLE articles ADD COLUMN IF NOT EXISTS subtitle TEXT NOT NULL DEFAULT ''",
]],
```

Règles : ne jamais modifier une migration déjà en ligne (en ajouter une nouvelle) ; rester compatible avec la version
précédente du code (ajouter des colonnes avec une valeur par défaut, ne pas en supprimer ni en renommer dans le même
déploiement), pour que le retour arrière reste possible. Les migrations s'appliquent au déploiement, et d'elles-mêmes
à la première visite si besoin.

## Lancer les contrôles sur ton PC

```powershell
python -m pip install -r requirements-dev.txt
$env:DATABASE_URL = "postgresql://postgres:mot_de_passe@localhost:5432/idialogos_test"   # base dédiée, vidée à chaque lancement
python -m pytest -q tests
ruff check .
```

## Dépannage

| Message dans le journal GitHub | Cause probable |
|---|---|
| `Envoi impossible : connexion SSH refusée` | mot de passe (`SSH_PASSWORD`) ou identifiant (`SSH_USER`) incorrect, ou `SSH_KNOWN_HOSTS` à refaire |
| `Host key verification failed` | `SSH_KNOWN_HOSTS` manquant ou différent : refaire `ssh-keyscan` |
| `Connection timed out` | accès SSH désactivé ou filtré par adresse IP chez LWS |
| `fichier …/app.env absent` | DEPLOIEMENT_LWS.md, étape 3 |
| `Sauvegarde impossible (HTTP 404)` | `DEPLOY_TOKEN` absent ou trop court dans `app.env` ; pour la première mise en ligne, cocher « Première mise en place » |
| `Sauvegarde impossible (HTTP 403)` | le secret `DEPLOY_TOKEN` de GitHub diffère de celui d'`app.env` |
| `Contrôle de santé en échec` | ouvrir `/healthz` : il indique l'erreur (base injoignable, extension PHP manquante…). Le site est déjà revenu à la version précédente. |

Déploiement manuel de dépannage depuis Git Bash ou Linux, avec `sshpass` installé (mêmes valeurs que dans GitHub) :

```bash
SSH_HOST=185.98.131.155 SSH_USER=i-dia2811273 SSH_PASSWORD='...' DEPLOY_PATH=htdocs \
SITE_URL=http://i-dialogos.com SITE_IP=185.98.131.155 DEPLOY_TOKEN=... bash deploy/deploy.sh deploy
```
