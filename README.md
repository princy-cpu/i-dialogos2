# I-Dialogos — publications

Application web pour rédiger et publier des articles, écrite en **PHP** (dossier `src/`) avec une base **PostgreSQL**. Elle tourne sur un hébergement mutualisé PHP (LWS Starter ou o2switch offre unique).

## Démarrer (sur votre poste)

Prérequis : PHP 8.0 ou plus récent avec les extensions `pdo_pgsql`, `mbstring`, `curl` et `zlib` (sous Windows, les activer dans `php.ini`), et une base PostgreSQL nommée `idialogos` (par exemple avec `createdb idialogos`).

Créez un fichier `app.env` à la racine du projet (il est exclu de Git) :

```
DATABASE_URL=postgresql://postgres:mot_de_passe@localhost:5432/idialogos
```

Puis lancez le serveur de développement :

```powershell
php -S 127.0.0.1:8000 dev-router.php
```

Ouvrez ensuite `http://127.0.0.1:8000`. Au premier démarrage, le site redirige vers `/admin/setup` pour créer le compte administrateur (12 caractères minimum pour le mot de passe), puis ouvrez **Administration** pour gérer les contenus. Pour arrêter le serveur : `Ctrl+C`.

Les tables sont créées automatiquement, puis mises à jour par les migrations de `src/db.php` (liste `MIGRATIONS`).

## Organisation du code

| Fichier | Rôle |
|---|---|
| `src/front.php` | point d'entrée et aiguillage des adresses |
| `src/public_pages.php` | accueil, thématiques, articles, actualités, commentaires |
| `src/admin_pages.php` | administration |
| `src/translation.php` | détection de langue et traduction automatique (avec cache en base) |
| `src/db.php` | connexion PostgreSQL, structure de la base et migrations |
| `src/html.php`, `src/http.php` | nettoyage du HTML, sessions, sécurité des formulaires |
| `src/ops.php` | sauvegarde et migrations déclenchées par le déploiement automatique |
| `public/` | fichiers installés à la racine du site sur le serveur (chargeur et règles Apache) |
| `tests/` | tests de bout en bout (Python, `pytest`) qui interrogent un vrai serveur PHP |

Les scripts Python (`server.py`, `translation.py`, `import_site.py`, `export_postgres_sql.py`) sont l'ancienne version et les outils d'import : ils ne sont pas déployés.

## Importer les articles de l’ancien site I-Dialogos

Outil en Python, à lancer sur votre poste. Après avoir défini `$env:DATABASE_URL` et installé les dépendances (`python -m pip install -r requirements.txt`, qui ajoute Pillow pour convertir les images), lancer :

```powershell
python import_site.py --limite 5   # essai sur 5 articles
python import_site.py              # import complet (environ 235 articles, compter une heure)
```

Le script lit les sitemaps de <https://www.i-dialogos.com> et importe chaque article complet : titre, chapô, texte avec sa mise en forme et ses liens, image principale et images du texte (téléchargées dans `uploads/` et converties en WebP), vidéos YouTube remplacées par un lien. La date vient de l’image principale (l’ancien site n’affiche pas de date), l’auteur est déduit du titre, la langue est détectée et la thématique est déduite de mots-clés : ces trois informations peuvent être corrigées dans l’administration. L’événement du site est importé dans « Actualités et événements », et les liens entre anciens articles pointent vers les nouvelles pages.

Le script peut être relancé sans créer de doublons : un article déjà importé (même adresse d’origine) est mis à jour, et les images déjà téléchargées sont réutilisées (`uploads/.import-images.json`). Les résumés créés auparavant par `import_articles.py` sont remplacés par les articles complets. `python import_site.py --simulation` affiche ce qui serait importé sans rien écrire.

## Fonctions

- Articles en brouillon ou publiés, titre, chapô, auteur/autrice et date.
- Actualités et événements avec résumé, contenu, lieu, dates, image, brouillon ou publication et page publique dédiée.
- Sélecteur multilingue pour le français, l’anglais, l’espagnol et l’italien, avec gestion des langues actives et de la langue par défaut dans l’administration.
- Traduction automatique des publications : la langue d’origine de chaque article et actualité est détectée automatiquement (option « Détection automatique » de l’éditeur). Le site affiche toutes les publications, traduites dans la langue choisie par le visiteur : titre, chapô, contenu (mise en forme et liens conservés), catégories et étiquettes. Un bandeau signale la traduction et propose la version originale (`?original=1`). Une langue peut aussi être forcée dans l’URL (`?lang=en`).
- Image mise en avant téléversée depuis l’ordinateur (JPEG, PNG, GIF ou WebP, 8 Mo maximum).
- Éditeur enrichi : gras, italique, souligné, barré, tailles de texte, titres, citation, listes, alignement, couleur, liens et images par URL.
- Catégories et étiquettes, ajoutées dans l’administration ou depuis l’éditeur.
- Modèles d’article personnalisables et choix d’un modèle par défaut.
- Commentaires activables par article, avec modération avant affichage.
- Authentification administrateur, mots de passe hachés avec PBKDF2, sessions HttpOnly et protection CSRF.
- Espace adhérent (`/admin`) avec trois rôles : **Administrateur** (tous les droits, seul à gérer les utilisateurs), **Auteur** (publie directement, ne modifie que ses propres articles), **Adhérent** (tableau de bord, profil, messagerie « Bientôt disponible »). Les comptes se créent, se modifient et se désactivent dans « Utilisateurs » ; un compte désactivé est déconnecté immédiatement. Chaque liste a une recherche et une pagination (20 lignes par page).
- Les Notes (`/analyses`) présentées en titres, avec deux onglets : **Sélection** (articles mis en avant, aussi sur l’accueil) et **Archives** (articles rangés par l’équipe, toujours en ligne). Filtres par thème, auteur, pays, année, mot-clé, langue et texte. Chaque article peut concerner plusieurs pays (champ « Pays » de l’éditeur) ; le menu « Régions » liste ces pays. Le rangement se fait article par article (case « Ranger dans les archives ») ou par lot depuis la liste des articles.
- Invités de la semaine et experts gérés dans l’administration (page publique `/invites-et-experts`).
- Newsletter à double confirmation (Brevo), lien de désinscription dans chaque e-mail, annonce automatique de chaque article publié (une seule fois, par une file d’envoi).
- Commentaires protégés contre les robots (champ piège, 3 envois par adresse IP en 10 minutes) et publiés après validation.

## Page d’accueil et maquettes

La page d’accueil suit les maquettes du dossier `PAGES Site 13.10.26`, dans l’ordre des numéros de page :

1. Accueil (article à la une, actualités, analyses précédentes en déroulé) et « Explorer nos thématiques »
2. Le réseau I-Dialogos dans le monde (liste des pays dépliable et carte)
3. À propos et Les Notes d’I-Dialogos
4. La gouvernance (coordinateurs pays, Comité de pilotage, Comité scientifique)
6. L’Invité/e de la semaine
7 à 13. Les thématiques : Médias et pluralisme, Francophonies, Démocraties, IA, Jeunesse, Ultramarins, Eau, climat et environnement, Géopolitique, Santé et développement
14. Experts (défilé automatique)
15. Nos soutiens et partenaires
16. Nos grandes inspirations
17. Clôture : inscription à l’InfoLettre

La liste des articles (« Les Notes ») est placée entre les thématiques et les experts. Chaque thématique a sa page `/thematique/<identifiant>` avec les articles publiés de la catégorie associée ; les textes et la correspondance avec les catégories sont définis dans la liste `THEMES` de `server.py`. Les images des sections viennent des maquettes, découpées dans `assets/` (logos des partenaires dans `assets/partners/`).

## Traduction automatique

Le code se trouve dans `src/translation.php`. Les traductions sont mises en cache dans la table `translations` de PostgreSQL : chaque passage n’est traduit qu’une fois par langue, et seul un passage modifié est retraduit. À l’enregistrement d’un contenu publié, ses traductions sont préparées juste après l’envoi de la page, dans toutes les langues actives. La page **Langues** de l’administration permet aussi de re-détecter la langue des contenus existants et de traduire tous les contenus en une fois.

Service de traduction, choisi dans `app.env` :

```
# Par défaut, sans réglage : service web public de Google Traduction (gratuit, sans clé, non officiel).
DEEPL_API_KEY=votre-cle:fx                 # DeepL (recommandé en production ; offre gratuite disponible)
LIBRETRANSLATE_URL=https://votre-instance  # ou LibreTranslate (+ LIBRETRANSLATE_API_KEY)
TRANSLATION_PROVIDER=none                  # ou désactiver la traduction
```

Si le service ne répond pas, le texte d’origine s’affiche avec un message, et un nouvel essai a lieu une minute plus tard. Les traductions déjà faites par l’ancienne version Python sont réutilisées telles quelles.

## Réglages (`app.env` ou variables d’environnement)

```ini
SITE_URL=https://www.i-dialogos.org          # adresse publique, utilisée dans les liens des e-mails
DONATION_URL=https://…                       # lien « Faire un don » (bouton masqué tant qu’il est vide)
MEMBERSHIP_URL=https://…                     # facultatif : lien « Devenir adhérent » (HelloAsso 2026 par défaut)
BREVO_API_KEY=xkeysib-…                      # clé API Brevo (Paramètres > Clés API)
MAIL_FROM_EMAIL=newsletter@i-dialogos.org    # expéditeur validé dans Brevo (Expéditeurs et domaines)
MAIL_FROM_NAME=I-Dialogos                    # facultatif
MAIL_DAILY_LIMIT=300                         # facultatif : plafond quotidien (offre gratuite Brevo : 300)
```

La file d’envoi de la newsletter est traitée juste après chaque publication, et peut l’être à intervalles réguliers par une tâche planifiée (cron, toutes les 15 minutes) : `curl -fsS -X POST -H "X-Deploy-Token: $DEPLOY_TOKEN" https://www.i-dialogos.org/_ops/mail-queue`. Le bouton « Envoyer la file maintenant » de l’administration (Newsletter) fait de même.

## Tests

Avec une base PostgreSQL **dédiée aux tests** (elle est vidée à chaque lancement) :

```powershell
python -m pip install -r requirements-dev.txt
$env:DATABASE_URL = "postgresql://postgres:mot_de_passe@localhost:5432/idialogos_test"
python -m pytest -q tests
```

Les tests lancent eux-mêmes un serveur PHP (`php -S`) et parcourent le site comme un visiteur et un administrateur.

## Mise en production et déploiement continu

- [DEPLOIEMENT_O2SWITCH.md](DEPLOIEMENT_O2SWITCH.md) : transfert et installation chez o2switch (offre unique), jusqu'au domaine et au HTTPS.
- [DEPLOIEMENT_LWS.md](DEPLOIEMENT_LWS.md) : installation sur l'hébergement LWS Starter (base PostgreSQL, réglages, domaine et HTTPS).
- [CICD.md](CICD.md) : tests et déploiement automatiques avec GitHub Actions (production à chaque version publiée, sauvegarde de la base avant chaque mise en ligne, retour arrière automatique).

## Données

Les articles, comptes, catégories, étiquettes, modèles, sessions, commentaires et traductions sont stockés dans PostgreSQL. Les images téléversées sont dans `uploads/` (sur le serveur : `htdocs/uploads/`). Sur le serveur, une sauvegarde de la base est faite automatiquement avant chaque déploiement (`htdocs/.app/backups/`).
