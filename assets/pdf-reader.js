// Liseuse PDF des articles : les liens « Lire le PDF » (data-pdf-read) ouvrent le document dans une fenêtre de lecture
// (pdf.js, pages affichées au fil du défilement, zoom, téléchargement). Si ce module ne se charge pas, les liens ouvrent
// simplement le PDF dans un nouvel onglet.
import * as pdfjs from './vendor/pdfjs/pdf.min.mjs';

pdfjs.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.mjs';

const ZOOMS = [0.6, 0.8, 1, 1.25, 1.5, 2];
let dialog;
let state = null;

function el(tag, attributes = {}, text = '') {
  const node = document.createElement(tag);
  Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, value));
  if (text) node.textContent = text;
  return node;
}

function buildDialog() {
  dialog = el('dialog', { class: 'pdf-reader', 'aria-labelledby': 'pdf-reader-title' });
  const bar = el('div', { class: 'pdf-reader-bar' });
  const title = el('h2', { id: 'pdf-reader-title' });
  const tools = el('div', { class: 'pdf-reader-tools' });
  const info = el('span', { class: 'pdf-reader-page', 'aria-live': 'polite' });
  const zoomOut = el('button', { type: 'button', 'aria-label': 'Réduire', title: 'Réduire' }, '−');
  const zoomLabel = el('span', { class: 'pdf-reader-zoom' }, '100 %');
  const zoomIn = el('button', { type: 'button', 'aria-label': 'Agrandir', title: 'Agrandir' }, '+');
  const download = el('a', { class: 'pdf-reader-download', download: '' }, 'Télécharger');
  const close = el('button', { type: 'button', class: 'pdf-reader-close', 'aria-label': 'Fermer la liseuse' }, '×');
  tools.append(info, zoomOut, zoomLabel, zoomIn, download, close);
  bar.append(title, tools);
  const pages = el('div', { class: 'pdf-reader-pages', tabindex: '0' });
  dialog.append(bar, pages);
  document.body.append(dialog);
  close.addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => { state?.doc.destroy(); state = null; pages.replaceChildren(); document.body.classList.remove('pdf-reader-open'); });
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  zoomOut.addEventListener('click', () => setZoom(-1));
  zoomIn.addEventListener('click', () => setZoom(1));
  pages.addEventListener('scroll', () => {
    if (!state) return;
    const middle = pages.scrollTop + pages.clientHeight / 3;
    const current = [...pages.children].findIndex(page => page.offsetTop + page.offsetHeight > middle);
    info.textContent = `Page ${Math.max(1, current + 1)} / ${state.doc.numPages}`;
  }, { passive: true });
  return { title, info, zoomLabel, download, pages };
}

let parts;

function pageWidth() {
  return Math.min(parts.pages.clientWidth - 24, 980) * ZOOMS[state.zoom];
}

async function renderPage(slot) {
  if (!state || slot.dataset.rendered === String(state.zoom)) return;
  slot.dataset.rendered = String(state.zoom);
  const page = await state.doc.getPage(Number(slot.dataset.page));
  const base = page.getViewport({ scale: 1 });
  const scale = pageWidth() / base.width;
  const viewport = page.getViewport({ scale });
  const ratio = Math.min(window.devicePixelRatio || 1, 2);
  const canvas = el('canvas', { 'aria-label': `Page ${slot.dataset.page}` });
  canvas.width = Math.floor(viewport.width * ratio);
  canvas.height = Math.floor(viewport.height * ratio);
  canvas.style.width = `${Math.floor(viewport.width)}px`;
  canvas.style.height = `${Math.floor(viewport.height)}px`;
  await page.render({ canvasContext: canvas.getContext('2d'), viewport, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null }).promise;
  slot.style.width = canvas.style.width;
  slot.style.height = canvas.style.height;
  slot.replaceChildren(canvas);
}

async function layout() {
  const first = await state.doc.getPage(1);
  const base = first.getViewport({ scale: 1 });
  const width = pageWidth();
  const height = width * base.height / base.width;
  state.observer?.disconnect();
  state.observer = new IntersectionObserver(entries => entries.forEach(entry => { if (entry.isIntersecting) renderPage(entry.target); }), { root: parts.pages, rootMargin: '600px 0px' });
  const slots = [];
  for (let number = 1; number <= state.doc.numPages; number++) {
    const slot = el('div', { class: 'pdf-reader-sheet', 'data-page': String(number) });
    slot.style.width = `${Math.floor(width)}px`;
    slot.style.height = `${Math.floor(height)}px`;
    slots.push(slot);
  }
  parts.pages.replaceChildren(...slots);
  slots.forEach(slot => state.observer.observe(slot));
  parts.zoomLabel.textContent = `${Math.round(ZOOMS[state.zoom] * 100)} %`;
  parts.info.textContent = `Page 1 / ${state.doc.numPages}`;
}

function setZoom(step) {
  if (!state) return;
  const next = Math.min(ZOOMS.length - 1, Math.max(0, state.zoom + step));
  if (next === state.zoom) return;
  const position = parts.pages.scrollTop / Math.max(1, parts.pages.scrollHeight);
  state.zoom = next;
  layout().then(() => { parts.pages.scrollTop = position * parts.pages.scrollHeight; });
}

async function openReader(url, title) {
  parts ??= buildDialog();
  parts.title.textContent = title || 'Document PDF';
  parts.download.href = url;
  parts.info.textContent = 'Chargement…';
  parts.pages.replaceChildren(el('p', { class: 'pdf-reader-loading' }, 'Chargement du document…'));
  document.body.classList.add('pdf-reader-open');
  dialog.showModal();
  try {
    const doc = await pdfjs.getDocument({ url, isEvalSupported: false }).promise;
    state = { doc, zoom: 2, observer: null };
    await layout();
    parts.pages.focus();
  } catch (error) {
    parts.info.textContent = '';
    const message = el('p', { class: 'pdf-reader-loading' }, 'Le document ne peut pas être affiché ici. ');
    const link = el('a', { href: url, target: '_blank', rel: 'noopener' }, 'Ouvrir le PDF dans un nouvel onglet');
    message.append(link);
    parts.pages.replaceChildren(message);
  }
}

document.addEventListener('click', event => {
  const link = event.target.closest('[data-pdf-read]');
  if (!link || event.ctrlKey || event.metaKey || event.shiftKey || typeof HTMLDialogElement !== 'function') return;
  event.preventDefault();
  openReader(link.dataset.pdfRead, link.dataset.pdfTitle);
});
