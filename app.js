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
// Rubriques dépliantes : en ouvrir une referme les autres du même groupe (menu principal, listes du réseau…).
// L'attribut name des <details> le fait déjà dans les navigateurs récents ; ce code couvre les autres.
document.querySelectorAll('details').forEach(item => item.addEventListener('toggle', () => {
  if (!item.open) return;
  const group = item.closest('nav, ul, ol, section, div');
  group?.querySelectorAll('details[open]').forEach(other => {
    if (other !== item && !other.contains(item) && !item.contains(other)) other.open = false;
  });
}));
// Menu principal : un clic ailleurs ou la touche Échap referme la rubrique ouverte.
document.addEventListener('click', event => {
  document.querySelectorAll('.head nav details[open]').forEach(item => { if (!item.contains(event.target)) item.open = false; });
});
document.addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  const open = document.querySelector('.head nav details[open]');
  if (open) { open.open = false; open.querySelector('summary').focus(); }
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
  // La recherche du site se fait dans la liste des analyses (titre et extrait, texte original).
  if (query) location.href = `/analyses?q=${encodeURIComponent(query)}`;
  else document.querySelector('#search-feedback').textContent = 'Saisissez un mot-clé pour lancer la recherche.';
});
document.querySelector('#language-button')?.addEventListener('click', event => {
  event.currentTarget.textContent = event.currentTarget.textContent.includes('Français') ? 'Langues⌄' : 'Français · English⌄';
});
// Pages de thématique : les articles s'affichent par 12 avec « Voir plus d'articles »
// (les listes /analyses et /actualites sont paginées par le serveur).
const themeGrid = document.querySelector('.theme-articles');
if (themeGrid) {
  const PAGE_SIZE = 12;
  let shown = PAGE_SIZE;
  const cards = [...themeGrid.querySelectorAll('.public-article-card')];
  const moreButton = document.createElement('button');
  moreButton.type = 'button';
  moreButton.className = 'theme-button more-articles';
  moreButton.textContent = 'Voir plus d’articles';
  themeGrid.after(moreButton);
  const showPage = () => {
    cards.forEach((card, index) => { card.hidden = index >= shown; });
    moreButton.hidden = cards.length <= shown;
  };
  moreButton.addEventListener('click', () => {
    shown += PAGE_SIZE;
    showPage();
  });
  showPage();
}

// Newsletter : envoi au serveur (/newsletter), message de confirmation ou d'erreur affiché sous le formulaire.
// Sans JavaScript, le formulaire est envoyé normalement et le serveur répond par une page.
document.querySelectorAll('#quick-newsletter,#newsletter-form').forEach(form => form.addEventListener('submit', async event => {
  event.preventDefault();
  const feedback = form.querySelector('[aria-live="polite"]');
  const input = form.querySelector('input[type="email"]');
  const button = form.querySelector('button[type="submit"]');
  const show = (message, ok) => {
    feedback.textContent = message;
    feedback.classList.toggle('is-error', !ok);
    feedback.classList.toggle('is-success', ok);
  };
  // La validation (et le message, dans la langue du visiteur) est faite par le serveur.
  button.disabled = true;
  try {
    const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
    const result = await response.json();
    show(result.message, result.ok);
    if (result.ok) form.reset();
    else input.focus();
  } catch (_) {
    show('Une erreur est survenue. Réessayez dans un instant.', false);
  } finally {
    button.disabled = false;
  }
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
// Retour en haut : un vrai défilement jusqu'à 0. L'ancienne ancre #accueil visait l'en-tête « collant »,
// vers lequel les navigateurs ne défilent pas de façon fiable.
const scrollToTop = () => {
  const smooth = !matchMedia('(prefers-reduced-motion: reduce)').matches;
  try {
    window.scrollTo({ top: 0, left: 0, behavior: smooth ? 'smooth' : 'auto' });
  } catch (_) {
    window.scrollTo(0, 0);
  }
  if (location.hash) history.replaceState(null, '', location.pathname + location.search);
  // Le focus revient en haut de page pour les personnes qui naviguent au clavier.
  const logo = document.querySelector('.head .logo');
  logo?.focus({ preventScroll: true });
};
const toTop = document.createElement('button');
toTop.type = 'button';
toTop.className = 'to-top';
toTop.setAttribute('aria-label', 'Retour en haut');
toTop.textContent = '↑';
toTop.addEventListener('click', scrollToTop);
document.body.append(toTop);
document.querySelectorAll('.back-top').forEach(link => link.addEventListener('click', event => {
  event.preventDefault();
  scrollToTop();
}));
// Logo : sur l'accueil, retour en haut de page ; ailleurs, le lien « / » ouvre l'accueil en haut.
document.querySelectorAll('.logo').forEach(logo => logo.addEventListener('click', event => {
  if (location.pathname !== '/' || location.search) return;
  event.preventDefault();
  scrollToTop();
}));
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

// « S'abonner à la newsletter » (pied de page) : défile jusqu'au formulaire et place le curseur dans le champ e-mail.
// Depuis une autre page, le lien ouvre /#infolettre et le champ reçoit le focus au chargement.
const focusNewsletter = () => {
  const section = document.querySelector('#infolettre');
  const input = document.querySelector('#newsletter-form input[type="email"]');
  if (!section || !input) return false;
  section.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
  input.focus({ preventScroll: true });
  return true;
};
document.querySelectorAll('[data-newsletter-link]').forEach(link => link.addEventListener('click', event => {
  if (focusNewsletter()) event.preventDefault();
}));
if (location.hash === '#infolettre') addEventListener('load', focusNewsletter);
