// Import d'articles depuis des PDF (espace adhérent). Le texte est extrait dans le navigateur avec pdf.js : titre (plus
// grand texte de la première page), intertitres (texte plus grand que le corps), paragraphes, listes à puces ; en-têtes
// et pieds de page répétés et numéros de page retirés. La première page peut servir d'image mise en avant.
//  - Éditeur d'article : l'encadré « Importer depuis un PDF » remplit le formulaire, qui reste à relire et enregistrer.
//  - /admin/articles/import : chaque PDF devient un brouillon (envoi à /admin/articles/save avec ajax=1).
import * as pdfjs from './vendor/pdfjs/pdf.min.mjs';

pdfjs.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.mjs';

const escapeHtml = text => text.replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
const BULLET = /^\s*([•●▪◦■–\-*]|\d{1,2}[.)])\s+/u;

/** Lignes d'une page : morceaux de texte regroupés par position verticale, avec leur taille de police. */
async function pageLines(page) {
  const content = await page.getTextContent();
  const pieces = content.items.filter(item => item.str && item.str.trim() !== '').map(item => ({
    text: item.str, x: item.transform[4], y: item.transform[5], size: Math.round(Math.hypot(item.transform[2], item.transform[3]) * 10) / 10 || item.height, width: item.width,
  }));
  pieces.sort((a, b) => (Math.abs(b.y - a.y) > Math.min(a.size, b.size) * 0.5 ? b.y - a.y : a.x - b.x));
  const lines = [];
  for (const piece of pieces) {
    const line = lines[lines.length - 1];
    if (line && Math.abs(line.y - piece.y) <= Math.min(line.size, piece.size) * 0.5) {
      const gap = piece.x - line.end;
      line.text += (gap > piece.size * 0.15 && !line.text.endsWith(' ') && !piece.text.startsWith(' ') ? ' ' : '') + piece.text;
      line.end = piece.x + piece.width;
      line.size = Math.max(line.size, piece.size);
    } else {
      lines.push({ text: piece.text, x: piece.x, y: piece.y, end: piece.x + piece.width, size: piece.size });
    }
  }
  return lines.map(line => ({ ...line, text: line.text.replace(/\s+/g, ' ').trim() })).filter(line => line.text !== '');
}

/** Texte « signature » d'une ligne pour repérer les en-têtes et pieds de page répétés (chiffres ignorés). */
const signature = text => text.toLowerCase().replace(/\d+/g, '#').replace(/\s+/g, ' ');

export async function extractPdf(file, { cover = false } = {}) {
  const doc = await pdfjs.getDocument({ data: await file.arrayBuffer(), isEvalSupported: false }).promise;
  const pages = [];
  for (let number = 1; number <= doc.numPages; number++) {
    pages.push(await pageLines(await doc.getPage(number)));
  }
  // En-têtes et pieds de page : lignes identiques (aux chiffres près) en haut ou en bas de plus de la moitié des pages.
  const counts = new Map();
  pages.forEach(lines => {
    const edges = new Set([...lines.slice(0, 2), ...lines.slice(-2)].map(line => signature(line.text)));
    edges.forEach(key => counts.set(key, (counts.get(key) || 0) + 1));
  });
  const repeated = new Set([...counts].filter(([, total]) => doc.numPages >= 3 && total > doc.numPages / 2).map(([key]) => key));
  const all = pages.map(lines => lines.filter(line => !repeated.has(signature(line.text)) && !/^(page\s*)?\d{1,4}(\s*\/\s*\d{1,4})?$/i.test(line.text)));
  // Taille du corps de texte : la plus fréquente (pondérée par le nombre de caractères).
  const weights = new Map();
  all.flat().forEach(line => weights.set(line.size, (weights.get(line.size) || 0) + line.text.length));
  const body = [...weights].sort((a, b) => b[1] - a[1])[0]?.[0] || 10;
  // Titre : le plus grand texte de la première page (lignes consécutives de cette taille).
  let title = '';
  const firstPage = all[0] || [];
  const biggest = Math.max(0, ...firstPage.map(line => line.size));
  if (biggest > body * 1.15) {
    const start = firstPage.findIndex(line => line.size === biggest);
    let end = start;
    while (end + 1 < firstPage.length && firstPage[end + 1].size === biggest && end - start < 3) end++;
    title = firstPage.slice(start, end + 1).map(line => line.text).join(' ');
    firstPage.splice(start, end - start + 1);
  }
  // Blocs : intertitres, paragraphes et listes, à partir des écarts verticaux et des tailles.
  const blocks = [];
  for (const lines of all) {
    let previous = null;
    for (const line of lines) {
      const heading = line.size >= body * 1.2 && line.text.length < 160;
      const bullet = BULLET.test(line.text);
      const gap = previous ? previous.y - line.y : 0;
      const current = blocks[blocks.length - 1];
      const sameBlock = current && previous && !heading && !bullet && current.type !== 'heading' && gap > 0 && gap < Math.max(line.size, previous.size) * 1.75;
      const sameHeading = current && previous && heading && current.type === 'heading' && current.size === line.size && gap > 0 && gap < line.size * 1.8;
      if (sameBlock || sameHeading) {
        current.text = current.text.endsWith('-') && /^[a-zà-ÿ]/.test(line.text) ? current.text.slice(0, -1) + line.text : `${current.text} ${line.text}`;
      } else {
        blocks.push({ type: heading ? 'heading' : (bullet ? 'item' : 'paragraph'), size: line.size, text: bullet ? line.text.replace(BULLET, '') : line.text });
      }
      previous = line;
    }
  }
  const headingSizes = [...new Set(blocks.filter(block => block.type === 'heading').map(block => block.size))].sort((a, b) => b - a);
  if (!title) {
    const first = blocks.findIndex(block => block.type === 'heading');
    if (first >= 0 && first < 3) title = blocks.splice(first, 1)[0].text;
    else title = (blocks.find(block => block.type === 'paragraph')?.text || file.name.replace(/\.pdf$/i, '')).slice(0, 140);
  }
  let html = '';
  let list = false;
  for (const block of blocks) {
    if (block.type === 'item' && !list) { html += '<ul>'; list = true; }
    if (block.type !== 'item' && list) { html += '</ul>'; list = false; }
    if (block.type === 'heading') {
      const level = headingSizes.indexOf(block.size) === 0 && headingSizes.length > 1 ? 'h2' : (headingSizes.length > 1 ? 'h3' : 'h2');
      html += `<${level}>${escapeHtml(block.text)}</${level}>`;
    } else if (block.type === 'item') {
      html += `<li>${escapeHtml(block.text)}</li>`;
    } else {
      html += `<p>${escapeHtml(block.text)}</p>`;
    }
  }
  if (list) html += '</ul>';
  const firstParagraph = blocks.find(block => block.type === 'paragraph' && block.text.length > 60)?.text || '';
  let excerpt = firstParagraph;
  if (excerpt.length > 480) {
    const cut = excerpt.slice(0, 480);
    const sentence = Math.max(cut.lastIndexOf('. '), cut.lastIndexOf('! '), cut.lastIndexOf('? '));
    excerpt = sentence > 200 ? cut.slice(0, sentence + 1) : `${cut.slice(0, cut.lastIndexOf(' '))}…`;
  }
  let coverFile = null;
  if (cover) {
    const page = await doc.getPage(1);
    const base = page.getViewport({ scale: 1 });
    const viewport = page.getViewport({ scale: Math.min(3, 1400 / base.width) });
    const canvas = document.createElement('canvas');
    canvas.width = Math.floor(viewport.width);
    canvas.height = Math.floor(viewport.height);
    const context = canvas.getContext('2d');
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    await page.render({ canvasContext: context, viewport }).promise;
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.86));
    if (blob) coverFile = new File([blob], 'couverture.jpg', { type: 'image/jpeg' });
  }
  const text = blocks.map(block => block.text).join(' ');
  const pageCount = doc.numPages;
  await doc.destroy();
  return { title: title.slice(0, 180), excerpt: excerpt.slice(0, 500), html, cover: coverFile, words: text ? text.split(/\s+/).length : 0, pages: pageCount };
}

/** Ajoute des fichiers à un champ <input type="file"> (en gardant ceux déjà choisis si multiple). */
function addFiles(input, files, keep = true) {
  if (!input || typeof DataTransfer !== 'function') return false;
  const transfer = new DataTransfer();
  if (keep && input.multiple) [...input.files].forEach(file => transfer.items.add(file));
  files.forEach(file => transfer.items.add(file));
  input.files = transfer.files;
  input.dispatchEvent(new Event('change', { bubbles: true }));
  return true;
}

// Éditeur d'article : remplir le formulaire à partir d'un PDF.
const panel = document.querySelector('[data-pdf-import]');
if (panel) {
  const source = panel.querySelector('[data-pdf-source]');
  const run = panel.querySelector('[data-pdf-run]');
  const status = panel.querySelector('.pdf-import-status');
  source.addEventListener('change', () => { run.disabled = !source.files.length; status.textContent = ''; });
  run.addEventListener('click', async () => {
    const file = source.files[0];
    if (!file) return;
    run.disabled = true;
    status.textContent = 'Lecture du PDF…';
    try {
      const form = document.querySelector('#article-editor-form');
      const editor = document.querySelector('#rich-editor');
      const featured = form.querySelector('input[name="featured_image"]');
      const wantCover = panel.querySelector('[data-pdf-cover]').checked && !form.querySelector('.featured-preview') && !featured.files.length;
      const result = await extractPdf(file, { cover: wantCover });
      if (!result.html) {
        status.textContent = 'Aucun texte trouvé : ce PDF est sans doute une image scannée. Le PDF peut tout de même être joint à l’article.';
      }
      const title = form.querySelector('input[name="title"]');
      if (result.title && (!title.value.trim() || confirm(`Remplacer le titre par « ${result.title} » ?`))) title.value = result.title;
      const excerpt = form.querySelector('textarea[name="excerpt"]');
      if (result.excerpt && !excerpt.value.trim()) excerpt.value = result.excerpt;
      // Contenu vide ou simple structure du modèle (pas encore modifiée) : remplacé sans question.
      const template = document.createElement('div');
      template.innerHTML = form.querySelector('select[name="template_id"]')?.selectedOptions[0]?.dataset.body || '';
      const plain = node => node.textContent.replace(/\s+/g, '');
      const untouched = !plain(editor) || plain(editor) === plain(template);
      if (result.html && (untouched || confirm('Remplacer le contenu actuel de l’article par le texte du PDF ?'))) editor.innerHTML = result.html;
      if (panel.querySelector('[data-pdf-attach]').checked) addFiles(form.querySelector('input[name="pdf_files[]"]'), [file]);
      if (result.cover) addFiles(featured, [result.cover], false);
      if (result.html) status.textContent = `Texte importé : ${result.pages} page(s), ${result.words} mots. Relisez l’article puis enregistrez-le.`;
    } catch (error) {
      status.textContent = 'Ce fichier n’a pas pu être lu (PDF protégé ou endommagé).';
    }
    run.disabled = false;
  });
}

// Import groupé : un brouillon par PDF.
const bulk = document.querySelector('[data-pdf-bulk]');
if (bulk) {
  const results = document.querySelector('.pdf-import-results');
  bulk.addEventListener('submit', async event => {
    event.preventDefault();
    const files = [...bulk.querySelector('[data-pdf-files]').files];
    if (!files.length) return;
    const button = bulk.querySelector('button[type="submit"]');
    button.disabled = true;
    const attach = bulk.querySelector('[data-pdf-attach]').checked;
    const cover = bulk.querySelector('[data-pdf-cover]').checked;
    for (const file of files) {
      const row = document.createElement('li');
      row.textContent = `${file.name} : lecture…`;
      results.append(row);
      try {
        const result = await extractPdf(file, { cover });
        const data = new FormData();
        data.set('_csrf', bulk.querySelector('input[name="_csrf"]').value);
        data.set('ajax', '1');
        data.set('title', result.title || file.name.replace(/\.pdf$/i, ''));
        data.set('excerpt', result.excerpt);
        data.set('body_html', result.html);
        data.set('author', bulk.querySelector('input[name="author"]').value);
        data.set('status', 'draft');
        data.set('language', 'auto');
        data.set('comments_enabled', '1');
        const category = bulk.querySelector('select[name="categories[]"]').value;
        if (category) data.append('categories[]', category);
        if (attach) data.append('pdf_files[]', file);
        if (result.cover) data.set('featured_image', result.cover);
        row.textContent = `${file.name} : enregistrement…`;
        const response = await fetch('/admin/articles/save', { method: 'POST', body: data, credentials: 'same-origin' });
        const answer = await response.json().catch(() => ({ error: `Erreur ${response.status}` }));
        if (!response.ok || !answer.id) throw new Error(answer.error || `Erreur ${response.status}`);
        row.className = 'is-done';
        row.textContent = `✓ ${data.get('title')} — brouillon créé${result.html ? '' : ' (aucun texte trouvé : PDF scanné ?)'} · `;
        const link = document.createElement('a');
        link.href = answer.edit;
        link.textContent = 'Relire et publier';
        row.append(link);
      } catch (error) {
        row.className = 'is-error';
        row.textContent = `✗ ${file.name} : ${error.message || 'lecture impossible'}`;
      }
    }
    button.disabled = false;
  });
}
