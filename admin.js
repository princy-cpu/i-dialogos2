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
    data.set('image', file);
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
    } catch (error) {
      alert(error.message || 'L’image n’a pas pu être envoyée.');
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
