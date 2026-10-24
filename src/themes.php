<?php
/** Thématiques (maquettes 7 à 13, dossier « PAGES Site 13.10.26 ») et repères des sections fixes d'index.html. */
declare(strict_types=1);

function theme_data(): array
{
    static $data = null;
    return $data ??= json_decode(<<<'JSON'
{
 "themes": [
  {
   "id": "medias",
   "title": "Médias et pluralisme",
   "question": "",
   "text": "Dans un Monde en pleine mutation et aux enjeux considérables pour l’avenir de l’Humanité, comment garantir une information « responsable », qui soit une information libre, indépendante, qualitative, humaine et respectueuse du pluralisme ?",
   "text2": "",
   "signature": "",
   "button": "Médias et pluralisme",
   "image": "media-pluralism.webp",
   "category": "medias-et-pluralisme"
  },
  {
   "id": "francophonies",
   "title": "Francophonies",
   "question": "Quelle(s) Francophonie(s) pour demain ?",
   "text": "I-Dialogos entend contribuer, dans le cadre d’une approche transnationale, à une Francophonie renouvelée, moins institutionnelle et davantage ouverte aux sociétés civiles, aux territoires et aux jeunes générations. Une Francophonie plurielle, attentive à la diversité des cultures et des langues, favorisant la circulation des idées et un dialogue équilibré entre les peuples.",
   "text2": "",
   "signature": "Jean-Claude Mairal",
   "button": "Francophonie",
   "image": "francophonie.webp",
   "category": "francophonies"
  },
  {
   "id": "democraties",
   "title": "Démocraties",
   "question": "Quelles démocraties ?",
   "text": "À partir des expériences existantes, I-Dialogos explore, dans le cadre d’une approche transnationale croisée, les voies d’une démocratie à plusieurs niveaux, adaptée à chaque société.",
   "text2": "Dialogue et écoute sont essentiels pour en préserver l’esprit et prévenir les dérives autoritaires ou impérialistes.",
   "signature": "",
   "button": "Quelles démocraties ?",
   "image": "democracies.webp",
   "category": "democraties"
  },
  {
   "id": "ia",
   "title": "IA – Intelligence artificielle",
   "question": "Quelle intelligence artificielle, pour quelle société ?",
   "text": "Comment mettre l’intelligence artificielle au service de l’humain, sans renforcer les inégalités, les dépendances technologiques ni les manipulations de l’information ?",
   "text2": "I-Dialogos propose de croiser les regards sur ses usages et ses effets sur le travail, l’éducation, la culture et la démocratie, en interrogeant la place des langues, la diversité des savoirs et la responsabilité des acteurs.",
   "signature": "",
   "button": "Intelligence artificielle",
   "image": "artificial-intelligence.webp",
   "category": "ia-intelligence-artificielle"
  },
  {
   "id": "jeunesse",
   "title": "Jeunesse",
   "question": "Les jeunes, acteurs des mutations du monde",
   "text": "Comment permettre aux jeunes, dans la diversité de leurs cultures et de leurs parcours, de faire entendre leur voix et de participer aux décisions qui engagent leur avenir ?",
   "text2": "I-Dialogos souhaite ouvrir un espace de dialogue entre jeunes de différents pays et entre générations, autour de l’éducation, de l’emploi, de la culture, du numérique, de l’environnement et de la paix.",
   "signature": "",
   "button": "Jeunesse",
   "image": "youth.webp",
   "category": "jeunesse"
  },
  {
   "id": "ultramarins",
   "title": "Ultramarins",
   "question": "Territoires ultramarins : au cœur du dialogue géopolitique",
   "text": "Longtemps considérés comme périphériques, les territoires ultramarins occupent une place stratégique dans les équilibres géopolitiques, maritimes, environnementaux et culturels. À la rencontre de plusieurs mondes, ils constituent des espaces privilégiés de dialogue, de coopération régionale et de multilatéralisme.",
   "text2": "",
   "signature": "",
   "button": "Territoires ultramarins",
   "image": "ultramarins-map.webp",
   "category": "ultramarins"
  },
  {
   "id": "eau",
   "title": "Eau, climat et environnement",
   "question": "L’eau, une réalité territoriale",
   "text": "Face au dérèglement climatique, sa gestion exige une gouvernance démocratique et partagée, associant États, territoires et sociétés civiles, au service de la coopération, de la paix et du développement durable.",
   "text2": "",
   "signature": "Abdoulaye Sène",
   "button": "Eau, climat et environnement",
   "image": "water-environment.webp",
   "category": "eau-climat-et-environnement"
  },
  {
   "id": "geopolitique",
   "title": "Géopolitique",
   "question": "Comprendre un monde en mutation",
   "text": "Dans un monde marqué par de profondes recompositions, I-Dialogos propose une lecture pluraliste des équilibres internationaux. En croisant les regards issus de différents continents, le think tank défend le dialogue, le multilatéralisme et le rôle des Nations unies dans la recherche de solutions pacifiques et partagées.",
   "text2": "",
   "signature": "",
   "button": "Géopolitique",
   "image": "geopolitics.webp",
   "category": "geopolitique"
  },
  {
   "id": "sante",
   "title": "Santé et développement",
   "question": "Santé, territoires et coopérations",
   "text": "Une approche sociale, territoriale et internationale des politiques de santé, en lien avec la société civile et la démocratie.",
   "text2": "Une démarche comparative intégrant des conceptions adaptées du service public.",
   "signature": "",
   "button": "Santé et développement",
   "image": "health.webp",
   "category": "sante-et-developpement"
  }
 ],
 "fields": [
  "title",
  "question",
  "text",
  "text2",
  "button"
 ],
 "aliases": {
  "international": "geopolitique"
 },
 "sections": [
  [
   "<!-- 2 – Le réseau -->",
   "<!-- 7 à 13 – Thématiques -->"
  ],
  [
   "<!-- 14 – Experts -->",
   "  </main>"
  ]
 ]
}
JSON, true, 512, JSON_THROW_ON_ERROR);
}
