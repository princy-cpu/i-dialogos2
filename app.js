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
  const query = document.querySelector('#site-search').value.trim();
  // Hors de l'accueil (article, thématique…), la recherche se fait sur la page d'accueil.
  if (location.pathname !== '/' && query) {
    location.href = `/?q=${encodeURIComponent(query)}`;
    return;
  }
  const term = query.toLocaleLowerCase('fr');
  const cards = [...document.querySelectorAll('.public-article-card, .analysis-item, .mk-theme')];
  const matches = cards.filter(card => card.textContent.toLocaleLowerCase('fr').includes(term));
  // Les articles trouvés au-delà des 12 premiers sont affichés.
  matches.forEach(card => { if (card.classList.contains('public-article-card')) card.hidden = false; });
  document.querySelector('#search-feedback').textContent = term ? `${matches.length} résultat${matches.length === 1 ? '' : 's'} pour « ${term} ».` : 'Saisissez un mot-clé pour lancer la recherche.';
  if (matches[0]) matches[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
});
document.querySelector('#language-button')?.addEventListener('click', event => {
  event.currentTarget.textContent = event.currentTarget.textContent.includes('Français') ? 'Langues⌄' : 'Français · English⌄';
});
const categoryFilter = document.querySelector('#public-theme-filter');
const tagFilter = document.querySelector('#public-tag-filter');
// Les articles s'affichent par pages de 12 ; les filtres portent sur l'ensemble des articles.
const PAGE_SIZE = 12;
let shownArticles = PAGE_SIZE;
const articleGrid = document.querySelector('#public-feed') || document.querySelector('.public-article-grid');
const moreButton = document.createElement('button');
moreButton.type = 'button';
moreButton.className = 'theme-button more-articles';
moreButton.textContent = 'Voir plus d’articles';
moreButton.hidden = true;
articleGrid?.after(moreButton);
moreButton.addEventListener('click', () => {
  shownArticles += PAGE_SIZE;
  filterArticles(false);
});
function filterArticles(resetPage = true) {
  if (resetPage) shownArticles = PAGE_SIZE;
  const category = categoryFilter?.value || '';
  const tag = tagFilter?.value || '';
  let matching = 0;
  document.querySelectorAll('.public-article-card').forEach(card => {
    const categories = (card.dataset.categories || '').split(' ');
    const tags = (card.dataset.tags || '').split(' ');
    const matches = (!category || categories.includes(category)) && (!tag || tags.includes(tag));
    if (matches) matching++;
    card.hidden = !matches || matching > shownArticles;
  });
  moreButton.hidden = matching <= shownArticles;
  const count = document.querySelector('#public-article-count');
  if (count) count.textContent = `${matching} article${matching === 1 ? '' : 's'}`;
}
categoryFilter?.addEventListener('change', () => filterArticles());
tagFilter?.addEventListener('change', () => filterArticles());
const params = new URLSearchParams(location.search);
if (categoryFilter && params.has('category')) categoryFilter.value = params.get('category');
if (tagFilter && params.has('tag')) tagFilter.value = params.get('tag');
filterArticles();
if (search && params.get('q')) {
  search.classList.add('open');
  document.querySelector('#site-search').value = params.get('q');
  search.requestSubmit();
}
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

// === Dynamique de la page : en-tête condensé, progression, apparitions, compteurs, menu actif ===
const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
const siteHeader = document.querySelector('header#accueil');
const progressBar = document.createElement('div');
progressBar.className = 'scroll-progress';
progressBar.setAttribute('aria-hidden', 'true');
document.body.prepend(progressBar);
const toTop = document.createElement('a');
toTop.href = '#accueil';
toTop.className = 'to-top';
toTop.setAttribute('aria-label', 'Retour en haut');
toTop.textContent = '↑';
document.body.append(toTop);
let scrollQueued = false;
const updateOnScroll = () => {
  scrollQueued = false;
  const y = scrollY;
  const max = document.documentElement.scrollHeight - innerHeight;
  progressBar.style.setProperty('--progress', max > 0 ? (y / max).toFixed(4) : '0');
  // Hystérésis : l'en-tête rétrécit, ce qui décale la page ; deux seuils évitent l'effet de clignotement.
  if (siteHeader) {
    if (y > 160) siteHeader.classList.add('is-condensed');
    else if (y < 40) siteHeader.classList.remove('is-condensed');
  }
  toTop.classList.toggle('is-visible', y > 900);
};
addEventListener('scroll', () => {
  if (!scrollQueued) {
    scrollQueued = true;
    requestAnimationFrame(updateOnScroll);
  }
}, { passive: true });
updateOnScroll();

if (!reduceMotion && 'IntersectionObserver' in window) {
  // Apparition progressive des blocs, en cascade entre éléments voisins.
  const revealSelector = '.featured-article, .event-box, .analysis-item, .morethemes h2, .themegrid a, .mk-network-title, .mk-network-intro, .mk-stats li, .mk-map, .mk-about-main, .mk-about-notes, .mk-governance article, .mk-page-title, .mk-split, #invitee > .mk-more, .section-heading, .public-article-card, .mk-experts-head, .mk-expert-filters a, .mk-partners-title, .mk-partner-grid li, .mk-closing h2, .mk-closing form';
  document.documentElement.classList.add('js-reveal');
  const revealObserver = new IntersectionObserver(entries => entries.forEach(entry => {
    if (!entry.isIntersecting) return;
    const element = entry.target;
    revealObserver.unobserve(element);
    element.classList.add('is-visible');
    // Une fois l'animation finie, on rend la main aux effets de survol.
    const delay = parseFloat(element.style.getPropertyValue('--reveal-delay')) || 0;
    setTimeout(() => {
      element.removeAttribute('data-reveal');
      element.classList.remove('is-visible');
      element.style.removeProperty('--reveal-delay');
    }, 950 + delay);
  }), { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });
  document.querySelectorAll(revealSelector).forEach(element => {
    const siblings = [...element.parentElement.children].filter(child => child.matches(revealSelector));
    element.style.setProperty('--reveal-delay', `${Math.min(siblings.indexOf(element), 7) * 70}ms`);
    element.dataset.reveal = '';
    revealObserver.observe(element);
  });

  // Compteurs animés (chiffres clés du réseau).
  document.querySelectorAll('[data-count]').forEach(counter => {
    const target = Number(counter.dataset.count);
    counter.textContent = '0';
    const counterObserver = new IntersectionObserver(entries => {
      if (!entries[0].isIntersecting) return;
      counterObserver.disconnect();
      const start = performance.now();
      const step = now => {
        const progress = Math.min((now - start) / 1400, 1);
        counter.textContent = String(Math.round(target * (1 - Math.pow(1 - progress, 3))));
        if (progress < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    }, { threshold: 0.6 });
    counterObserver.observe(counter);
  });
}

// Menu : lien de la section affichée à l'écran mis en évidence.
if ('IntersectionObserver' in window) {
  const navLinks = [...document.querySelectorAll('.head nav > a[href^="#"]')];
  const sectionLinks = new Map(navLinks.map(link => [document.querySelector(link.getAttribute('href')), link]).filter(([section]) => section));
  const spy = new IntersectionObserver(entries => entries.forEach(entry => {
    const link = sectionLinks.get(entry.target);
    if (entry.isIntersecting) {
      navLinks.forEach(item => item.classList.remove('is-active'));
      link.classList.add('is-active');
    } else {
      link.classList.remove('is-active');
    }
  }), { rootMargin: '-45% 0px -50% 0px' });
  sectionLinks.forEach((_, section) => spy.observe(section));
}
