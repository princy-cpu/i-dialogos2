# I-Dialogos — publications

Application web pour rédiger et publier des articles. Le stockage des comptes et des publications utilise PostgreSQL.

## Démarrer

Installez le pilote PostgreSQL Python :

```powershell
python -m pip install -r requirements.txt
```

Créez une base PostgreSQL nommée `idialogos` (par exemple avec `createdb idialogos`), puis indiquez son URL de connexion dans PowerShell :

```powershell
$env:DATABASE_URL = "postgresql://postgres:mot_de_passe@localhost:5432/idialogos"
python server.py
```

Adaptez le nom d’utilisateur et le mot de passe à votre installation PostgreSQL. Ouvrez ensuite `http://127.0.0.1:8000`. Au premier démarrage, le site redirige vers `/admin/setup` pour créer le compte administrateur. Le mot de passe doit contenir au moins 12 caractères. Puis ouvrir **Administration** pour gérer les contenus.

Pour arrêter le serveur : `Ctrl+C`.

## Importer les articles de l’ancien site I-Dialogos

Après avoir configuré `DATABASE_URL` et installé les dépendances (`python -m pip install -r requirements.txt`, qui ajoute Pillow pour convertir les images), lancer :

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

Le code se trouve dans `translation.py`. Les traductions sont mises en cache dans la table `translations` de PostgreSQL : chaque passage n’est traduit qu’une fois par langue, et seul un passage modifié est retraduit. À l’enregistrement d’un contenu publié, ses traductions sont préparées en arrière-plan dans toutes les langues actives. La page **Langues** de l’administration permet aussi de re-détecter la langue des contenus existants et de traduire tous les contenus en une fois.

Service de traduction, choisi par variable d’environnement :

```powershell
# Par défaut, sans configuration : service web public de Google Traduction (gratuit, sans clé, non officiel).
$env:DEEPL_API_KEY = "votre-cle:fx"                     # DeepL (recommandé en production ; offre gratuite disponible)
$env:LIBRETRANSLATE_URL = "https://votre-instance"      # ou LibreTranslate (+ $env:LIBRETRANSLATE_API_KEY)
$env:TRANSLATION_PROVIDER = "none"                      # ou désactiver la traduction
```

Si le service ne répond pas, le texte d’origine s’affiche avec un message, et un nouvel essai a lieu une minute plus tard.

## Données

Les articles, comptes, catégories, étiquettes, modèles, sessions et commentaires sont stockés dans PostgreSQL. La configuration de connexion est fournie via `DATABASE_URL`. Les images téléversées sont dans `uploads/`. Sauvegardez la base PostgreSQL (par exemple avec `pg_dump idialogos`) et ce dossier pour conserver le site.

Le serveur écoute par défaut uniquement sur `127.0.0.1` : le compte et les contenus sont prévus pour une utilisation sur cette machine. Une mise en ligne publique ou un accès partagé nécessiteraient un hébergement HTTPS et une configuration serveur adaptée.
# i-dialogos2
