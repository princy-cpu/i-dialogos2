// Interactions de la vitrine publique. Les publications et la taxonomie viennent du serveur.
const topics = [
  { id: 'ia', title: 'IA – Intelligence artificielle', image: 'artificial-intelligence.webp', question: 'Quelle intelligence artificielle, pour quelle société ?', text: 'Croiser les regards sur les usages de l’IA et ses effets sur le travail, l’éducation, la culture et la démocratie.' },
  { id: 'jeunesse', title: 'Jeunesse', image: 'youth.webp', question: 'Les jeunes, acteurs des mutations du monde', text: 'Ouvrir un espace de dialogue entre jeunes de différents pays et entre générations.' },
  { id: 'medias', title: 'Médias et pluralisme', image: 'media-pluralism.webp', question: 'Quelle information responsable ?', text: 'Comment garantir une information libre, indépendante, qualitative, humaine et pluraliste ?' },
  { id: 'francophonies', title: 'Francophonies', image: 'francophonie.webp', question: 'Quelle(s) Francophonie(s) pour demain ?', text: 'Faire vivre une Francophonie plurielle, ouverte aux sociétés civiles, aux territoires et aux jeunes générations.' },
  { id: 'democraties', title: 'Démocraties', image: 'democracies.webp', question: 'Quelles démocraties ?', text: 'Explorer les voies d’une démocratie à plusieurs niveaux, adaptée à chaque société.' },
  { id: 'ultramarins', title: 'Ultramarins', image: 'ultramarins-map.webp', question: 'Au cœur du dialogue géopolitique', text: 'Des espaces stratégiques de dialogue, de coopération régionale et de multilatéralisme.' },
  { id: 'eau', title: 'Eau, climat et environnement', image: 'water-environment.webp', question: 'L’eau, une réalité territoriale', text: 'Une gouvernance démocratique et partagée de l’eau, du climat et des ressources.' },
  { id: 'geopolitique', title: 'Géopolitique', image: 'geopolitics.webp', question: 'Comprendre un monde en mutation', text: 'Croiser les regards sur les équilibres internationaux et la recherche de solutions pacifiques.' },
  { id: 'sante', title: 'Santé et développement', image: 'health.webp', question: 'Santé, territoires et coopérations', text: 'Une approche sociale, territoriale et internationale des politiques de santé.' }
];
const menu = document.querySelector('#themes-menu');
const grid = document.querySelector('#theme-grid');
const categorySlug = value => value.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
topics.forEach((topic, index) => {
  if (menu) menu.insertAdjacentHTML('beforeend', `<a href="#${topic.id}">${topic.title}</a>`);
  const categoryUrl = `/?category=${encodeURIComponent(categorySlug(topic.title))}#articles`;
  if (grid) grid.insertAdjacentHTML('beforeend', `<article class="theme-card" id="${topic.id}"><a class="theme-image-link" href="${categoryUrl}"><img src="/assets/${topic.image}" alt="Illustration : ${topic.title}" loading="lazy"></a><div class="theme-content"><span class="theme-index">0${index + 1} / THÉMATIQUE</span><h3>${topic.title}</h3><h4>${topic.question}</h4><p>${topic.text}</p><a class="read-link" href="${categoryUrl}">Voir les publications →</a></div></article>`);
});
const date = document.querySelector('#today-date');
if (date) date.textContent = new Date().toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
const menuToggle = document.querySelector('.menu-toggle');
const nav = document.querySelector('.head nav');
menuToggle?.addEventListener('click', () => {
  const open = nav.classList.toggle('open');
  menuToggle.setAttribute('aria-expanded', String(open));
  menuToggle.textContent = open ? '×' : '☰';
});
nav?.addEventListener('click', event => {
  if (!event.target.closest('a')) return;
  nav.classList.remove('open');
  menuToggle?.setAttribute('aria-expanded', 'false');
  if (menuToggle) menuToggle.textContent = '☰';
});
const search = document.querySelector('#search-panel');
document.querySelector('#search-open')?.addEventListener('click', () => {
  search.classList.toggle('open');
  if (search.classList.contains('open')) document.querySelector('#site-search').focus();
});
document.querySelector('#search-close')?.addEventListener('click', () => search.classList.remove('open'));
search?.addEventListener('submit', event => {
  event.preventDefault();
  const term = document.querySelector('#site-search').value.trim().toLocaleLowerCase('fr');
  const cards = [...document.querySelectorAll('.public-article-card, .analysis-item, .theme-card')];
  const matches = cards.filter(card => card.textContent.toLocaleLowerCase('fr').includes(term));
  document.querySelector('#search-feedback').textContent = term ? `${matches.length} résultat${matches.length === 1 ? '' : 's'} pour « ${term} ».` : 'Saisissez un mot-clé pour lancer la recherche.';
  if (matches[0]) matches[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
});
document.querySelector('#language-button')?.addEventListener('click', event => {
  event.currentTarget.textContent = event.currentTarget.textContent.includes('Français') ? 'Langues⌄' : 'Français · English⌄';
});
const categoryFilter = document.querySelector('#public-theme-filter');
const tagFilter = document.querySelector('#public-tag-filter');
function filterArticles() {
  const category = categoryFilter?.value || '';
  const tag = tagFilter?.value || '';
  let visible = 0;
  document.querySelectorAll('.public-article-card').forEach(card => {
    const categories = (card.dataset.categories || '').split(' ');
    const tags = (card.dataset.tags || '').split(' ');
    const show = (!category || categories.includes(category)) && (!tag || tags.includes(tag));
    card.hidden = !show;
    if (show) visible++;
  });
  const count = document.querySelector('#public-article-count');
  if (count) count.textContent = `${visible} article${visible === 1 ? '' : 's'}`;
}
categoryFilter?.addEventListener('change', filterArticles);
tagFilter?.addEventListener('change', filterArticles);
const params = new URLSearchParams(location.search);
if (categoryFilter && params.has('category')) categoryFilter.value = params.get('category');
if (tagFilter && params.has('tag')) tagFilter.value = params.get('tag');
filterArticles();
document.querySelectorAll('#quick-newsletter,#newsletter-form').forEach(form => form.addEventListener('submit', event => {
  event.preventDefault();
  const feedback = form.querySelector('[aria-live="polite"]') || document.createElement('span');
  feedback.textContent = 'Inscription enregistrée pour cette démonstration.';
  if (!feedback.isConnected) form.append(feedback);
  form.reset();
}));
