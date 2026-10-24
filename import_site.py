"""Importe les articles de l'ancien site https://www.i-dialogos.com (Site123) dans la base PostgreSQL.

Pour chaque article listé dans les sitemaps du site : titre, auteur, date, chapô, contenu complet
(mise en forme, liens), image principale et images du texte (téléchargées dans uploads/),
langue détectée et thématique déduite des mots-clés. L'événement du site est importé dans
« Actualités et événements ».

Usage (avec DATABASE_URL configurée) :
    python import_site.py                 # import complet
    python import_site.py --limite 5      # essai sur 5 articles
    python import_site.py --simulation    # analyse sans rien écrire
    python import_site.py --sans-images   # garde les images sur l'ancien site

Le script peut être relancé : un contenu déjà importé (même adresse d'origine) est mis à jour.
"""

from __future__ import annotations

import argparse
import hashlib
import html
import io
import json
import re
import sys
import time
import urllib.error
import urllib.request
import uuid
from contextlib import nullcontext
from datetime import datetime, timezone
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import quote, unquote, urlparse

from server import UPLOAD_DIR, connect, initialize, now_iso, safe_rich_html, slugify, unique_slug
from translation import detect_language, html_to_text

try:
    from PIL import Image
except ImportError:  # Pillow facultatif : sans lui, les images sont gardées dans leur format d'origine.
    Image = None

SITE = 'https://www.i-dialogos.com'
USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) I-Dialogos-migration/1.0'
DELAY = 0.4  # pause entre deux requêtes, pour ménager l'ancien site
ARTICLE_SITEMAPS = ['sitemap-2-articles-p1.xml', 'sitemap-3-articles-2-p1.xml', 'sitemap-8-articles-3-p1.xml']
EVENT_SITEMAPS = ['sitemap-6-events-p1.xml']
IMAGE_MANIFEST = UPLOAD_DIR / '.import-images.json'
SUMMARY_MARKER = 'Lire l’article original sur I-Dialogos'  # résumés créés par import_articles.py
MONTHS = 'janvier février mars avril mai juin juillet août septembre octobre novembre décembre'.split()

# Thématique déduite des mots-clés du titre (poids 3) et du chapô (poids 1).
CATEGORY_KEYWORDS = {
    'medias-et-pluralisme': ['média', 'presse', 'journalis', 'désinformation', 'pluralisme', 'information'],
    'francophonies': ['francophon', 'langue française', 'plurilingu', 'senghor'],
    'democraties': ['démocrat', 'élection', 'électoral', 'gouvernance', 'citoyen', 'urnes'],
    'ia-intelligence-artificielle': ['intelligence artificielle', ' ia ', 'numérique', 'algorithm', 'découvrab'],
    'jeunesse': ['jeunesse', 'jeunes', 'étudiant'],
    'ultramarins': ['outre-mer', 'ultramarin', 'guadeloupe', 'martinique', 'guyane', 'mayotte', 'calédonie', 'polynésie'],
    'eau-climat-et-environnement': [' eau ', "l'eau", 'l’eau', 'climat', 'environnement', 'écolog', 'océan'],
    'geopolitique': ['géopoliti', 'guerre', 'paix', 'onu', 'nations unies', 'diplomat', 'conflit', 'otan', 'multilatéral',
                     'russie', 'ukraine', 'gaza', 'israël', 'sahel', 'chine', 'europe', 'afrique', 'souverain'],
    'sante-et-developpement': ['santé', 'médec', 'hôpita', 'épidém', 'soins', 'sanitaire'],
}


def log(message: str) -> None:
    print(message, flush=True)


class Fetcher:
    """Téléchargements avec pause, nouvelles tentatives et cache disque facultatif."""

    def __init__(self, cache: Path | None) -> None:
        self.cache = cache
        if cache:
            cache.mkdir(parents=True, exist_ok=True)
        self.last = 0.0

    def get(self, url: str, binary: bool = False) -> bytes | str:
        cached = self.cache / (hashlib.sha1(url.encode('utf-8')).hexdigest() + ('.bin' if binary else '.html')) if self.cache else None
        if cached and cached.exists():
            data = cached.read_bytes()
            return data if binary else data.decode('utf-8', errors='replace')
        for attempt in range(3):
            wait = DELAY - (time.time() - self.last)
            if wait > 0:
                time.sleep(wait)
            self.last = time.time()
            try:
                request = urllib.request.Request(url, headers={'User-Agent': USER_AGENT})
                with urllib.request.urlopen(request, timeout=40) as response:
                    data = response.read()
                break
            except OSError as exc:  # URLError et TimeoutError dérivent d’OSError
                if attempt == 2:
                    raise
                log(f'  … nouvel essai ({exc})')
                time.sleep(2 * (attempt + 1))
        if cached:
            cached.write_bytes(data)
        return data if binary else data.decode('utf-8', errors='replace')


class ElementExtractor(HTMLParser):
    """Récupère le HTML brut (intérieur) du premier élément dont la classe contient `class_name`."""

    def __init__(self, source: str, class_name: str) -> None:
        super().__init__(convert_charrefs=False)
        self.source = source
        self.class_name = class_name
        self.line_offsets = [0]
        for line in source.splitlines(keepends=True):
            self.line_offsets.append(self.line_offsets[-1] + len(line))
        self.tag = ''
        self.depth = 0
        self.start = self.end = -1

    def absolute_position(self) -> int:  # « offset » est déjà un attribut interne de HTMLParser
        line, column = self.getpos()
        return self.line_offsets[line - 1] + column

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if self.end >= 0:
            return
        if self.start < 0:
            classes = (dict(attrs).get('class') or '').split()
            if self.class_name in classes:
                self.tag, self.depth = tag, 1
                self.start = self.absolute_position() + len(self.get_starttag_text() or '')
        elif tag == self.tag:
            self.depth += 1

    def handle_endtag(self, tag: str) -> None:
        if self.start >= 0 and self.end < 0 and tag == self.tag:
            self.depth -= 1
            if self.depth == 0:
                self.end = self.absolute_position()

    def inner_html(self) -> str:
        self.feed(self.source)
        self.close()
        return self.source[self.start:self.end] if self.start >= 0 and self.end >= 0 else ''


def inner_html(source: str, class_name: str) -> str:
    return ElementExtractor(source, class_name).inner_html()


def json_ld(page: str, kind: str) -> dict:
    for block in re.findall(r'<script type="application/ld\+json">(.*?)</script>', page, re.S):
        try:
            data = json.loads(block)
        except ValueError:
            continue
        if data.get('@type') == kind:
            return data
    return {}


def clean_text(value: str) -> str:
    return ' '.join(html.unescape(html.unescape(value or '')).split())


def shorten(text: str, limit: int = 420) -> str:
    if len(text) <= limit:
        return text
    cut = text[:limit].rsplit(' ', 1)[0].rstrip(' ,;:–-')
    return cut + '…'


def upload_date(url: str) -> datetime | None:
    """Les fichiers Site123 portent un identifiant uniqid() : ses 8 premiers chiffres hexadécimaux sont la date d'envoi."""
    match = re.search(r'/(?:[a-z0-9]+_)?([0-9a-f]{13})(?:_|\.)', url or '')
    if not match:
        return None
    moment = datetime.fromtimestamp(int(match.group(1)[:8], 16), tz=timezone.utc)
    return moment if datetime(2015, 1, 1, tzinfo=timezone.utc) <= moment <= datetime.now(timezone.utc) else None


def text_date(text: str) -> datetime | None:
    found = re.findall(r'\b(1er|\d{1,2}) (' + '|'.join(MONTHS) + r') (20\d\d)\b', text)
    for day, month, year in reversed(found):
        try:
            return datetime(int(year), MONTHS.index(month) + 1, 1 if day == '1er' else int(day), tzinfo=timezone.utc)
        except ValueError:
            continue
    return None


# Prénom(s) (« Alain », « Serge M. ») puis NOM en capitales (« JUILLET », « MALLET DI BENTO »).
# Les motifs ne se chevauchent pas (pas de retour arrière exponentiel).
FIRST_NAME = r"(?:[A-ZÀ-Ý][a-zà-ÿ'’-]+|[A-ZÀ-Ý]\.)"
NAME = rf"{FIRST_NAME}(?:\s+{FIRST_NAME})*\s+[A-ZÀ-Ý][A-ZÀ-Ý'’-]{{2,}}(?:\s+[A-ZÀ-Ý][A-ZÀ-Ý'’-]+)*"
ACRONYMS = {'RDC', 'ONU', 'OIF', 'OTAN', 'UNESCO', 'USA', 'BRICS', 'COING', 'FERAM', 'CEDEAO', 'UQAM', 'UE', 'IA', 'PDF', 'AG', 'COP', 'OMS', 'FMI'}


def guess_author(title: str) -> str:
    """Auteur tiré du titre : « … Alain JUILLET (Paris) », « … Yakov RABKIN et Samir SAUL », « … Tran Thu Dung (Hanoï) »."""
    matches = [m for m in re.finditer(rf'({NAME})(\s*\([^)]{{2,60}}\))?', title)
               if not set(re.findall(r"\b[A-ZÀ-Ý][A-ZÀ-Ý'’-]+\b", m.group(1))) <= ACRONYMS]
    if matches:
        last = matches[-1]
        author = (last.group(1) + (last.group(2) or '')).strip()
        if len(matches) > 1 and title[matches[-2].end():last.start()].strip() in {'et', '&', ',', 'and'}:
            previous = matches[-2]
            author = f"{(previous.group(1) + (previous.group(2) or '')).strip()} et {author}"
        return re.sub(r"^(?:(?:Le|La|Les|Un|Une|Par|Avec|Selon)\s+)+", '', author)
    last = re.split(r'(?<=[.!?])\s+', title.strip().rstrip('.'))[-1]
    words = re.sub(r'\([^)]*\)', '', last).split()
    if 2 <= len(words) <= 5 and all(word[0].isupper() for word in words) and last != title.strip().rstrip('.'):
        return last.strip()
    return 'I-Dialogos'


def guess_category(title: str, excerpt: str) -> str | None:
    haystack_title = f' {title.lower()} '
    haystack_excerpt = f' {excerpt.lower()} '
    scores = {slug: 3 * sum(haystack_title.count(word) for word in words) + sum(haystack_excerpt.count(word) for word in words)
              for slug, words in CATEGORY_KEYWORDS.items()}
    best = max(scores, key=scores.get)
    return best if scores[best] >= 2 else None


class ImageStore:
    """Télécharge les images dans uploads/ (WebP, 1600 px max) et garde la correspondance adresse → fichier."""

    def __init__(self, fetcher: Fetcher, enabled: bool, dry_run: bool) -> None:
        self.fetcher = fetcher
        self.enabled = enabled and not dry_run
        self.manifest = json.loads(IMAGE_MANIFEST.read_text(encoding='utf-8')) if IMAGE_MANIFEST.exists() else {}
        self.saved = 0

    def local(self, url: str) -> str:
        url = html.unescape(url).strip()
        if url.startswith('//'):
            url = 'https:' + url
        if not self.enabled or not url.startswith(('https://', 'http://')):
            return url if url.startswith('https://') else ''
        known = self.manifest.get(url)
        if known and (UPLOAD_DIR / Path(known).name).exists():
            return known
        try:
            data = self.fetcher.get(url, binary=True)
        except Exception as exc:  # une image manquante ne doit pas bloquer l'article
            log(f'  ! image ignorée ({exc}) : {url}')
            return url if url.startswith('https://') else ''
        name = str(uuid.uuid4())
        if Image is not None:
            try:
                picture = Image.open(io.BytesIO(data))
                picture = picture.convert('RGBA' if picture.mode in ('RGBA', 'LA', 'P') else 'RGB')
                picture.thumbnail((1600, 1600))
                filename = f'{name}.webp'
                picture.save(UPLOAD_DIR / filename, 'WEBP', quality=82)
            except Exception:
                filename = ''
        else:
            filename = ''
        if not filename:
            extension = Path(urlparse(url).path).suffix.lower()
            if extension not in {'.jpg', '.jpeg', '.png', '.gif', '.webp'}:
                return url if url.startswith('https://') else ''
            filename = f'{name}{extension}'
            (UPLOAD_DIR / filename).write_bytes(data)
        self.manifest[url] = f'/uploads/{filename}'
        self.saved += 1
        return self.manifest[url]

    def save_manifest(self) -> None:
        if self.enabled:
            IMAGE_MANIFEST.write_text(json.dumps(self.manifest, ensure_ascii=False, indent=1), encoding='utf-8')


def youtube_link(source: str) -> str:
    match = re.search(r'youtube(?:-nocookie)?\.com/embed/([\w-]{6,})', source)
    return f'https://www.youtube.com/watch?v={match.group(1)}' if match else source


def prepare_body(raw: str, images: ImageStore) -> str:
    # Vidéos intégrées (iframe, retirées par le nettoyage) : remplacées par un lien.
    def video(match: re.Match) -> str:
        source = re.search(r'\bsrc="([^"]+)"', match.group(1))
        return f'<p><a href="{html.escape(youtube_link(html.unescape(source.group(1))))}">▶ Voir la vidéo</a></p>' if source else ''

    def image(match: re.Match) -> str:
        return '<img' + re.sub(r'\bsrc="([^"]+)"', lambda s: f'src="{html.escape(images.local(s.group(1)))}"', match.group(1)) + '>'

    raw = re.sub(r'<iframe\b([^>]*)>.*?</iframe>', video, raw, flags=re.S | re.I)
    raw = re.sub(r'<img\b([^>]*)>', image, raw, flags=re.I)
    body = safe_rich_html(raw)
    body = re.sub(r'<img src=""[^>]*>', '', body)
    # \s couvre déjà l'espace insécable : surtout ne pas l'ajouter à l'alternative (retour arrière exponentiel).
    body = re.sub(r'<(p|div|span|strong|em|b|i|u)>(?:\s|&nbsp;|<br>)*</\1>', '', body)
    body = re.sub(r'(?:<br>\s*){3,}', '<br><br>', body)
    return body.strip()


def parse_article(url: str, page: str, images: ImageStore) -> dict | None:
    data = json_ld(page, 'Article')
    heading = re.search(r'<h1[^>]*>(.*?)</h1>', page, re.S | re.I)
    title = clean_text(data.get('headline') or (re.sub(r'<[^>]+>', '', heading.group(1)) if heading else ''))
    raw_body = inner_html(page, 's123-editor')
    if not title or len(html_to_text(raw_body).strip()) < 120:  # page vide ou d'attente (« Prière de patienter »)
        return None
    lead = re.search(r'class="leadImage[^"]*">\s*<img[^>]*\ssrc="([^"]+)"', page)
    lead_url = html.unescape(lead.group(1)) if lead else ((data.get('image') or [''])[0] if isinstance(data.get('image'), list) else data.get('image') or '')
    body = prepare_body(raw_body, images)
    excerpt = shorten(clean_text(data.get('description') or html_to_text(body)))
    first_image = re.search(r'<img[^>]*src="([^"]+)"', raw_body)
    date = upload_date(lead_url) or upload_date(first_image.group(1) if first_image else '') or text_date(excerpt + ' ' + html_to_text(body))
    return {
        'source_url': url,
        'title': title[:300],
        'excerpt': excerpt,
        'body_html': body,
        'image_url': lead_url,
        'author': guess_author(title),
        'published_at': (date or datetime.now(timezone.utc)).isoformat(timespec='seconds'),
        'language': detect_language(f'{title}\n{excerpt}\n{html_to_text(body)}') or 'fr',
        'category': guess_category(title, excerpt),
    }


def parse_event(url: str, page: str, images: ImageStore) -> dict | None:
    data = json_ld(page, 'Event')
    if not data:
        return None
    raw_body = inner_html(page, 'product-details-box-content')
    starts = ''
    match = re.match(r'(\d{1,2})/(\d{1,2})/(\d{4})', data.get('startDate', ''))
    if match:
        starts = f'{match.group(3)}-{int(match.group(1)):02d}-{int(match.group(2)):02d}'
    location = re.search(r'Emplacement</span>\s*<span class="info-data">([^<(]+)', page)
    return {
        'source_url': url,
        'title': clean_text(data.get('name'))[:180],
        'summary': shorten(clean_text(data.get('description')), 480),
        'body_html': prepare_body(raw_body, images),
        'image_url': data.get('image') or '',
        'starts_at': starts,
        'location': clean_text(location.group(1)) if location else '',
        'language': detect_language(clean_text(data.get('description'))) or 'fr',
    }


def sitemap_urls(fetcher: Fetcher, name: str) -> list[str]:
    xml = fetcher.get(f'{SITE}/{name}')
    return [html.unescape(url) for url in re.findall(r'<loc>([^<]+)</loc>', xml)]


def ensure_category(db, slug: str) -> int | None:
    row = db.execute('SELECT id FROM categories WHERE slug=?', (slug,)).fetchone()
    return row['id'] if row else None


def save_article(db, item: dict, images: ImageStore, template_id: int | None) -> tuple[str, str]:
    existing = db.execute('SELECT id FROM articles WHERE source_url=?', (item['source_url'],)).fetchone()
    if not existing:
        # Résumé créé auparavant par import_articles.py pour la même adresse : il est remplacé par l'article complet.
        existing = db.execute('SELECT id FROM articles WHERE strpos(body_html, ?) > 0 AND strpos(body_html, ?) > 0', (item['source_url'], SUMMARY_MARKER)).fetchone()
    article_id = existing['id'] if existing else uuid.uuid4().hex
    slug = unique_slug(db, item['title'], article_id)
    featured = images.local(item['image_url']) if item['image_url'] else ''
    now = now_iso()
    values = (item['title'], slug, item['excerpt'], item['body_html'], featured, item['author'], item['published_at'], item['language'], item['source_url'], now)
    if existing:
        db.execute("UPDATE articles SET title=?,slug=?,excerpt=?,body_html=?,featured_image=?,author=?,published_at=?,language=?,source_url=?,updated_at=?,status='published' WHERE id=?", (*values, article_id))
    else:
        db.execute("INSERT INTO articles(title,slug,excerpt,body_html,featured_image,author,published_at,language,source_url,updated_at,id,status,template_id,comments_enabled,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'published',?,1,?)", (*values, article_id, template_id, item['published_at']))
    if item['category']:
        category_id = ensure_category(db, item['category'])
        if category_id:
            db.execute('DELETE FROM article_categories WHERE article_id=?', (article_id,))
            db.execute('INSERT OR IGNORE INTO article_categories(article_id,category_id) VALUES(?,?)', (article_id, category_id))
    return ('mis à jour' if existing else 'ajouté'), slug


def save_event(db, item: dict, images: ImageStore) -> str:
    existing = db.execute('SELECT id FROM news_events WHERE source_url=?', (item['source_url'],)).fetchone()
    root = slugify(item['title'])
    slug, number = root, 2
    while db.execute('SELECT id FROM news_events WHERE slug=? AND id<>?', (slug, existing['id'] if existing else 0)).fetchone():
        slug, number = f'{root}-{number}', number + 1
    image = images.local(item['image_url']) if item['image_url'] else ''
    now = now_iso()
    values = ('event', item['title'], slug, item['summary'], item['body_html'], image, item['location'], item['starts_at'], item['starts_at'], 'published', now, item['language'], item['source_url'])
    if existing:
        db.execute('UPDATE news_events SET kind=?,title=?,slug=?,summary=?,body_html=?,image_url=?,location=?,starts_at=?,ends_at=?,status=?,updated_at=?,language=?,source_url=? WHERE id=?', (*values, existing['id']))
        return 'mis à jour'
    db.execute('INSERT INTO news_events(kind,title,slug,summary,body_html,image_url,location,starts_at,ends_at,status,updated_at,language,source_url,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)', (*values, now))
    return 'ajouté'


def link_key(url: str) -> str:
    parsed = urlparse(html.unescape(url))
    return unquote(parsed.path).rstrip('/').lower() if parsed.netloc.endswith('i-dialogos.com') or not parsed.netloc else ''


def rewrite_internal_links(db) -> int:
    """Les liens vers un ancien article importé pointent désormais vers sa nouvelle page."""
    targets = {link_key(row['source_url']): row['slug'] for row in db.execute("SELECT source_url,slug FROM articles WHERE source_url<>''").fetchall()}
    changed = 0
    for row in db.execute("SELECT id,body_html FROM articles WHERE source_url<>''").fetchall():
        def replace(match: re.Match) -> str:
            slug = targets.get(link_key(match.group(1)))
            return f'href="/article/{quote(slug)}"' if slug else match.group(0)
        body = re.sub(r'href="(https?://(?:www\.)?i-dialogos\.com/[^"]+)"', replace, row['body_html'])
        if body != row['body_html']:
            db.execute('UPDATE articles SET body_html=? WHERE id=?', (body, row['id']))
            changed += 1
    return changed


def main() -> None:
    parser = argparse.ArgumentParser(description='Importe les articles de www.i-dialogos.com dans la base PostgreSQL.')
    parser.add_argument('--limite', type=int, default=0, help='nombre maximal d’articles à importer (essai)')
    parser.add_argument('--simulation', action='store_true', help='analyse les pages sans rien écrire')
    parser.add_argument('--sans-images', action='store_true', help='ne télécharge pas les images (liens vers l’ancien site)')
    parser.add_argument('--cache', type=Path, help='dossier où garder les pages téléchargées (relances plus rapides)')
    args = parser.parse_args()
    if Image is None and not args.sans_images:
        log('Pillow n’est pas installé : les images seront gardées dans leur format d’origine (plus lourdes).')
        log('  Pour les convertir en WebP : python -m pip install -r requirements.txt')

    fetcher = Fetcher(args.cache)
    images = ImageStore(fetcher, enabled=not args.sans_images, dry_run=args.simulation)
    urls = [url for name in ARTICLE_SITEMAPS for url in sitemap_urls(fetcher, name)]
    urls = list(dict.fromkeys(urls))
    if args.limite:
        urls = urls[:args.limite]
    log(f'{len(urls)} article(s) à traiter depuis {SITE}')

    if not args.simulation:
        initialize()
        UPLOAD_DIR.mkdir(exist_ok=True)
    counts = {'ajouté': 0, 'mis à jour': 0, 'ignoré': 0, 'erreur': 0}
    with (nullcontext() if args.simulation else connect()) as db:
        template = db.execute('SELECT id FROM templates WHERE is_default=1 LIMIT 1').fetchone() if not args.simulation else None
        template_id = template['id'] if template else None
        for index, url in enumerate(urls, 1):
            try:
                item = parse_article(url, fetcher.get(url), images)
                if not item:
                    counts['ignoré'] += 1
                    log(f'[{index}/{len(urls)}] ignoré (pas de contenu) : {url}')
                    continue
                if args.simulation:
                    log(f"[{index}/{len(urls)}] {item['published_at'][:10]} · {item['language']} · {item['category'] or '—'} · {item['author']} · {item['title'][:70]}")
                    continue
                status, slug = save_article(db, item, images, template_id)
                db.connection.commit()
                counts[status] += 1
                log(f"[{index}/{len(urls)}] {status} : {item['published_at'][:10]} · {item['author']} · /article/{slug}")
            except Exception as exc:
                if db:
                    db.connection.rollback()
                counts['erreur'] += 1
                log(f'[{index}/{len(urls)}] ERREUR {exc} : {url}')
        if not args.simulation and not args.limite:
            for url in [url for name in EVENT_SITEMAPS for url in sitemap_urls(fetcher, name)]:
                try:
                    item = parse_event(url, fetcher.get(url), images)
                    if item:
                        log(f"Événement {save_event(db, item, images)} : {item['title'][:70]}")
                        db.connection.commit()
                except Exception as exc:
                    db.connection.rollback()
                    log(f'Événement non importé ({exc}) : {url}')
        if not args.simulation:
            log(f'Liens internes réécrits dans {rewrite_internal_links(db)} article(s).')
    images.save_manifest()
    log(f"Terminé : {counts['ajouté']} ajouté(s), {counts['mis à jour']} mis à jour, {counts['ignoré']} ignoré(s), {counts['erreur']} erreur(s), {images.saved} image(s) téléchargée(s).")


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    main()
