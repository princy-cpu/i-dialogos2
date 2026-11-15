<?php
/**
 * Pages institutionnelles tirées des fiches PDF du client (novembre 2026) : À propos (laboratoire d'idées, gouvernance,
 * historique, grandes inspirations), Les Notes (présentation, Médias, Francophonie) et Partenaires. Chaque rubrique du
 * menu ouvre sa propre page, au lieu d'une ancre de l'accueil qui faisait défiler d'autres contenus.
 */
declare(strict_types=1);

const INSTITUTION_PAGES = [
    '/a-propos' => 'page_laboratoire',
    '/gouvernance' => 'page_gouvernance',
    '/gouvernance/comite-de-pilotage' => 'page_comite_pilotage',
    '/gouvernance/coordinateurs' => 'page_coordinateurs',
    '/gouvernance/comite-scientifique' => 'page_comite_scientifique',
    '/historique' => 'page_historique',
    '/inspirations' => 'page_inspirations',
    '/les-notes' => 'page_les_notes',
    '/les-notes/medias' => 'page_note_medias',
    '/les-notes/francophonie' => 'page_note_francophonie',
    '/partenaires' => 'page_partenaires',
];

const OLD_SITE = 'https://www.i-dialogos.com';

/** Comité de pilotage 2026-2027 (élu le 3 mai 2026) : nom, pays, fonction, portrait éventuel (assets/people). */
const STEERING_COMMITTEE = [
    ['William AWINADOR', 'Ghana', 'Ambassadeur, conseiller Gouvernance et Paix de la Commission de l’Union africaine, ancien représentant près la CEDEAO et les Nations unies pour l’Afrique.', ''],
    ['Reine BENAO SAKANDE', 'Burkina Faso', 'Ancienne députée à l’Assemblée nationale et à la CEDEAO, haute fonctionnaire, directrice de la Coopération décentralisée, 1re adjointe de mairie d’arrondissement à Ouagadougou.', ''],
    ['Francis DELPÉRÉE', 'Belgique', 'Professeur émérite de droit constitutionnel à l’Université catholique de Louvain, membre de l’Académie royale, ancien sénateur et ancien député.', ''],
    ['Luiz-Henrique DINIZ ARAUJO', 'Brésil', 'Procureur fédéral et professeur de droit constitutionnel à l’Université fédérale de Pernambuco (UFPE), docteur en droit.', ''],
    ['Peggy FEHAN', 'États-Unis', 'Directrice exécutive du CODOFIL (Conseil pour le développement du français en Louisiane), à Lafayette.', ''],
    ['Cecilia Helga FRECH', 'Pérou', 'Professeure à l’Universidad Nacional Federico Villarreal (tourisme), à Lima. Chercheuse invitée à l’American Institute of Applied Science (Suisse).', ''],
    ['Augustin Brutus JAYKUMAR', 'Inde', 'Président de l’INDP (Intercultural Network for Development and Peace), spécialiste du développement holistique et du microcrédit, à Pondichéry.', ''],
    ['Pierrick HAMON', 'France', 'Délégué général d’I-Dialogos. Ancien du ministère des Affaires étrangères, du Comité des régions de l’UE, des Nations unies à Genève et des collectivités territoriales.', ''],
    ['Annie LÉVY-MOZZICONACCI', 'France', 'Médecin généticienne, maître de conférences et praticienne hospitalo-universitaire à l’AP-HM (Marseille) ; ancienne conseillère municipale de Marseille.', 'annie-levy-mozziconacci'],
    ['Jean-Claude MAIRAL', 'France', 'Ancien président du Département de l’Allier et vice-président du Conseil régional d’Auvergne ; président de Planète Jeunes Reporters.', 'jean-claude-mairal'],
    ['Tina MWANGELU', 'RDC / France', 'Présidente d’Ephethys Consulting et de l’association « Je suis RDC », trésorière d’I-Dialogos. Cadre dirigeante à la SNCF.', ''],
    ['Habiba MEJRI-CHEIKH', 'Tunisie', 'Ancienne directrice de l’Information et de la Communication de l’Union africaine ; journaliste, ancienne de l’agence TAP. Porte-parole de ministères.', 'habiba-mejri-cheikh'],
    ['David NDUKA UTAH', 'Nigeria', 'Universitaire et linguiste ; enseignant-chercheur à la National Open University of Nigeria (Abuja), spécialiste des migrations et des études globales.', ''],
    ['Marc PINELLI', 'France', 'Journaliste, ancien de l’AFP et de TV5 ; spécialiste de l’éducation aux médias et à l’information, membre de Globe Reporters.', ''],
    ['Ali SEDJARI', 'Maroc', 'Professeur à l’Université Mohammed V de Rabat ; spécialiste de la gouvernance, ancien titulaire de la Chaire UNESCO des droits de l’Homme.', 'ali-sedjari'],
    ['Abdoulaye SENE', 'Sénégal', 'Ancien président du Conseil régional de Fatick et ancien député ; spécialiste de la décentralisation ; président du Global Water Partnership Afrique de l’Ouest.', 'abdoulaye-sene'],
    ['Tran THU DUNG', 'Viet Nam', 'Écrivaine, journaliste et traductrice, engagée dans le dialogue interculturel et la francophonie au Viet Nam. Présidente d’associations.', ''],
    ['Jimmy VAKAULIAFA', 'Wallis-et-Futuna (France)', 'Consultant en intelligence économique et développement territorial ; spécialiste des transitions économiques, ancien militaire et chef d’entreprise.', 'jimmy-vakaulifa'],
    ['Martin ZIGUÉLÉ', 'République centrafricaine', 'Ancien Premier ministre de la République centrafricaine, ancien dirigeant de la Banque des États de l’Afrique centrale (BEAC).', ''],
];

const EXECUTIVE_BUREAU = [
    ['Abdoulaye SENE', 'Coprésident', 'abdoulaye-sene'],
    ['Habiba MEJRI-CHEIKH', 'Coprésidente', 'habiba-mejri-cheikh'],
    ['Jean-Claude MAIRAL', 'Coprésident', 'jean-claude-mairal'],
    ['Tina MWANGELU', 'Administratrice trésorière', ''],
    ['Pierrick HAMON', 'Délégué général', ''],
];

/** Chantiers des Notes et de la présentation, reliés à leur thématique. */
const NOTE_WORKSITES = [
    ['Information, presse et médias sociaux', 'medias'],
    ['Eau, climat, environnement, territoires et gouvernance', 'eau'],
    ['Approche internationale des politiques de coopération sanitaire', 'sante'],
    ['Quelle démocratie, quelles démocraties ?', 'democraties'],
    ['Territoires ultramarins', 'ultramarins'],
    ['Francophonies', 'francophonies'],
];

/** Portrait carré, ou initiales sur fond vert quand le client n'a pas encore transmis de photo. */
function member_portrait(string $name, string $photo, string $class = 'member-photo'): string
{
    if ($photo !== '') {
        return '<img class="' . $class . '" src="/assets/people/' . esc($photo) . '.webp" alt="Portrait de ' . esc($name) . '" loading="lazy" width="360" height="360">';
    }
    $words = preg_split('/[\s-]+/u', $name) ?: [];
    $initials = mb_strtoupper(mb_substr($words[0] ?? '', 0, 1) . mb_substr(end($words) ?: '', 0, 1));
    return '<span class="' . $class . ' member-initials" aria-hidden="true">' . esc($initials) . '</span>';
}

/** Lien vers un article publié dont le titre contient $needle, sinon vers la recherche des Notes. */
function article_href(string $needle): string
{
    $slug = db()->value("SELECT slug FROM articles WHERE status='published' AND title ILIKE ? ORDER BY published_at DESC NULLS LAST LIMIT 1", ['%' . $needle . '%']);
    return $slug ? '/article/' . q((string) $slug) : '/analyses?q=' . q($needle);
}

/**
 * Gabarit commun : bandeau « Peuples et citoyens du monde », en-tête (surtitre, titre, chapô, visuel), fil d'Ariane,
 * contenu, puis liens vers les pages voisines de la même rubrique.
 */
function institution_page(string $title, array $trail, string $lead, string $content, string $visual = '', array $siblings = []): void
{
    $crumbs = '<a href="/">Accueil</a>';
    foreach ($trail as [$label, $href]) {
        $crumbs .= ' <span aria-hidden="true">›</span> ' . ($href !== '' ? '<a href="' . esc($href) . '">' . esc($label) . '</a>' : '<span>' . esc($label) . '</span>');
    }
    $crumbs .= ' <span aria-hidden="true">›</span> <span aria-current="page">' . esc($title) . '</span>';
    $next = '';
    if ($siblings) {
        $links = '';
        foreach ($siblings as [$label, $href, $text]) {
            if ($href !== request()->path) {
                $links .= '<a class="inst-card inst-link-card" href="' . esc($href) . '"><strong>' . esc($label) . '</strong><span>' . esc($text) . '</span><span class="inst-arrow" aria-hidden="true">→</span></a>';
            }
        }
        $next = '<nav class="inst-next" aria-label="Pages de la même rubrique"><h2>Dans la même rubrique</h2><div class="inst-cards">' . $links . '</div></nav>';
    }
    $body = '<main class="inst-page"><p class="mk-ribbon">Peuples et citoyens du monde</p>'
        . '<header class="inst-hero' . ($visual !== '' ? ' has-visual' : '') . '"><div><nav class="inst-breadcrumb" aria-label="Fil d’Ariane">' . $crumbs . '</nav><span class="inst-kicker">I-Dialogos International</span><h1>' . esc($title) . '</h1>'
        . ($lead !== '' ? '<p class="inst-lead">' . $lead . '</p>' : '') . '</div>' . $visual . '</header>'
        . '<div class="inst-body">' . $content . $next . '</div></main>';
    public_page($title, $body);
}

const ABOUT_PAGES = [
    ['Un nouveau Laboratoire d’idées', '/a-propos', 'Qui sommes-nous, notre ambition et nos chantiers.'],
    ['La Gouvernance', '/gouvernance', 'Comité de pilotage, coordinateurs territoriaux et Comité scientifique.'],
    ['Historique', '/historique', 'Les premiers signataires et le Comité constitutif.'],
    ['Les grandes inspirations', '/inspirations', 'Léopold Sédar Senghor, François Jullien, Souleymane Bachir Diagne.'],
];

const GOVERNANCE_PAGES = [
    ['Le Comité de pilotage', '/gouvernance/comite-de-pilotage', '19 membres issus de 16 pays, élus le 3 mai 2026.'],
    ['Les Coordinateurs territoriaux', '/gouvernance/coordinateurs', 'Les relais d’I-Dialogos dans chaque pays.'],
    ['Le Comité scientifique', '/gouvernance/comite-scientifique', 'Instance consultative présidée par le professeur Ali Sedjari.'],
];

const NOTES_PAGES = [
    ['Présentation', '/les-notes', 'Des analyses aux propositions.'],
    ['Médias', '/les-notes/medias', 'Pour une information véritablement plurielle.'],
    ['Francophonie', '/les-notes/francophonie', 'Une Note en cours d’élaboration.'],
];

function worksite_list(): string
{
    $items = '';
    foreach (NOTE_WORKSITES as $index => [$label, $theme]) {
        $items .= '<li><a href="/thematique/' . $theme . '"><span class="inst-num">' . sprintf('%02d', $index + 1) . '</span>' . esc($label) . '</a></li>';
    }
    return '<ul class="inst-worksites">' . $items . '</ul>';
}

function page_laboratoire(): void
{
    $article = article_href('think tanks');
    $content = <<<HTML
<section class="inst-intro">
  <img class="inst-logo" src="/assets/logo-i-dialogos.png" alt="I-Dialogos, think tank" width="330" height="109">
  <p class="inst-statement">I-Dialogos est un think tank transnational qui réunit des acteurs de plus de 70 pays dans le monde, et de différents horizons, pour confronter les analyses et formuler des propositions.</p>
  <p class="inst-callout"><strong>Son ambition :</strong> contribuer à la refondation des relations internationales et de l’action publique par un dialogue exigeant et le croisement des expériences.</p>
</section>
<a class="inst-feature" href="{$article}">
  <img src="/assets/people/abdoulaye-sene.webp" alt="" width="360" height="360" loading="lazy">
  <span><small>L’article d’Abdoulaye SENE, à Dakar</small><strong>« Les think tanks, et autres laboratoires d’idées comme éclaireurs d’un monde en mutation »</strong><em>Lire l’article →</em></span>
</a>
<div class="inst-prose inst-columns">
  <section><h2>Dépasser les lectures stéréotypées</h2>
  <p>Cette démarche répond à une exigence : dépasser les lectures stéréotypées, les certitudes des élites installées et les discours médiatiques, souvent militants, qui privilégient l’émotion et le court terme. Comprendre suppose de savoir contextualiser, de pouvoir écouter les acteurs concernés et de reconnaître la diversité des histoires et des cultures, des intérêts et des aspirations. Le pluralisme exige que les désaccords puissent s’exprimer et être discutés.</p></section>
  <section><h2>Les relations internationales</h2>
  <p>Dans les relations internationales, I-Dialogos interroge les prétentions à un universalisme à sens unique parfois doté d’un humanisme opportunément interprété. Il questionne les ingérences, toutes les ingérences, et les logiques de puissance. Face aux limites des réponses strictement militaires, dont témoigne encore l’actualité, il entend remettre au premier plan la diplomatie, la recherche de solutions débattues et négociées, ainsi qu’un multilatéralisme effectif faisant pleinement place aux organisations régionales, sous-régionales et transfrontalières. Le dialogue, c’est la confrontation des points de vue et l’examen critique des responsabilités.</p></section>
  <section><h2>L’action publique</h2>
  <p>Cette ambition concerne aussi l’action publique. L’inadaptation des États-nations à certains enjeux, la bureaucratisation et l’éloignement, ou l’inadaptation, des lieux de décision, notamment européens, appellent à repenser l’exercice du pouvoir. Les territoires et les initiatives citoyennes peuvent contribuer à une plus grande efficacité dans une gouvernance multiniveaux ouverte sur le monde.</p></section>
  <section><h2>Une conception citoyenne de la Francophonie</h2>
  <p>I-Dialogos porte une conception citoyenne de la Francophonie : un espace de dialogue entre les langues et les cultures, de réciprocité et de coopération, affranchi des rapports de tutelle. Il défend un débat public nourri par des médias réellement respectueux du pluralisme, capables de contextualiser les événements et de soumettre les discours dominants à un examen critique, y compris ceux des réseaux et plateformes numériques, devenus des vecteurs majeurs d’information comme de désinformation.</p></section>
</div>
<p class="inst-prose inst-wide">Elle se concrétise par la publication de recherches, d’analyses, d’articles et d’entretiens, ainsi que par l’organisation d’ateliers, de débats et de visioconférences. En croisant les approches, elle vise à faire émerger des propositions étayées pour nourrir le débat public sur les grands enjeux géopolitiques, démocratiques, sociaux, culturels, climatiques et environnementaux. La diversité de son réseau transnational et l’exigence d’un débat fondé sur la raison permettent d’ouvrir des perspectives autour de plusieurs chantiers.</p>
<section class="inst-section"><h2>Nos principaux chantiers</h2>
HTML;
    $content .= worksite_list() . '</section>';
    institution_page('Un nouveau Laboratoire d’idées', [['À propos', '']], 'Un think tank transnational pour comprendre les mutations du monde.', $content, '', ABOUT_PAGES);
}

function page_gouvernance(): void
{
    $cards = [
        ['/assets/gov-pilotage.webp', 'Le Comité de pilotage', 'Le Comité de pilotage d’I-Dialogos réunit 19 membres issus de 16 pays et de plusieurs continents. Il est l’instance de gouvernance par délégation de l’Assemblée générale.', '/gouvernance/comite-de-pilotage'],
        ['/assets/gov-coordinators.webp', 'Les Coordinateurs territoriaux', 'Les coordonnatrices et coordonnateurs représentent I-Dialogos dans leurs pays respectifs et assurent la liaison avec les membres et partenaires.', '/gouvernance/coordinateurs'],
        ['/assets/gov-scientifique.webp', 'Le Comité scientifique', 'Instance consultative, le Comité scientifique international éclaire les orientations d’I-Dialogos et formule des propositions sur ses priorités de réflexion.', '/gouvernance/comite-scientifique'],
    ];
    $html = '';
    foreach ($cards as [$image, $title, $text, $href]) {
        $html .= '<article class="inst-card inst-gov-card"><img src="' . $image . '" alt="" loading="lazy"><h2><a href="' . $href . '">' . esc($title) . '</a></h2><p>' . esc($text) . '</p><a class="inst-button" href="' . $href . '" aria-hidden="true" tabindex="-1">Découvrir</a></article>';
    }
    institution_page('La Gouvernance', [['À propos', '/a-propos']], 'Trois instances complémentaires font vivre le dialogue transnational d’I-Dialogos.', '<div class="inst-cards inst-cards-3">' . $html . '</div>', '', ABOUT_PAGES);
}

function page_comite_pilotage(): void
{
    $bureau = '';
    foreach (EXECUTIVE_BUREAU as [$name, $role, $photo]) {
        $bureau .= '<li class="bureau-member">' . member_portrait($name, $photo, 'bureau-photo') . '<strong>' . esc($name) . '</strong><span>' . esc($role) . '</span></li>';
    }
    $members = '';
    foreach (STEERING_COMMITTEE as [$name, $country, $role, $photo]) {
        $members .= '<li class="member-card">' . member_portrait($name, $photo) . '<div><h3>' . esc($name) . '</h3><span class="member-country">' . esc($country) . '</span><p>' . esc($role) . '</p></div></li>';
    }
    $honour = '<li class="member-card member-honour"><img class="member-photo" src="/assets/oumou-sall-seck.webp" alt="Portrait d’Oumou Sall Seck" loading="lazy"><div><span class="eyebrow">Membre d’honneur</span><h3>Oumou SALL SECK</h3><span class="member-country">Mali</span><p>Ministre de l’Entrepreneuriat national, de l’Emploi et de la Formation professionnelle ; maire de Goundam et ancienne ambassadrice.</p></div></li>';
    $content = '<figure class="inst-mosaic"><img src="/assets/pages/comite-pilotage-membres.webp" alt="Portraits des membres du Comité de pilotage 2026-2027" width="992" height="242"><figcaption>Un nouveau Laboratoire d’idées, ou Think Tank, transnational (Nonprofit organization)</figcaption></figure>'
        . '<ul class="inst-stats"><li><strong>19</strong><span>membres</span></li><li><strong>16</strong><span>pays</span></li><li><strong>3 mai 2026</strong><span>date de l’élection</span></li></ul>'
        . '<section class="inst-section"><h2>Le Bureau exécutif</h2><ul class="bureau">' . $bureau . '</ul></section>'
        . '<section class="inst-section"><h2>Le Comité international de pilotage élu le 3 mai 2026</h2><ul class="member-grid">' . $members . $honour . '</ul></section>';
    institution_page('Le Comité de pilotage 2026-2027', [['À propos', '/a-propos'], ['La Gouvernance', '/gouvernance']], 'Les membres du Comité international de pilotage d’I-Dialogos et son Bureau exécutif.', $content, '', GOVERNANCE_PAGES);
}

function page_coordinateurs(): void
{
    preg_match('#<ul class="mk-country-list".*?</ul>#s', index_page(), $match);
    $countries = $match[0] ?? '';
    $content = <<<HTML
<p class="inst-statement">Les coordonnatrices et coordonnateurs représentent I-Dialogos dans leurs pays respectifs et assurent la liaison avec les membres et partenaires. Ils jouent un rôle moteur dans la mobilisation des contributions et leur circulation au sein du réseau international.</p>
<div class="inst-cards inst-cards-3 inst-pillars">
  <section class="inst-card"><span class="inst-num">01</span><h2>Des relais pour mobiliser les contributions</h2><p>À l’écoute des réalités de leur pays, ils recueillent et sollicitent des analyses, des expériences et des propositions qu’ils partagent avec les membres du réseau et les animateurs du think tank. Ils repèrent les compétences et les expertises susceptibles d’enrichir les travaux d’I-Dialogos et invitent leurs détenteurs à y contribuer.</p></section>
  <section class="inst-card"><span class="inst-num">02</span><h2>Une circulation des idées dans toutes les directions</h2><p>Leur mission repose sur un échange permanent : faire connaître les travaux et les initiatives du think tank dans leur pays, nourrir la réflexion collective des contributions qui en émanent et favoriser les échanges directs entre membres, experts et partenaires de différents pays. Ils contribuent ainsi à faire émerger de nouveaux sujets de réflexion et des coopérations transnationales.</p></section>
  <section class="inst-card"><span class="inst-num">03</span><h2>Une coordination au service de la réflexion collective</h2><p>Réunis au sein du <strong>Comité de liaison des coordonnateurs</strong>, ils mettent en commun leurs observations, leurs ressources et leurs initiatives. Cette coordination donne toute sa place à la diversité des perspectives et permet de construire des analyses et des propositions collectives nourries des réalités de terrain.</p></section>
</div>
<section class="inst-section"><h2>Retrouvez nos coordonnatrices et coordonnateurs</h2>
<p class="inst-help">Cliquez sur le nom d’un pays pour consulter le nom et les éventuelles coordonnées de sa coordonnatrice ou de son coordonnateur.</p>
<div class="mk-map inst-map">{$countries}<img src="/assets/pages/carte-coordinateurs.webp" alt="Carte des coordinateurs I-Dialogos dans le monde" width="1440" height="577" loading="lazy"></div>
</section>
HTML;
    $visual = '<img class="inst-badge" src="/assets/pages/coordinateurs-pays.webp" alt="I-Dialogos Country Coordinators" width="285" height="274">';
    institution_page('Le réseau I-Dialogos dans le monde', [['À propos', '/a-propos'], ['La Gouvernance', '/gouvernance']], 'Croiser les analyses, mobiliser les expertises et partager les savoirs.', $content, $visual, GOVERNANCE_PAGES);
}

function page_comite_scientifique(): void
{
    $content = <<<HTML
<article class="inst-person">
  <img src="/assets/people/ali-sedjari.webp" alt="Portrait du professeur Ali Sedjari" width="360" height="360" loading="lazy">
  <div><span class="eyebrow">Président</span><h2>Le professeur Ali SEDJARI</h2><p class="member-country">Rabat, Maroc</p>
  <p>Présidé par le professeur Ali SEDJARI, le Comité scientifique international est en cours de création.</p></div>
</article>
<div class="inst-cards inst-cards-2">
  <section class="inst-card"><h2>Une instance consultative</h2><p>Instance consultative, le Comité scientifique international, dont la composition est évolutive, éclaire les orientations d’I-Dialogos et formule des propositions sur ses priorités de réflexion.</p></section>
  <section class="inst-card"><h2>Un rôle de conseil pour les Notes</h2><p>Il exerce également un rôle de conseil dans la présentation des <a href="/les-notes">Notes d’I-Dialogos</a>, en veillant à la diversité des regards.</p></section>
</div>
HTML;
    $visual = '<img class="inst-badge" src="/assets/pages/comite-scientifique.webp" alt="Le Comité scientifique" width="480" height="455">';
    institution_page('Le Comité scientifique international', [['À propos', '/a-propos'], ['La Gouvernance', '/gouvernance']], 'Éclairer les orientations d’I-Dialogos en veillant à la diversité des regards.', $content, $visual, GOVERNANCE_PAGES);
}

function page_historique(): void
{
    $old = OLD_SITE;
    $content = <<<HTML
<div class="inst-cards inst-cards-2">
  <section class="inst-card" id="signataires"><h2>Les premiers signataires</h2><p>Les promoteurs et premiers signataires de l’initiative I-Dialogos, au 11 mars 2022.</p><p class="inst-pending">Contenu en cours de préparation.</p><a class="inst-button" href="{$old}/les-premiers-signataires" target="_blank" rel="noopener">Voir la liste sur l’ancien site ↗</a></section>
  <section class="inst-card" id="comite-constitutif"><h2>Le Comité constitutif</h2><p>Les membres du Comité qui a porté la création d’I-Dialogos.</p><p class="inst-pending">Contenu en cours de préparation.</p><a class="inst-button" href="{$old}/le-comit%C3%A9-constitutif" target="_blank" rel="noopener">Voir la liste sur l’ancien site ↗</a></section>
</div>
HTML;
    institution_page('Historique', [['À propos', '/a-propos']], 'Aux origines du think tank transnational I-Dialogos.', $content, '', ABOUT_PAGES);
}

function page_inspirations(): void
{
    $old = OLD_SITE;
    $content = <<<HTML
<figure class="inst-mosaic inst-mosaic-narrow"><img src="/assets/inspirations-figures.webp" alt="Portraits de Léopold Sédar Senghor et de François Jullien" loading="lazy"></figure>
<div class="inst-figures">
  <article class="inst-card" id="senghor"><span class="inst-num">01</span><h2>Léopold Sédar Senghor</h2><p class="member-country">1906-2001 · Sénégal</p>
  <p>Poète, penseur de la Négritude et de la Francophonie, premier président du Sénégal (1960-1980) et premier Africain élu à l’Académie française (1983). Il appelait de ses vœux une « civilisation de l’universel », fondée sur la rencontre et le dialogue des cultures.</p>
  <p>Pour I-Dialogos, une référence essentielle sur le dialogue des cultures et des religions.</p>
  <a class="inst-button" href="{$old}/nos-grandes-r%C3%A9f%C3%A9rences-leopold-s%C3%A9dar-senghor" target="_blank" rel="noopener">Lire la présentation complète ↗</a></article>
  <article class="inst-card" id="jullien"><span class="inst-num">02</span><h2>François Jullien</h2><p class="member-country">Philosophe, sinologue et helléniste · France</p>
  <p>Philosophe du dialogue entre les pensées européenne et chinoise, il invite à penser l’universel au-delà de l’universalisme ethnocentrique comme du relativisme culturaliste.</p>
  <p>Avec Léopold Sédar Senghor, une référence essentielle pour I-Dialogos, dont il a soutenu l’initiative.</p>
  <a class="inst-button" href="{$old}/nos-grandes-r%C3%A9f%C3%A9rences-fran%C3%A7ois-jullien" target="_blank" rel="noopener">Lire la présentation complète ↗</a></article>
  <article class="inst-card" id="diagne"><span class="inst-num">03</span><h2>Souleymane Bachir Diagne</h2><p class="member-country">Philosophe · Sénégal</p>
  <p>Philosophe sénégalais, professeur à l’université Columbia (New York), penseur de la traduction et du dialogue entre les langues, les cultures et les traditions philosophiques.</p>
  <p class="inst-pending">Présentation complète en cours de préparation.</p></article>
</div>
HTML;
    institution_page('Les grandes inspirations', [['À propos', '/a-propos']], 'Le dialogue entre les cultures, fil conducteur d’I-Dialogos.', $content, '', ABOUT_PAGES);
}

function page_les_notes(): void
{
    $tribune = article_href('presse libre');
    $content = <<<HTML
<div class="inst-split">
  <div class="inst-prose">
    <p class="inst-statement">Les Notes d’I-Dialogos rassemblent les propositions de notre think tank pour contribuer à la refondation des relations internationales et de l’action publique.</p>
    <p>Nourries du dialogue entre acteurs de différents pays et du croisement des expériences, elles associent analyse critique, mise en perspective et pistes d’action concrètes.</p>
    <p>Destinées aux citoyens, aux professionnels et aux décideurs, elles entendent contribuer au débat, confronter les choix possibles et faire émerger des réponses qui respectent la diversité des sociétés.</p>
  </div>
  <img class="inst-side-image" src="/assets/pages/notes-averroes.webp" alt="Citation d’Averroès (UNESCO) : « L’ignorance mène à la peur, la peur mène à la haine et la haine conduit à la violence. Voilà l’équation. »" width="369" height="327" loading="lazy">
</div>
<section class="inst-section"><h2>Première Note</h2>
  <article class="inst-card inst-note-card"><img src="/assets/pages/presse-libre-pluralisme.webp" alt="Qu’est-ce qu’une presse libre sans réel pluralisme ?" width="460" height="306" loading="lazy">
  <div><h3><a href="/les-notes/medias">Médias : pour une information véritablement plurielle</a></h3>
  <p>L’esquisse de cette Note a été publiée sous forme de tribune dans l’une de nos précédentes InfoLettres ou NewsLetters.</p>
  <a class="inst-quote-link" href="{$tribune}"><small>Lire la tribune</small>Qu’est-ce qu’une presse libre sans accès à une véritable information plurielle ?</a>
  <a class="inst-button" href="/les-notes/medias">Lire la Note</a></div></article>
</section>
<section class="inst-section"><h2>Les prochaines Notes</h2><p class="inst-help">En cours d’élaboration au sein des groupes thématiques :</p>
HTML;
    $content .= worksite_list() . '<a class="inst-button inst-button-light" href="/analyses">Toutes les analyses et contributions →</a></section>';
    institution_page('Les Notes d’I-Dialogos', [['les Notes', '']], 'Des analyses aux propositions.', $content, '', NOTES_PAGES);
}

function page_note_medias(): void
{
    $tribune = article_href('presse libre');
    $content = <<<HTML
<div class="inst-article">
<aside class="inst-toc" aria-label="Sommaire"><strong>Sommaire</strong>
  <a href="#presentation">Pour une information plurielle</a>
  <a href="#elements-initiaux">1. Éléments initiaux</a>
  <a href="#analyses">2. Analyses et contributions</a>
  <a href="#valeurs">Que sont nos valeurs partagées devenues ?</a>
</aside>
<div class="inst-prose">
<section id="presentation">
  <figure class="inst-figure"><img src="/assets/pages/presse-libre-pluralisme.webp" alt="3 mai, Journée internationale de la liberté de la presse : « Qu’est-ce qu’une presse libre sans réel pluralisme ? »" width="460" height="306" loading="lazy"></figure>
  <h2>Pour une information véritablement plurielle</h2>
  <p>Comment garantir une information libre, indépendante, de qualité et humaine ? Le groupe Médias et pluralisme d’I-Dialogos croise les expériences et les analyses de journalistes, de professionnels et de spécialistes des médias sur tous les continents pour élaborer des propositions.</p>
  <h3>Pluralisme, indépendance et responsabilité</h3>
  <p>Ses travaux interrogent la diversité des sources et des regards, la place des analyses divergentes dans le débat public et les influences politiques, économiques et idéologiques sur l’information. Ils questionnent les frontières entre journalisme, communication et militantisme, ainsi que les conditions de l’indépendance éditoriale et financière des médias.</p>
  <h3>De nouveaux modèles pour les médias et le débat public</h3>
  <p>Du territoire local à l’international, la réflexion porte sur la formation et le statut des journalistes, les financements de la presse, les médias de proximité et les démarches participatives. Elle examine aussi le rôle des plateformes numériques, des réseaux sociaux et de l’intelligence artificielle dans la circulation de l’information, les manipulations et la censure.</p>
  <h3>Une Note en préparation, ouverte aux contributions</h3>
  <p>Une première esquisse a été publiée sous forme de tribune dans une InfoLettre d’I-Dialogos. La Note en cours de développement sera enrichie des échanges et contributions du réseau. Elle proposera des pistes pour renforcer le pluralisme, restaurer la confiance et favoriser l’éducation aux médias ainsi que la participation citoyenne.</p>
  <p class="inst-callout"><strong>Combattre la désinformation sans disqualifier le désaccord.</strong></p>
  <a class="inst-quote-link" href="{$tribune}"><small>Lire la tribune</small>Qu’est-ce qu’une presse libre sans accès à une véritable information plurielle ?</a>
</section>

<section id="elements-initiaux">
  <h2>1. Éléments initiaux</h2>
  <p class="inst-overline">Quelle réalité ? Quelle perspective pour une information indépendante, qualitative et humaine ? Quels nouveaux modèles ? Quelles nouvelles références ?</p>
  <p>La présente Note « martyr » avait été soumise pour avis critiques et constructifs, et éventuelles contributions, aux journalistes, professionnels et spécialistes des médias de nos contacts et réseaux respectifs, sur tous les continents. Le fruit de ces échanges enrichira la Note ci-dessus.</p>
  <p>Dans un Monde en pleine mutation et aux enjeux considérables pour l’avenir de l’Humanité, comment garantir une information « responsable » qui soit une information libre, indépendante, qualitative et humaine ? Telles sont les questions auxquelles nous essaierons de répondre.</p>
  <p>Les Médias, au regard de leur grande responsabilité et de leur importance, doivent pouvoir se ressourcer pour dépasser et relativiser la puissance des réseaux sociaux ouverts à toutes les manipulations. Eux aussi doivent pouvoir se réinventer non seulement pour jouer ce rôle essentiel à la démocratie mais aussi, parfois, pour survivre. Acteurs d’une réinvention partagée du débat, ils peuvent demeurer ou redevenir des acteurs essentiels et respectés.</p>
  <p>Face aux immenses défis auxquels l’ensemble des pays et des populations de la planète sont confrontés pour leur avenir, les grands médias et les médias en ligne, la presse écrite, audio et TV, du territoire local au territoire national et à l’international ont un rôle fondamental à jouer pour informer, favoriser les prises de conscience, donner confiance et permettre la participation des populations et des jeunes en particulier à la résolution des problèmes rencontrés et à la mise en œuvre de l’ensemble des objectifs du développement durable (ODD) adoptés par l’ONU en 2015.</p>
  <h3>La presse n’est pas une !</h3>
  <p>Journalisme d’information, journalisme d’investigation et d’alerte, journalisme de proximité, journalisme de vulgarisation, journalisme d’espoir et de solutions, journalisme participatif et citoyen, autant de formes d’approches existantes du journalisme qui irriguent les pratiques journalistiques et médiatiques.</p>
  <h3>Une grande diversité de médias, une grande diversité de situations</h3>
  <p>Des médias généralistes, locaux, régionaux et internationaux, des médias qui vont de l’instantané des réseaux sociaux et des chaînes et des radios d’information en continu et du quotidien au mensuel, des médias sous influences des États, des financiers et des lobbies, des médias indépendants, des médias à vocation partisane (politique, syndicale, religieuse ou autre), des médias sur une ou des thématiques. Des interrogations à se poser et auxquelles nous devons essayer d’apporter des réponses.</p>
  <div class="inst-cards inst-cards-3 inst-questions">
    <section class="inst-card"><h4>Donner à voir la réalité de la presse</h4><ul><li>Quel état des lieux dans les pays ?</li><li>Journalisme et communication ?</li><li>Quel apport des médias dans les territoires et le lien avec les populations ?</li><li>Quelles expériences et quel statut des journalistes ?</li><li>Quelle formation des journalistes ?</li><li>Journalisme, engagement et militantisme sont-ils compatibles ?</li><li>Le poids des réseaux sociaux.</li></ul></section>
    <section class="inst-card"><h4>Pour une nouvelle approche</h4><ul><li>Comment lutter contre la perte de crédibilité des médias classiques par rapport aux médias dits alternatifs ?</li><li>Comment concevoir des médias diversifiés, indépendants, critiques et moins liés aux intérêts dominants ?</li><li>Quelle place du numérique dans les nouvelles formes du journalisme ?</li><li>Quel apport à la promotion des priorités des ODD ?</li><li>Comment repenser et développer la formation des journalistes ?</li><li>Comment favoriser des médias d’information libre et de qualité tout en veillant à leur indépendance financière ?</li><li>Quels financements de la presse ?</li></ul></section>
    <section class="inst-card"><h4>Quelle contribution pour la démocratie et la citoyenneté ?</h4><ul><li>Quel rôle, quel apport de la presse dans les pratiques démocratiques ?</li><li>Comment favoriser l’éducation aux médias et à la maîtrise des réseaux sociaux chez les jeunes ?</li><li>Comment développer des médias avec participation des populations ?</li><li>Quels espaces de débats, d’échanges d’expériences construire entre acteurs des médias, des jeunes et des citoyens ?</li></ul></section>
  </div>
</section>

<section id="analyses">
  <h2>2. Analyses et contributions</h2>
  <div class="inst-author"><img src="/assets/people/habiba-mejri-cheikh.webp" alt="" width="360" height="360" loading="lazy"><div><h3>Médias et démocratie</h3><p><strong>Habiba Mejri-Cheikh</strong>, coprésidente d’I-Dialogos</p></div></div>
  <p class="inst-bio">Outre ses responsabilités à la tête de la Direction de l’information et de la communication de l’Union africaine, l’organisation internationale et régionale dont le siège est à Addis-Abeba (2005-2015), Habiba Mejri-Cheikh, coprésidente du think tank I-Dialogos, avait auparavant travaillé comme journaliste au sein de nombreux organes de presse, ainsi qu’à l’Agence TAP (Tunis Afrique Presse). Deux fois chargée de mission, porte-parole, aux cabinets des ministres des Affaires sociales et de la Culture, elle voue un attachement indéfectible à son titre de journaliste. Aujourd’hui, elle pilote l’initiative Presse/Médias d’I-Dialogos.</p>
  <blockquote class="inst-blockquote">« À une époque de supercherie universelle, dire la vérité est un acte révolutionnaire. »<cite>George Orwell, 1984</cite></blockquote>
  <p>Aujourd’hui, les médias, tous supports confondus, nous disent-ils la vérité ? Une simple petite vadrouille dans les méandres des médias du monde, plus particulièrement ceux des grandes démocraties de notre temps, nous laisse pensifs. Notre monde s’enlise dans la falsification, la supercherie, les mensonges et les menteries. C’est à croire que nous sommes en plein dans l’univers orwellien. Les médias mettent-ils en danger la démocratie ?</p>
  <h3>Terrorisme médiatique ?</h3>
  <p>Ouvertement embrigadés, les médias constituent une des techniques modernes, peut-être la première et certainement la plus efficace, de la velléité de contrôle des individus. Ils s’appliquent, beaucoup mieux que ne le faisait le « Parti » imaginaire de George Orwell, à éliminer toute idée « hérétique ». Certainement pas tous, mais une grande partie d’entre eux et pas des moindres.</p>
  <p>Lorsque CNN, pour des raisons obscures, tombe dans les fake news, et que le président des États-Unis reprend aussitôt ses allégations, la liberté tremble, se meurt. Ce n’est pas le seul exemple à citer et certainement pas le dernier dont nous gratifient, quotidiennement, les médias dominants.</p>
  <p>Big Brother n’a jamais été aussi réel, tangible et patent. Il a repris du service notoirement et explicitement sur les ondes et les plateaux des plus grands médias internationaux, ceux qui se targuent d’être les chantres de la liberté d’expression et d’opinion et les porte-flambeaux de la démocratie et en qui nous avions très longtemps cru. Ne pas le voir, ne pas le dire, sans pour autant tomber dans l’accusatoire et le jugement, ne sert pas la démocratie.</p>
  <p>La hiérarchisation des vies humaines, les deux poids deux mesures et les doubles standards médiatiques y sont érigés en règle absolue. Il y aurait les maîtres et les éternels élèves, ceux qui commandent et ceux qui doivent s’exécuter. Il y aurait même ceux qui ont le droit de vivre et ceux qui doivent disparaître.</p>
  <p>La bombe aveugle et assassine, lorsqu’elle fauche un Ukrainien, devient « droit à se défendre » lorsque la victime est Palestinienne. C’est ainsi et pas autrement, personne ne doit oser se demander pourquoi, sinon il sera très vite catapulté dans les théories du complot, ou bien, et c’est encore plus net, plus propre, taxé de terrorisme ou d’antisémitisme et réduit au silence médiatique.</p>
  <h3>Crimes de la pensée</h3>
  <p>La « police de la pensée » opère sur les écrans, à visage découvert. Les « crimes de la pensée » pullulent sur les grandes chaînes internationales de télévision, mais également, et de façon beaucoup plus intraitable et en plus grand nombre, sur les plateformes internet, celles qui ont jadis promis d’élargir le champ de la liberté en donnant une voix aux sans-voix.</p>
  <p>Des censeurs zélés et froids, manifestement atteints par ce que Hannah Arendt désigne par la « banalité du mal », limitent, à tour de bras, la parole ou carrément ordonnent la fermeture de comptes de citoyens-journalistes, dès qu’ils s’aventurent à sortir de « la dystopie totalitaire ». Une idéologie oppressive œuvre sans relâche à épurer toutes les pensées qui lui sont inconfortables.</p>
  <p>La liberté d’opinion et d’expression, fondement, nous a-t-on appris, de la démocratie, doit désormais impérativement s’appuyer sur l’obéissance aveugle aux couvertures biaisées et unilatérales, servies par les représentants des grandes entreprises médiatiques.</p>
  <p>Avec les progrès de l’intelligence artificielle, les risques prennent des formes diverses et autrement plus pernicieuses. Dans ce monde fortement interconnecté, personne ne sera épargné.</p>
  <p>Le Forum sur la loi, les droits et la sécurité nationale des États-Unis, Just Security (www.justsecurity.org), nous apprend, par exemple, que la police de Memphis (États-Unis) a créé un faux profil Facebook pour se lier d’amitié avec les militants de Black Lives Matter et recueillir des informations auprès d’eux.</p>
  <p>Plus grave encore (peut-être pas !), dans la plus grande démocratie du monde, les préjugés intégrés aux outils algorithmiques bafouent les droits de certains individus. En effet, le même Forum indique que des utilisateurs d’Instagram ont récemment découvert que l’étiquette terroriste était ajoutée à leur biographie en anglais si leur biographie en arabe comprenait le mot « Palestinien », l’emoji du drapeau palestinien ou encore l’expression arabe courante « louange à Dieu ».</p>
  <h3>Décor démocratique</h3>
  <p>Face à ces faits et bien d’autres, peut-on encore parler de démocratie ?</p>
  <p>Une démocratie qui piétine les libertés, notamment les deux libertés les plus fondamentales que sont la liberté d’opinion et d’expression, serait-elle démocratique ? Sinon, de quelle démocratie parle-t-on ?</p>
  <p>La démocratie serait-elle, comme l’affirmait le professeur Alain Badiou, « rien d’autre qu’un outil de propagande du capitalisme » ? Une tromperie des bonnes masses ? Beaucoup le pensent, non sans fondements d’ailleurs.</p>
  <p>Des fake news à la manipulation, les médias dominants ne se soucient manifestement plus des principes déontologiques. Ils façonnent l’opinion publique hors contexte, hors logique.</p>
  <p>La seule démocratie qu’ils prêchent et œuvrent sans relâche à imposer, y compris par la violence, se réduit à une constitution, un multipartisme de façade, des élections et à la rotation du pouvoir. Un simple décor démocratique en somme. La lutte contre l’occupation, l’autodétermination, la souveraineté sur les ressources nationales, l’échange inégal et injuste et même la toute simple libre circulation des personnes sont des concepts quasi absents de leur lexique, des questions mineures qu’ils ne font accéder qu’occasionnellement au débat public.</p>
  <p>Mille et une raisons expliquent ces freins à l’émergence d’un journalisme qui s’extrait des clichés. La liste est longue et va, malgré les différentes lignes éditoriales, de l’absence de culture et de recul historique à la fascination pour l’anecdote, du manque de formation au poids considérable des réseaux sociaux qui constituent l’unique source où s’abreuvent les jeunes journalistes.</p>
  <p>La proximité de certains médias des pouvoirs politique, économique et idéologique enfonce davantage le clou. Le constat perturbe.</p>
  <p>Même si les médias occidentaux ne forment pas un bloc monolithique, même si certains se démarquent, il n’en demeure pas moins qu’ils véhiculent, dans toute leur diversité, via leurs journalistes, une conception réductrice, qui n’intègre guère la diversité philosophique, culturelle et spirituelle de l’ensemble des peuples du monde.</p>
  <p>Il n’y a pas lieu de chercher un coupable et surtout pas seulement du côté des professionnels des médias. Beaucoup essaient, non sans mal, de faire correctement leur métier et certains réussissent même à nous informer. Notre propos plaide pour la construction d’un discours de tolérance et de dialogue respectueux qui fera avancer l’agenda de la paix dont notre monde a grandement besoin. Une couverture médiatique équilibrée et professionnelle qui ne met pas en péril la démocratie. Rien de plus !</p>
</section>

<section id="valeurs">
  <h2>Que sont nos valeurs partagées devenues ?</h2>
  <p>Nous avons besoin de dialoguer. Sincèrement. Respectueusement. Le besoin se fait urgent. Vital.</p>
  <p>L’humanité se déchire, s’émiette, s’entre-tue, en direct. La presse s’aligne. Rend compte et puis s’en va. Et la crise ne fait qu’empirer. Il n’y a qu’à se tourner, juste un petit quart de tour, pour se rendre compte que la crédibilité de l’homme, celui-là même par qui notre salut devait arriver, s’ébrèche chaque jour davantage.</p>
  <p>La confiance en des promesses servies à grands coups de marketing, mais pas que, tombe en lambeaux, par petits bouts. Personne ne s’en soucie, ou juste ce qu’il faut pour continuer à vivre. Conséquence : les « eux » et « nous » gagnent du terrain, plus assourdissants, plus violents.</p>
  <p>Certains, du Sud, cette « mauvaise » partie du globe, mais également des pays mieux lotis du Nord, voient leur patience s’user et le font savoir, chacun à sa manière.</p>
  <p>Il y a ceux qui agissent et ceux, en face, qui réagissent. Tous s’expriment. Mieux vaut tendre l’oreille, tant qu’il est encore temps, pour capter ce flux de messages. Écouter activement ces interrogations qui fusent. Pour comprendre. Mais également, pourquoi pas, corriger les travers d’une trajectoire à sens unique, qui guide le monde et qui semble avoir atteint son niveau suprême de déviance.</p>
  <h3>Essoufflement ou échec ?</h3>
  <p>Le modèle occidental (gouvernements, système onusien et organisations de la société civile confondus) a-t-il rendu le monde meilleur ? Y a-t-il moins de conflits ? Moins de pauvreté ? Plus de solidarité ? Plus de compréhension ?</p>
  <p>Au vu des chiffres et des rapports, secrets et publics, beaucoup doutent. Et ils semblent avoir mille fois raison. La pauvreté augmente. La nourriture manque. Chocs énergétiques. Crises de la dette. Réchauffement climatique. Inflation. Guerres. À en croire les bilans des incontournables Banque mondiale et Fonds monétaire international, rien ne va plus<sup><a href="#note-1" id="appel-1">1</a></sup>.</p>
  <p>Leurs assemblées générales 2022 tirent très fort les sonnettes d’alarme. Ces grands riches qui ne manquent de rien font les comptes et s’inquiètent.</p>
  <p>« Le développement est en crise », atteste le président de la Banque, allant jusqu’à annoncer que « l’issue humanitaire de ces crises qui se chevauchent est catastrophique ».</p>
  <p>Catastrophique ? Pour qui ?</p>
  <p>Une bombe à retardement. L’Afrique, avec toutes ses richesses, sur et sous terre, peine à décoller. Les enfants d’Asie subsistent grâce aux habits de luxe qu’ils se tuent à confectionner pour trois fois rien. Les enfants du Yémen survivent grâce aux dons collectés par des bénévoles et des personnalités publiques recrutés pour les besoins de la cause. D’autres jeunes, séduits par l’herbe verte d’un lointain ailleurs, se jettent à l’océan. Beaucoup, radicalisés, empruntent, les cerveaux lavés et les cœurs pleins de haine, le voyage sans retour vers l’extrémisme…</p>
  <p>La guerre qui fait rage actuellement entre la Russie et l’Ukraine pourrit davantage la plaie, en élargissant le problème à une géographie que l’on croyait, jusque-là, hors d’atteinte. Une évaluation rapide des dommages et des besoins, publiée par la Banque mondiale, l’Union européenne et le gouvernement ukrainien, a estimé les besoins de redressement et de reconstruction de l’Ukraine à environ 349 milliards de dollars, soit le double du PIB de l’Ukraine d’avant-guerre. Qu’à cela ne tienne, les contribuables payeront la note.</p>
  <h3>Populations-élèves</h3>
  <p>Le modèle occidental, qui s’est imposé et qui continue de gouverner le monde (pour combien de temps encore ?), est malade. D’aucuns pensent qu’il se meurt. Suicidé par les siens qui se sont ligués, comme un seul homme, pour imposer une vision uniforme à un monde multiple.</p>
  <p>Prescrire comment l’autre doit être gouverné. Comment et avec qui il doit faire l’amour. Quelle législation il doit adopter et quels programmes scolaires il doit dispenser à ses élèves. Comble de l’arrogance, cet Occident qui s’est physiquement évertué, hier, à civiliser des populations, entreprend aujourd’hui, par d’autres moyens, moins coûteux peut-être, mais tout aussi provocants, de les démocratiser, les libéraliser, les intégrer dans le cercle des gens fréquentables.</p>
  <p>Ces populations-élèves en éternelle formation sont contraintes, sans délai, de savoir faire plus avec moins. Moins de tout. Moins d’argent, moins de technologie, zéro siège au Conseil de sécurité… etc.</p>
  <p>La mission est impossible. Tout le monde le sait, mais personne n’en dit mot, ou presque. Malgré le déni et la fuite en avant, les signes d’essoufflement sont évidents. C’est l’obsolescence même.</p>
  <p>Le modèle n’a-t-il pas carrément échoué ? Ça se discute.</p>
  <p>Seul le dialogue pourrait conduire à une compréhension honnête de l’étendue des dégâts, sur la base d’une évaluation froide qui permettrait de cesser la tromperie, du moins de la reconnaître pour permettre à ceux qui le peuvent de redresser la barre.</p>
  <p>Certes, le tableau n’est pas totalement sombre. Il y a des résultats positifs et des acquis enregistrés. Une prise de conscience se profile doucement, mais sûrement, à l’horizon. Malgré le déséquilibre de plus en plus insupportable qui profite d’abord à ceux nés du bon côté du globe et à quelques autres bons élèves qui ont réussi l’examen d’admission au cercle de confiance, l’espoir demeure.</p>
  <p>On déplore seulement que le cercle soit un peu trop étanche et même qu’il se rétrécisse. Et c’est dommage. Nous devons urgemment en débattre ! La voie du salut est dans le dialogue, franc, libre et sans conditions préalables.</p>
  <h3>Information ou marketing politique ?</h3>
  <p>Dans cette opération de sauvetage d’envergure, la presse, tous médias et supports confondus, constitue un acteur privilégié. Classique et digitale, elle est l’outil, l’interface entre les gouvernants et les gouvernés, aux niveaux national et international.</p>
  <p>C’est par elle que la rencontre espérée devrait venir ou non. Sa position est déterminante. Au-delà du rôle de médiateur, qui lui revient de droit, elle a la responsabilité de la vérité. Cependant, là encore, le tableau n’offre pas plus de lumière.</p>
  <p>Sans succomber à la généralisation, il appert que, malgré les bonnes intentions, un discours dominant écrase les plus vulnérables. Il les gêne, même dans leur cheminement. Il y aurait les bons d’un côté et les mauvais de l’autre. Ceux qui ont tout ce qu’il faut et s’en encensent, et ceux qui ont juste ce qu’il faut et qui tendent la main et parfois l’esprit. Tous sont jugés selon les mêmes règles, uniformes et sans rapport avec le contexte, tombées d’en haut, de chez « le maître ». Aucun écart n’est permis.</p>
  <p>La censure à laquelle sont soumis les journalistes russes ces derniers temps, qui font l’objet d’un embargo étanche, interdits de tout droit d’expression, carrément effacés du paysage médiatique, renseigne, on ne peut plus clairement, sur la mission affectée à la presse au sein du système de gouvernance du modèle occidental. Un rôle dont elle semble s’accommoder à la perfection. Et ce n’est pas le seul exemple au palmarès. Double standard ? Loi du plus fort ?</p>
  <p>Il est temps de revoir nos basiques. La communication, le marketing politique ont-ils pris le dessus sur l’information ?</p>
  <p>Outre la cassure technique et technologique, et épistémologique aussi, qu’est-ce qu’il nous reste à digérer ?</p>
  <p class="inst-callout"><strong>Parlons-en. Dialoguons.</strong></p>
  <p class="inst-footnote" id="note-1"><a href="#appel-1">1.</a> Banque mondiale, « Assemblées annuelles 2022 : le développement en crise », <a href="https://www.albankaldawli.org/ar/news/feature/2022/10/16/annual-meetings-2022-development-in-crisis" target="_blank" rel="noopener">albankaldawli.org</a>.</p>
</section>
</div>
</div>
HTML;
    $visual = '<figure class="inst-pilot"><img src="/assets/people/habiba-mejri-cheikh.webp" alt="Portrait d’Habiba Mejri-Cheikh" width="360" height="360"><figcaption>Groupe piloté par<br><strong>Habiba MEJRI-CHEIKH</strong></figcaption></figure>';
    institution_page('Médias et pluralisme', [['les Notes', '/les-notes']], 'Pour une information véritablement plurielle.', $content, $visual, NOTES_PAGES);
}

function page_note_francophonie(): void
{
    $theme = null;
    foreach (themes() as $item) {
        $theme = $item['id'] === 'francophonies' ? $item : $theme;
    }
    $text = '';
    foreach (['question', 'text', 'text2'] as $key) {
        $text .= ($theme[$key] ?? '') !== '' ? '<p>' . esc($theme[$key]) . '</p>' : '';
    }
    $image = $theme ? '<img class="inst-side-image" src="/assets/' . esc($theme['image']) . '" alt="Illustration : Francophonies" loading="lazy">' : '';
    $content = '<div class="inst-split"><div class="inst-prose"><p class="inst-pending">Cette Note est en cours d’élaboration au sein du groupe thématique « Francophonies ».</p>' . $text
        . '<a class="inst-button" href="/thematique/francophonies">Voir la thématique Francophonies</a></div>' . $image . '</div>';
    institution_page('Francophonie', [['les Notes', '/les-notes']], 'Une conception citoyenne de la Francophonie : un espace de dialogue entre les langues et les cultures.', $content, '', NOTES_PAGES);
}

function page_partenaires(): void
{
    preg_match('#<section class="mk-page mk-partners" id="partenaires">(.*?)</section>#s', index_page(), $match);
    $section = str_replace('src="assets/', 'src="/assets/', $match[1] ?? '');
    $section = (string) preg_replace(['#<p class="mk-ribbon[^"]*">.*?</p>#s', '#<h2 class="mk-partners-title">.*?</h2>#s'], '', $section, 1);
    institution_page('Nos soutiens et partenaires', [], 'Les institutions, réseaux et organisations qui accompagnent I-Dialogos.', '<div class="mk-partners inst-partners">' . $section . '</div>');
}
