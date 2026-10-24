"""Détection de langue et traduction automatique des publications.

Le fournisseur de traduction est choisi par variables d'environnement :
- DEEPL_API_KEY : API DeepL (clé gratuite se terminant par « :fx » ou clé payante) ;
- LIBRETRANSLATE_URL (et LIBRETRANSLATE_API_KEY si nécessaire) : instance LibreTranslate ;
- sinon, le service web public de Google Traduction (sans clé, non officiel).
TRANSLATION_PROVIDER=none désactive la traduction automatique.

Les traductions sont mises en cache par segment de texte dans la table `translations` :
un article modifié n'est retraduit que pour les passages qui ont changé.
"""

from __future__ import annotations

import hashlib
import html
import json
import os
import re
import time
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from html.parser import HTMLParser


TIMEOUT = float(os.environ.get('TRANSLATION_TIMEOUT', '10'))
RETRY_DELAY = 60
_unavailable_until = 0.0

STOPWORDS = {
    'fr': set('le la les un une des du de et est en que qui dans pour pas sur au aux ce cette ces son sa ses leur leurs nous vous ils elles avec par plus mais ou où sont été être avoir fait comme tout tous très aussi entre sans sous dont lui elle on ne se l d qu j c n s'.split()),
    'en': set('the of and to in is that for it with as was on be by this are from at or an have not but which their they has were been will its can more would there all also who about than into these other our we'.split()),
    'es': set('el la los las un una unos unas de del y en que es por para con no se su sus lo al como más pero sobre entre también ha son está fue ser muy sin este esta estos donde cuando porque hay'.split()),
    'it': set('il lo la i gli le un una uno di del della dei delle e è che per con non si su nel nella al alla come più ma anche sono tra dal dalla questo questa essere ha hanno degli negli sulla'.split()),
    'de': set('der die das und ist nicht ein eine einer zu den mit von im für auf dem des sich auch es sind wird werden als bei nach aus wie oder aber noch über'.split()),
    'pt': set('o a os as um uma de do da dos das e em que é para com não se por no na nos nas ao como mais mas foi são está também entre sobre muito sem pela pelo'.split()),
}
ACCENT_HINTS = {'fr': 'çèêëœùûîï', 'es': 'ñ¿¡', 'pt': 'ãõ', 'de': 'ßäöü', 'it': 'ìò'}

LANGUAGE_NAMES = {
    'fr': {'fr': 'français', 'en': 'anglais', 'es': 'espagnol', 'it': 'italien', 'de': 'allemand', 'pt': 'portugais'},
    'en': {'fr': 'French', 'en': 'English', 'es': 'Spanish', 'it': 'Italian', 'de': 'German', 'pt': 'Portuguese'},
    'es': {'fr': 'francés', 'en': 'inglés', 'es': 'español', 'it': 'italiano', 'de': 'alemán', 'pt': 'portugués'},
    'it': {'fr': 'francese', 'en': 'inglese', 'es': 'spagnolo', 'it': 'italiano', 'de': 'tedesco', 'pt': 'portoghese'},
    'de': {'fr': 'Französisch', 'en': 'Englisch', 'es': 'Spanisch', 'it': 'Italienisch', 'de': 'Deutsch', 'pt': 'Portugiesisch'},
    'pt': {'fr': 'francês', 'en': 'inglês', 'es': 'espanhol', 'it': 'italiano', 'de': 'alemão', 'pt': 'português'},
}
NOTICES = {
    'fr': ('Traduction automatique — langue d’origine : {source}.', 'Lire la version originale', 'Voir la traduction', 'La traduction automatique est momentanément indisponible : le texte est affiché dans sa langue d’origine.'),
    'en': ('Automatic translation — original language: {source}.', 'Read the original', 'View the translation', 'Automatic translation is temporarily unavailable; the text is shown in its original language.'),
    'es': ('Traducción automática — idioma original: {source}.', 'Leer la versión original', 'Ver la traducción', 'La traducción automática no está disponible en este momento; el texto se muestra en su idioma original.'),
    'it': ('Traduzione automatica — lingua originale: {source}.', 'Leggi la versione originale', 'Vedi la traduzione', 'La traduzione automatica non è al momento disponibile; il testo è mostrato nella lingua originale.'),
    'de': ('Automatische Übersetzung — Originalsprache: {source}.', 'Original lesen', 'Übersetzung anzeigen', 'Die automatische Übersetzung ist derzeit nicht verfügbar; der Text wird in der Originalsprache angezeigt.'),
    'pt': ('Tradução automática — idioma original: {source}.', 'Ler a versão original', 'Ver a tradução', 'A tradução automática está temporariamente indisponível; o texto é apresentado no idioma original.'),
}

HAS_LETTER = re.compile(r'[^\W\d_]')


class TranslationError(RuntimeError):
    pass


def html_to_text(source: str) -> str:
    return html.unescape(re.sub(r'<[^>]*>', ' ', source or ''))


def detect_language(text: str, allowed: set[str] | None = None) -> str | None:
    """Détecte la langue d'un texte (fr, en, es, it, de, pt) par fréquence des mots outils."""
    lowered = (text or '').lower()
    words = re.findall(r'[^\W\d_]+', lowered)[:3000]
    if not words:
        return None
    candidates = [code for code in STOPWORDS if not allowed or code in allowed]
    # Un mot partagé par plusieurs langues (« de », « la », « en ») compte moins qu'un mot propre à une seule (« el », « the »).
    scores = {code: 0.0 for code in candidates}
    for word in words:
        owners = [code for code in candidates if word in STOPWORDS[code]]
        for code in owners:
            scores[code] += 1 / len(owners)
    for code, hints in ACCENT_HINTS.items():
        if code in scores and hints:
            scores[code] += 0.5 * min(sum(lowered.count(char) for char in hints), 40)
    best = max(scores, key=scores.get, default=None)
    if not best or scores[best] < 2:
        return None
    return best


def notice_texts(target: str, source: str) -> tuple[str, str, str, str]:
    translated, original_link, translation_link, unavailable = NOTICES.get(target, NOTICES['en'])
    name = LANGUAGE_NAMES.get(target, LANGUAGE_NAMES['en']).get(source, source.upper())
    return translated.format(source=name), original_link, translation_link, unavailable


def _request_json(url: str, data: bytes, headers: dict[str, str]):
    request = urllib.request.Request(url, data=data, headers={'User-Agent': 'Mozilla/5.0 (I-Dialogos)', **headers})
    try:
        with urllib.request.urlopen(request, timeout=TIMEOUT) as response:
            return json.loads(response.read().decode('utf-8'))
    except (OSError, ValueError) as exc:  # URLError et TimeoutError dérivent d'OSError
        raise TranslationError(str(exc)) from exc


class GoogleWebProvider:
    name = 'Google Traduction (service web public, sans clé)'
    max_chars = 4000
    max_items = 100

    def translate(self, texts: list[str], target: str) -> list[str]:
        url = 'https://translate.googleapis.com/translate_a/single?' + urllib.parse.urlencode({'client': 'gtx', 'sl': 'auto', 'tl': target, 'dt': 't'})
        data = urllib.parse.urlencode({'q': '\n'.join(texts)}).encode()
        response = _request_json(url, data, {'Content-Type': 'application/x-www-form-urlencoded;charset=utf-8'})
        try:
            joined = ''.join(part[0] for part in response[0] if part and part[0])
        except (TypeError, IndexError) as exc:
            raise TranslationError('Réponse de traduction inattendue.') from exc
        lines = joined.split('\n')
        if len(lines) == len(texts):
            return [line.strip() for line in lines]
        if len(texts) == 1:
            return [' '.join(joined.split())]
        # Le service a fusionné ou découpé des lignes : on retraduit chaque segment séparément.
        return [self.translate([text], target)[0] for text in texts]


class DeepLProvider:
    name = 'DeepL'
    max_chars = 30000
    max_items = 50

    def __init__(self, key: str) -> None:
        self.key = key
        self.url = 'https://api-free.deepl.com/v2/translate' if key.endswith(':fx') else 'https://api.deepl.com/v2/translate'

    def translate(self, texts: list[str], target: str) -> list[str]:
        code = {'en': 'EN-GB', 'pt': 'PT-PT'}.get(target, target.upper())
        payload = json.dumps({'text': texts, 'target_lang': code}).encode()
        response = _request_json(self.url, payload, {'Authorization': f'DeepL-Auth-Key {self.key}', 'Content-Type': 'application/json'})
        try:
            return [item['text'] for item in response['translations']]
        except (KeyError, TypeError) as exc:
            raise TranslationError('Réponse DeepL inattendue.') from exc


class LibreTranslateProvider:
    name = 'LibreTranslate'
    max_chars = 8000
    max_items = 50

    def __init__(self, url: str, key: str = '') -> None:
        self.url = url.rstrip('/') + '/translate'
        self.key = key

    def translate(self, texts: list[str], target: str) -> list[str]:
        body = {'q': texts, 'source': 'auto', 'target': target, 'format': 'text'}
        if self.key:
            body['api_key'] = self.key
        response = _request_json(self.url, json.dumps(body).encode(), {'Content-Type': 'application/json'})
        try:
            result = response['translatedText']
        except (KeyError, TypeError) as exc:
            raise TranslationError('Réponse LibreTranslate inattendue.') from exc
        return result if isinstance(result, list) else [result]


def _make_provider():
    if os.environ.get('TRANSLATION_PROVIDER', '').strip().lower() == 'none':
        return None
    if os.environ.get('DEEPL_API_KEY', '').strip():
        return DeepLProvider(os.environ['DEEPL_API_KEY'].strip())
    if os.environ.get('LIBRETRANSLATE_URL', '').strip():
        return LibreTranslateProvider(os.environ['LIBRETRANSLATE_URL'].strip(), os.environ.get('LIBRETRANSLATE_API_KEY', '').strip())
    return GoogleWebProvider()


PROVIDER = _make_provider()


def provider_name() -> str:
    return PROVIDER.name if PROVIDER else 'désactivée (TRANSLATION_PROVIDER=none)'


def _split_long(text: str, limit: int) -> list[str]:
    if len(text) <= limit:
        return [text]
    pieces, current = [], ''
    for sentence in re.split(r'(?<=[.!?…;])\s+', text):
        while len(sentence) > limit:
            pieces.append(sentence[:limit])
            sentence = sentence[limit:]
        if current and len(current) + len(sentence) + 1 > limit:
            pieces.append(current)
            current = sentence
        else:
            current = f'{current} {sentence}'.strip()
    if current:
        pieces.append(current)
    return pieces


def translate_texts(db, texts: list[str], target: str) -> list[str | None]:
    """Traduit une liste de textes vers `target`. Renvoie None pour chaque texte non traduit."""
    global _unavailable_until
    results: list[str | None] = [None] * len(texts)
    if not texts:
        return results
    keys = [hashlib.sha256(text.encode('utf-8')).hexdigest() for text in texts]
    rows = db.execute('SELECT hash,translated FROM translations WHERE target=? AND hash = ANY(?)', (target, list(set(keys)))).fetchall()
    cached = {row['hash']: row['translated'] for row in rows}
    missing: dict[str, str] = {}
    for key, text in zip(keys, texts):
        if key not in cached:
            missing.setdefault(key, text)
    if missing and PROVIDER and time.time() >= _unavailable_until:
        pieces = [(key, piece) for key, text in missing.items() for piece in _split_long(text, PROVIDER.max_chars)]
        translated: dict[str, list[str]] = {}
        failed: set[str] = set()
        batch: list[tuple[str, str]] = []

        def flush() -> None:
            outputs = PROVIDER.translate([piece for _, piece in batch], target)
            if len(outputs) != len(batch):
                raise TranslationError('Nombre de segments traduits incohérent.')
            for (key, _), output in zip(batch, outputs):
                translated.setdefault(key, []).append(output)
            batch.clear()

        try:
            for key, piece in pieces:
                if batch and (sum(len(p) + 1 for _, p in batch) + len(piece) > PROVIDER.max_chars or len(batch) >= PROVIDER.max_items):
                    flush()
                batch.append((key, piece))
            if batch:
                flush()
        except TranslationError as exc:
            _unavailable_until = time.time() + RETRY_DELAY
            failed = {key for key, _ in batch}
            print(f'Traduction indisponible ({exc}) : nouvel essai dans {RETRY_DELAY} s.')
        now = datetime.now(timezone.utc).isoformat(timespec='seconds')
        expected = {}
        for key, _ in pieces:
            expected[key] = expected.get(key, 0) + 1
        for key, outputs in translated.items():
            if key in failed or len(outputs) != expected[key]:
                continue
            value = ' '.join(output.strip() for output in outputs if output)
            if value:
                cached[key] = value
                db.execute('INSERT OR IGNORE INTO translations(hash,target,translated,created_at) VALUES(?,?,?,?)', (key, target, value, now))
    for index, key in enumerate(keys):
        results[index] = cached.get(key)
    return results


INLINE_TAGS = {'a', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'span', 'font', 'code', 'small', 'mark'}
PLACEHOLDER = re.compile(r'(</?g\d+>)')


def _collapse(value: str) -> str:
    return ' '.join(value.split())


class _Segment:
    """Un passage de texte continu (paragraphe, titre, élément de liste…) traduit d'un seul tenant.

    Les balises en ligne (gras, lien…) sont remplacées par des marqueurs <g1>…</g1> que le service
    de traduction conserve, puis restaurées dans la traduction.
    """

    def __init__(self, tokens: list[tuple]) -> None:
        self.tokens = tokens
        self.tags: dict[int, list[str]] = {}
        for token in tokens:
            if token[0] == 'open':
                self.tags[token[1]] = [token[2], '']
            elif token[0] == 'close':
                self.tags[token[1]][1] = token[2]
        raw = ''.join(token[1] if token[0] == 'text' else (f'<g{token[1]}>' if token[0] == 'open' else f'</g{token[1]}>') for token in tokens)
        self.source = _collapse(raw)
        self.lead = ' ' if raw[:1].isspace() else ''
        self.trail = ' ' if raw[-1:].isspace() else ''
        self.nodes = [_collapse(token[1]) for token in tokens if token[0] == 'text' and HAS_LETTER.search(token[1])]

    def original(self) -> str:
        return ''.join(html.escape(token[1], quote=False) if token[0] == 'text' else token[2] for token in self.tokens)

    def render(self, translated: str) -> str | None:
        """Restaure les balises dans la traduction ; None si les marqueurs ont été altérés."""
        out: list[str] = []
        stack: list[int] = []
        seen: set[int] = set()
        capitalize = self.nodes[0][:1].isupper()
        for piece in PLACEHOLDER.split(translated):
            match = re.fullmatch(r'<(/?)g(\d+)>', piece)
            if not match:
                if capitalize and HAS_LETTER.search(piece):
                    # Le service perd parfois la majuscule initiale quand la phrase commence par un marqueur.
                    position = HAS_LETTER.search(piece).start()
                    piece = piece[:position] + piece[position].upper() + piece[position + 1:]
                    capitalize = False
                out.append(html.escape(piece, quote=False))
                continue
            number = int(match.group(2))
            if number not in self.tags:
                return None
            if not match.group(1):
                if number in seen:
                    return None
                seen.add(number)
                stack.append(number)
                out.append(self.tags[number][0])
            else:
                if not stack or stack[-1] != number:
                    return None
                stack.pop()
                out.append(self.tags[number][1])
        if stack or seen != set(self.tags):
            return None
        return self.lead + ''.join(out).strip() + self.trail

    def render_nodes(self, translations: list[str]) -> str:
        """Solution de repli : chaque morceau de texte traduit séparément, balises inchangées."""
        remaining = iter(translations)
        out = []
        for token in self.tokens:
            if token[0] == 'text' and HAS_LETTER.search(token[1]):
                lead = ' ' if token[1][:1].isspace() else ''
                trail = ' ' if token[1][-1:].isspace() else ''
                out.append(lead + html.escape(next(remaining), quote=False) + trail)
            else:
                out.append(html.escape(token[1], quote=False) if token[0] == 'text' else token[2])
        return ''.join(out)


class _Segmenter(HTMLParser):
    """Découpe un HTML (déjà nettoyé par safe_rich_html) en balises conservées et passages à traduire."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.parts: list[str | _Segment] = []
        self.segments: list[_Segment] = []
        self.tokens: list[tuple] = []
        self.open_stack: list[tuple[int, str]] = []
        self.counter = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        raw = self.get_starttag_text() or f'<{tag}>'
        if tag in INLINE_TAGS:
            self.counter += 1
            self.tokens.append(('open', self.counter, raw))
            self.open_stack.append((self.counter, tag))
        else:
            self.flush()
            self.parts.append(raw)

    def handle_startendtag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.flush()
        self.parts.append(self.get_starttag_text() or f'<{tag}>')

    def handle_endtag(self, tag: str) -> None:
        if self.open_stack and self.open_stack[-1][1] == tag:
            number, _ = self.open_stack.pop()
            self.tokens.append(('close', number, f'</{tag}>'))
        else:
            self.flush()
            self.parts.append(f'</{tag}>')

    def handle_data(self, data: str) -> None:
        self.tokens.append(('text', data))

    def close(self) -> None:
        super().close()
        self.flush()

    def flush(self) -> None:
        # Une balise ouverte dans ce passage mais fermée plus loin reste hors traduction.
        unclosed = {number for number, _ in self.open_stack}
        tokens, self.tokens, self.open_stack = self.tokens, [], []
        chunk: list[tuple] = []
        for token in tokens:
            if token[0] == 'open' and token[1] in unclosed:
                self._emit(chunk)
                self.parts.append(token[2])
                chunk = []
            else:
                chunk.append(token)
        self._emit(chunk)

    def _emit(self, tokens: list[tuple]) -> None:
        if not tokens:
            return
        segment = _Segment(tokens)
        if segment.nodes:
            self.parts.append(segment)
            self.segments.append(segment)
        else:
            self.parts.append(segment.original())

    def rebuild(self, rendered: dict[int, str]) -> str:
        return ''.join(rendered[id(part)] if isinstance(part, _Segment) else part for part in self.parts)


def translate_records(db, records: list[dict], target: str, fields: tuple[str, ...] = (), html_fields: tuple[str, ...] = (), language_key: str = 'language') -> list[dict]:
    """Renvoie des copies des enregistrements avec les champs traduits vers `target`.

    Les enregistrements déjà rédigés dans la langue cible restent intacts. Chaque copie reçoit :
    - `translated_from` : langue d'origine lorsque le contenu a été traduit, sinon '' ;
    - `translation_missing` : True si une partie n'a pas pu être traduite (texte d'origine conservé) ;
    - `display_language` : la langue cible demandée.
    Tous les textes sont envoyés en un seul lot pour limiter les appels au service.
    """
    copies: list[dict] = []
    plain_jobs: list[tuple[dict, str, int]] = []
    html_jobs: list[tuple[dict, str, _Segmenter, int]] = []
    texts: list[str] = []
    for record in records:
        copy = dict(record)
        copy['translated_from'] = ''
        copy['translation_missing'] = False
        copy['display_language'] = target
        copies.append(copy)
        source = (record.get(language_key) or '').lower() if language_key else ''
        if source == target:
            continue
        for field in fields:
            value = _collapse(str(record.get(field) or ''))
            if HAS_LETTER.search(value):
                plain_jobs.append((copy, field, len(texts)))
                texts.append(value)
        for field in html_fields:
            segmenter = _Segmenter()
            segmenter.feed(record.get(field) or '')
            segmenter.close()
            if segmenter.segments:
                html_jobs.append((copy, field, segmenter, len(texts)))
                texts.extend(segment.source for segment in segmenter.segments)
    results = translate_texts(db, texts, target)

    # Passages dont les marqueurs de mise en forme n'ont pas survécu : retraduction morceau par morceau.
    rendered: dict[int, str | None] = {}
    fallback: list[tuple[_Segment, int]] = []
    fallback_texts: list[str] = []
    for _, _, segmenter, start in html_jobs:
        for offset, segment in enumerate(segmenter.segments):
            value = results[start + offset]
            rendered[id(segment)] = segment.render(value) if value is not None else None
            if value is not None and rendered[id(segment)] is None:
                fallback.append((segment, len(fallback_texts)))
                fallback_texts.extend(segment.nodes)
    if fallback_texts:
        fallback_results = translate_texts(db, fallback_texts, target)
        for segment, start in fallback:
            values = fallback_results[start:start + len(segment.nodes)]
            if all(value is not None for value in values):
                rendered[id(segment)] = segment.render_nodes(values)

    def mark(copy: dict) -> None:
        source = (copy.get(language_key) or '').lower() if language_key else ''
        copy['translated_from'] = source or 'auto'

    for copy, field, index in plain_jobs:
        if results[index] is None:
            copy['translation_missing'] = True
        else:
            copy[field] = results[index]
            mark(copy)
    for copy, field, segmenter, _ in html_jobs:
        if any(rendered.get(id(segment)) is None for segment in segmenter.segments):
            copy['translation_missing'] = True
        else:
            copy[field] = segmenter.rebuild(rendered)
            mark(copy)
    return copies
