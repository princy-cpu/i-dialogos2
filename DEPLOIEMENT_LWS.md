# Mise en production sur LWS (hébergement cPanel)

Ce document couvre ce qui est propre à l'hébergement LWS et la **reprise des données**.
Le déploiement du code est automatique : voir [CICD.md](CICD.md) (mise en place pas à pas, à suivre après ce document).

## Pourquoi MySQL et passenger_wsgi.py

L'hébergement cPanel de LWS ne propose pas PostgreSQL : en production, le site utilise **MySQL** (MariaDB).
Le code choisit le moteur d'après `DATABASE_URL` :

- `postgresql://…` : PostgreSQL, comme sur le poste local (rien ne change pour le développement) ;
- `mysql://…` : MySQL/MariaDB, sur LWS.

Sur cPanel, Python est exécuté par Phusion Passenger (outil « Setup Python App »), qui appelle
`passenger_wsgi.py` à chaque requête au lieu de lancer `python server.py`.
Python 3.9 ou plus récent est nécessaire (choisir la version la plus récente proposée par cPanel).

## 1. Exporter les données locales (sur ton PC)

Dans PowerShell, dans le dossier du projet :

```powershell
python -m pip install -r requirements.txt
$env:DATABASE_URL = "postgresql://postgres:mot_de_passe@localhost:5432/idialogos"
python export_mysql.py
```

Le script crée `idialogos_mysql.sql` (tables, articles, actualités, catégories, étiquettes, commentaires,
traductions déjà faites et compte administrateur). Ce fichier contient le compte administrateur :
ne pas le publier ni le versionner (il est exclu par `.gitignore`).

Les images sont dans `idialogos_uploads.zip` (contenu du dossier `uploads/`).

## 2. Importer dans la base MySQL de l'environnement

Une fois la base créée (CICD.md, étape 2) : cPanel > **phpMyAdmin** > sélectionner la base > onglet **Importer** >
choisir `idialogos_mysql.sql` > **Exécuter**. Si le fichier dépasse la taille autorisée, le compresser en `.zip`
(phpMyAdmin accepte les fichiers compressés).

> L'import **supprime puis recrée** les tables du site : à faire avant l'ouverture du site
> (ou pour remplacer entièrement les données).

On peut importer le même fichier en préproduction : elle aura alors une copie des vraies données.

## 3. Vérifier après le premier déploiement

- Le site affiche les articles importés ; `/healthz` répond `"status": "ok"`.
- `/admin/login` : se connecter avec le compte administrateur existant (même mot de passe qu'en local).
- En cas d'erreur : cPanel > **Métriques > Erreurs**, ou le journal de l'application (champ « Passenger log file » de
  Setup Python App, par exemple `/home/moncompte/idialogos-prod/passenger.log`).
  « base de données injoignable » signale presque toujours une erreur dans `DATABASE_URL` de `shared/app.env`
  (préfixe du nom de base ou d'utilisateur oublié, utilisateur non rattaché à la base, caractère spécial non encodé).

## 4. Domaine et HTTPS

**Tant que le domaine i-dialogos.com n'est pas récupéré**, on travaille sur la préproduction (sous-domaine d'un domaine
du compte, HTTPS normal). Pour voir la production sur son vrai nom avant la bascule, depuis ton PC seulement : relever
l'adresse IP partagée du serveur (cPanel, colonne de droite), puis ajouter dans `C:\Windows\System32\drivers\etc\hosts`
(Bloc-notes lancé en administrateur) : `IP_DU_SERVEUR  i-dialogos.com  www.i-dialogos.com`. Le certificat HTTPS n'étant
pas encore émis, tester en `http://` avec `COOKIE_SECURE` absent de `app.env` (sinon la connexion à l'administration
échoue). Retirer cette ligne après les tests.

**Le jour de la bascule** (accès au domaine récupéré) :

1. Chez le gestionnaire du domaine, faire pointer `i-dialogos.com` et `www` vers LWS (serveurs DNS de LWS,
   ou enregistrements A vers l'adresse IP du serveur).
2. Après la propagation (quelques heures), cPanel > **SSL/TLS Status** > lancer **AutoSSL** (certificat gratuit).
3. Forcer le HTTPS (cPanel > Domaines > « Force HTTPS Redirect »).
4. Mettre `COOKIE_SECURE=1` dans `~/idialogos-prod/shared/app.env`, puis redémarrer l'application
   (Setup Python App > Restart).

## 5. Sauvegardes

- Base : une sauvegarde est faite automatiquement avant chaque déploiement (`backups/`). En plus : phpMyAdmin > Exporter,
  ou l'outil de sauvegarde de cPanel.
- Images : `shared/uploads/` (les images ajoutées depuis l'administration y sont enregistrées). Elles ne sont pas dans Git :
  les sauvegarder régulièrement (outil de sauvegarde cPanel).

## Notes techniques

- Les traductions automatiques lancées en arrière-plan peuvent s'interrompre quand Passenger met l'application
  en veille (après quelques minutes sans visite). Elles reprennent à la visite suivante ; le bouton
  « Traduire tous les contenus maintenant » (Administration > Langues) permet de tout préparer d'un coup.
- Le service de traduction par défaut (Google, non officiel) peut être bloqué depuis un serveur mutualisé :
  DeepL (`DEEPL_API_KEY` dans `app.env`) est plus fiable en production.
- Pour lancer le site localement avec MySQL au lieu de PostgreSQL :
  `$env:DATABASE_URL = "mysql://utilisateur:mot_de_passe@localhost:3306/idialogos"; python server.py`.
