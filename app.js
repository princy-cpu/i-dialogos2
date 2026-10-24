// Interactions de la vitrine publique. Les publications et la taxonomie viennent du serveur.
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
  const cards = [...document.querySelectorAll('.public-article-card, .analysis-item, .mk-theme')];
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
// Experts : défilé automatique (maquette 14). Les fiches sont dupliquées pour une boucle continue.
const expertTrack = document.querySelector('.mk-expert-track');
if (expertTrack && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
  [...expertTrack.children].forEach(card => {
    const copy = card.cloneNode(true);
    copy.setAttribute('aria-hidden', 'true');
    expertTrack.append(copy);
  });
  expertTrack.classList.add('is-scrolling');
}
