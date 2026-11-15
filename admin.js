// Images trop lourdes (photos d'appareil, captures) : réduites dans le navigateur avant l'envoi,
// pour passer sous la limite du serveur (2 Mo par défaut chez de nombreux hébergeurs).
const IMAGE_SOFT_LIMIT = 1.5 * 1024 * 1024;
const IMAGE_MAX_SIDE = 2400;
async function shrinkImage(file) {
  if (!file || file.size <= IMAGE_SOFT_LIMIT || !/^image\/(jpeg|png|webp)$/.test(file.type)) return file;
  let bitmap;
  try { bitmap = await createImageBitmap(file); } catch { return file; }
  const canvas = document.createElement('canvas');
  const context = canvas.getContext('2d');
  let side = IMAGE_MAX_SIDE;
  let result = file;
  for (let attempt = 0; attempt < 5; attempt += 1) {
    const ratio = Math.min(1, side / Math.max(bitmap.width, bitmap.height));
    canvas.width = Math.round(bitmap.width * ratio);
    canvas.height = Math.round(bitmap.height * ratio);
    context.clearRect(0, 0, canvas.width, canvas.height);
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    // PNG avec transparence (logo) : reste en PNG ; sinon JPEG, bien plus léger pour une photo.
    let transparent = false;
    if (file.type === 'image/png') {
      const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
      for (let i = 3; i < pixels.length; i += 4 * 16) if (pixels[i] < 255) { transparent = true; break; }
    }
    const type = transparent ? 'image/png' : 'image/jpeg';
    const blob = await new Promise(resolve => canvas.toBlob(resolve, type, 0.85));
    if (!blob) return file;
    const name = file.name.replace(/\.[^.]+$/, '') + (transparent ? '.png' : '.jpg');
    result = new File([blob], name, { type, lastModified: Date.now() });
    if (result.size <= IMAGE_SOFT_LIMIT) break;
    side = Math.round(side * 0.75);
  }
  bitmap.close?.();
  return result.size < file.size ? result : file;
}

const pendingImages = new Set();
document.addEventListener('change', event => {
  const input = event.target;
  if (!(input instanceof HTMLInputElement) || input.type !== 'file' || input.multiple || !input.name || !/image\//.test(input.accept) || !input.files?.[0]) return;
  const original = input.files[0];
  if (original.size <= IMAGE_SOFT_LIMIT) return;
  let status = input.parentElement.querySelector('.image-shrink-status');
  if (!status) {
    status = Object.assign(document.createElement('small'), { className: 'field-help image-shrink-status' });
    status.setAttribute('aria-live', 'polite');
    input.after(status);
  }
  const megabytes = size => (size / 1048576).toLocaleString('fr-FR', { maximumFractionDigits: 1 }) + ' Mo';
  status.textContent = 'Préparation de l’image (' + megabytes(original.size) + ')…';
  const job = shrinkImage(original).then(smaller => {
    if (smaller !== original && input.files?.[0] === original) {
      const transfer = new DataTransfer();
      transfer.items.add(smaller);
      input.files = transfer.files;
      status.textContent = 'Image allégée pour l’envoi : ' + megabytes(original.size) + ' → ' + megabytes(smaller.size) + '.';
    } else {
      status.textContent = '';
    }
  }).finally(() => pendingImages.delete(job));
  pendingImages.add(job);
}, true);
document.addEventListener('submit', event => {
  if (!pendingImages.size) return;
  // Une réduction est encore en cours : on attend sa fin puis on renvoie le formulaire.
  event.preventDefault();
  const form = event.target;
  const submitter = event.submitter;
  Promise.allSettled([...pendingImages]).then(() => form.requestSubmit(submitter || undefined));
}, true);

const richEditor = document.querySelector('#rich-editor');
const articleEditorForm = document.querySelector('#article-editor-form');
if (richEditor && articleEditorForm) {
  const focusEditor = () => richEditor.focus();
  const toolbar = document.querySelector('.rich-toolbar');
  const familyPicker = document.createElement('select');
  familyPicker.id = 'font-family';
  familyPicker.setAttribute('aria-label', 'Police de caractères');
  familyPicker.innerHTML = '<option value="Arial">Arial</option><option value="Georgia">Georgia</option><option value="Times New Roman">Times</option><option value="Courier New">Courier</option>';
  toolbar.insertBefore(familyPicker, document.querySelector('#font-size'));
  familyPicker.addEventListener('change', event => { focusEditor(); document.execCommand('fontName', false, event.target.value); });
  const imageButton = document.createElement('button');
  imageButton.type = 'button';
  imageButton.title = 'Insérer une image par URL';
  imageButton.textContent = '▧';
  toolbar.insertBefore(imageButton, document.querySelector('#link-tool'));
  imageButton.addEventListener('mousedown', event => event.preventDefault());
  imageButton.addEventListener('click', () => {
    const src = prompt('Adresse HTTPS de l’image (ou chemin /uploads/…)');
    if (src && (/^https:\/\//i.test(src) || src.startsWith('/uploads/'))) {
      focusEditor();
      document.execCommand('insertImage', false, src);
    }
  });
  // Image téléversée depuis l'ordinateur, insérée à l'endroit du curseur.
  const uploadButton = document.createElement('button');
  uploadButton.type = 'button';
  uploadButton.title = 'Insérer une image depuis l’ordinateur';
  uploadButton.textContent = '🖼';
  const uploadInput = Object.assign(document.createElement('input'), { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', hidden: true });
  toolbar.insertBefore(uploadButton, imageButton);
  toolbar.append(uploadInput);
  let savedRange = null;
  uploadButton.addEventListener('mousedown', event => {
    event.preventDefault();
    const selection = window.getSelection();
    savedRange = selection.rangeCount && richEditor.contains(selection.anchorNode) ? selection.getRangeAt(0).cloneRange() : null;
  });
  uploadButton.addEventListener('click', () => uploadInput.click());
  uploadInput.addEventListener('change', async () => {
    const file = uploadInput.files[0];
    if (!file) return;
    const data = new FormData();
    data.set('_csrf', articleEditorForm.querySelector('input[name="_csrf"]').value);
    data.set('image', await shrinkImage(file));
    uploadButton.disabled = true;
    try {
      const response = await fetch('/admin/articles/image', { method: 'POST', body: data, credentials: 'same-origin' });
      const answer = await response.json();
      if (!response.ok || !answer.url) throw new Error(answer.error || 'Envoi impossible');
      focusEditor();
      if (savedRange) {
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(savedRange);
      }
      document.execCommand('insertImage', false, answer.url);
      adminToast('Image insérée dans le texte. Pensez à enregistrer l’article.');
    } catch (error) {
      adminToast(error.message || 'L’image n’a pas pu être envoyée.', 'error');
    }
    uploadButton.disabled = false;
    uploadInput.value = '';
  });
  document.querySelectorAll('.rich-toolbar button').forEach(button => {
    button.addEventListener('mousedown', event => event.preventDefault());
    button.addEventListener('click', () => {
      focusEditor();
      const command = button.dataset.command;
      if (command) document.execCommand(command, false, null);
      if (button.id === 'link-tool') {
        const url = prompt('Adresse du lien (https://… ou mailto:…)');
        if (url) document.execCommand('createLink', false, url);
      }
      button.classList.toggle('active', ['bold', 'italic', 'underline', 'strikeThrough'].some(name => name === command && document.queryCommandState(name)));
    });
  });
  document.querySelector('#font-size')?.addEventListener('change', event => {
    focusEditor();
    document.execCommand('fontSize', false, event.target.value);
    event.target.value = '3';
  });
  document.querySelector('#block-format')?.addEventListener('change', event => {
    focusEditor();
    document.execCommand('formatBlock', false, `<${event.target.value}>`);
    event.target.value = 'p';
  });
  document.querySelector('#font-color')?.addEventListener('input', event => {
    focusEditor();
    document.execCommand('foreColor', false, event.target.value);
  });
  articleEditorForm.addEventListener('submit', () => {
    document.querySelector('#body-html').value = richEditor.innerHTML;
  });
  document.querySelector('select[name="template_id"]')?.addEventListener('change', event => {
    const template = event.target.selectedOptions[0];
    const nextBody = template?.dataset.body || '';
    if (nextBody && (!richEditor.innerText.trim() || confirm('Remplacer le contenu actuel par la structure de ce modèle ?'))) richEditor.innerHTML = nextBody;
  });
  const imageInput = document.querySelector('input[name="featured_image"]');
  imageInput?.addEventListener('change', () => {
    const file = imageInput.files?.[0];
    if (!file) return;
    let preview = document.querySelector('.featured-preview');
    if (!preview) {
      preview = document.createElement('img');
      preview.className = 'featured-preview';
      preview.alt = 'Aperçu de l’image mise en avant';
      imageInput.before(preview);
      document.querySelector('.image-placeholder')?.remove();
    }
    preview.src = URL.createObjectURL(file);
    // L'aperçu n'est pas encore enregistré : sans ce rappel, actualiser la page ramène l'ancienne image.
    let pending = imageInput.closest('.side-panel').querySelector('.featured-pending');
    if (!pending) {
      pending = Object.assign(document.createElement('p'), { className: 'featured-pending' });
      pending.setAttribute('role', 'status');
      preview.after(pending);
    }
    pending.textContent = 'Nouvelle image choisie, pas encore enregistrée : cliquez sur « ' + (articleEditorForm.querySelector('.side-actions button[type="submit"]')?.textContent.trim() || 'Enregistrer') + ' » pour la mettre en ligne.';
  });

  // Modifications non enregistrées : on prévient avant de quitter ou d'actualiser la page.
  let unsaved = false;
  const markUnsaved = () => { unsaved = true; };
  articleEditorForm.addEventListener('input', markUnsaved);
  articleEditorForm.addEventListener('change', markUnsaved);
  richEditor.addEventListener('input', markUnsaved);
  articleEditorForm.addEventListener('submit', () => { unsaved = false; });
  window.addEventListener('beforeunload', event => {
    if (!unsaved) return;
    event.preventDefault();
    event.returnValue = '';
  });
}

// Documents et liens de l'article : « + Un autre lien » ajoute une ligne vide.
document.querySelector('[data-add-link]')?.addEventListener('click', () => {
  const rows = document.querySelector('#link-rows');
  const row = rows.querySelector('.link-row').cloneNode(true);
  row.querySelectorAll('input').forEach(input => { input.value = ''; });
  rows.append(row);
  row.querySelector('input').focus();
});

document.querySelectorAll('form[onsubmit]').forEach(form => {
  form.addEventListener('submit', event => {
    if (!confirm('Confirmer cette suppression ?')) event.preventDefault();
  });
});

const languageModule = document.createElement('script'); languageModule.src = '/i18n.js'; languageModule.defer = true; document.head.append(languageModule);

// Rangement groupé des articles : « Tout sélectionner » et nombre d'articles cochés.
const bulkForm = document.querySelector('#bulk-articles');
if (bulkForm) {
  const boxes = () => [...document.querySelectorAll('input[name="ids[]"][form="bulk-articles"]')];
  const counter = bulkForm.querySelector('[data-bulk-count]');
  const selectAll = document.querySelector('[data-select-all]');
  const refresh = () => {
    const checked = boxes().filter(box => box.checked).length;
    counter.textContent = `${checked} article${checked > 1 ? 's' : ''} sélectionné${checked > 1 ? 's' : ''}`;
    bulkForm.classList.toggle('has-selection', checked > 0);
    if (selectAll) selectAll.checked = checked > 0 && checked === boxes().length;
  };
  selectAll?.addEventListener('change', () => { boxes().forEach(box => { box.checked = selectAll.checked; }); refresh(); });
  boxes().forEach(box => box.addEventListener('change', refresh));
  bulkForm.addEventListener('submit', event => {
    if (!boxes().some(box => box.checked)) {
      event.preventDefault();
      counter.textContent = 'Cochez au moins un article.';
    }
  });
  refresh();
}

// Notifications : confirmation après chaque action (envoyée par le serveur) et erreurs des formulaires.
function adminToast(message, type = 'success') {
  const toast = document.createElement('div');
  toast.className = 'admin-toast is-' + type;
  toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
  toast.dataset.toast = '';
  toast.innerHTML = '<span class="admin-toast-icon" aria-hidden="true"></span><p></p><button type="button" class="admin-toast-close" aria-label="Fermer la notification" data-toast-close>×</button>';
  toast.querySelector('.admin-toast-icon').textContent = type === 'error' ? '!' : '✓';
  toast.querySelector('p').textContent = message;
  document.body.append(toast);
  setupToast(toast);
}
function toastStack() {
  let stack = document.querySelector('.admin-toasts');
  if (!stack) {
    stack = Object.assign(document.createElement('div'), { className: 'admin-toasts' });
    document.body.append(stack);
  }
  return stack;
}
function setupToast(toast) {
  toastStack().append(toast);
  const close = () => { toast.classList.add('is-leaving'); setTimeout(() => toast.remove(), 250); };
  toast.querySelector('[data-toast-close]')?.addEventListener('click', close);
  // Les confirmations disparaissent seules ; les erreurs restent jusqu'à ce qu'on les ferme.
  if (toast.classList.contains('is-success')) {
    let timer = setTimeout(close, 6000);
    toast.addEventListener('mouseenter', () => clearTimeout(timer));
    toast.addEventListener('mouseleave', () => { timer = setTimeout(close, 3000); });
  }
}
document.querySelectorAll('[data-toast]').forEach(setupToast);
document.querySelectorAll('.form-error').forEach(error => {
  if (error.textContent.trim()) adminToast(error.textContent.trim(), 'error');
});

// Hauteur réelle de la barre du haut (plus haute sur mobile) : la barre de mise en forme se colle juste dessous.
const adminTopbar = document.querySelector('.admin-topbar');
if (adminTopbar && document.querySelector('.rich-toolbar')) {
  // Sur mobile, la rangée de liens de l'espace adhérent reste aussi collée sous la barre du haut : on l'ajoute.
  const syncTopbar = () => {
    let bottom = adminTopbar.offsetHeight;
    const sidebar = document.querySelector('.admin-sidebar');
    const style = sidebar && getComputedStyle(sidebar);
    if (style && style.position === 'sticky' && sidebar.offsetWidth > window.innerWidth * 0.6) {
      bottom = Math.max(bottom, (parseFloat(style.top) || 0) + sidebar.offsetHeight);
    }
    document.documentElement.style.setProperty('--admin-topbar-height', bottom + 'px');
  };
  syncTopbar();
  window.addEventListener('resize', syncTopbar);
}
