"""Import selected I-Dialogos publications into the local PostgreSQL database.

The imported article bodies are concise summaries that link to the source pages.
Run with DATABASE_URL configured: python import_articles.py
"""

from __future__ import annotations

from server import connect, initialize, now_iso, safe_rich_html, slugify
from translation import detect_language


SOURCE_ARTICLES = [
    {
        'title': 'Qui rend le monde découvrable ? Nommer, référencer et préserver la pluralité à l’ère de l’intelligence artificielle',
        'excerpt': 'À l’ère numérique, nommer un territoire ne suffit plus : les langues, les lieux et les savoirs doivent aussi pouvoir être reliés et retrouvés par les systèmes numériques.',
        'author': 'Benoist MALLET DI BENTO',
        'published_at': '2026-10-09T00:00:00+00:00',
        'category': 'Francophonies',
        'tags': ['Intelligence artificielle', 'Francophonie', 'Cultures'],
        'image': 'https://files.cdn-files-a.com/uploads/3401002/2000_6ac7e3666a4df.png',
        'source': 'https://www.i-dialogos.com/analyses1/qui-rend-le-monde-d%C3%A9couvrable-benoist-mallet-di-bento-perche-fr-et-cameroun',
        'summary': 'L’article examine la découvrabilité à l’ère de l’IA : un contenu peut être disponible sans être réellement trouvé. Il invite à relier les variantes linguistiques, les noms de lieux et les savoirs locaux dans des référentiels interopérables, afin que la normalisation numérique ne rende pas certaines cultures invisibles.',
    },
    {
        'title': 'Niger : la Refondation à l’épreuve de ses propres fractures',
        'excerpt': 'Abdoulahi Attayoub appelle à un bilan lucide de la transition nigérienne et à un dialogue national qui prenne en compte la diversité des attentes.',
        'author': 'Abdoulahi ATTAYOUB',
        'published_at': '2026-10-03T00:00:00+00:00',
        'category': 'Géopolitique',
        'tags': ['Niger', 'Sahel', 'Gouvernance'],
        'image': 'https://files.cdn-files-a.com/uploads/3401002/2000_6964f934c4045.png',
        'source': 'https://www.i-dialogos.com/analyses1/sahel-central-un-discours-souverainiste-fragilis%C3%A9-par-les-d%C3%A9rives-populistes-abdoulahi-attayoub-niger',
        'summary': 'Cette analyse dresse le bilan des difficultés de la transition au Niger, notamment sur la sécurité, la gouvernance et la cohésion des institutions. L’auteur plaide pour une rectification politique, un débat public ouvert et une unité nationale construite par la reconnaissance des différences.',
    },
    {
        'title': 'Et si la démocratie n’était pas faite pour nous ?',
        'excerpt': 'Taieb Chadi interroge la confiance dans les institutions marocaines et propose de déplacer le débat vers les conditions d’une gouvernance légitime et efficace.',
        'author': 'Taieb CHADI',
        'published_at': '2026-10-02T00:00:00+00:00',
        'category': 'Démocraties',
        'tags': ['Maroc', 'Démocratie', 'Gouvernance'],
        'image': 'https://files.cdn-files-a.com/uploads/3401002/2000_69ad3f722ac6e.png',
        'source': 'https://www.i-dialogos.com/analyses11/les-m%C3%A9dias-occidentaux-et-la-trag%C3%A9die-de-gaza',
        'summary': 'À partir de la défiance envers les partis et les institutions, Taieb Chadi questionne la portée des élections lorsqu’elles ne s’accompagnent pas d’un socle civique solide. Son texte distingue démocratie et gouvernance et appelle à restaurer la confiance par des résultats, le pluralisme et des actes.',
    },
    {
        'title': 'A Call to Member States / Un appel aux États membres : revitaliser les Nations Unies',
        'excerpt': 'Un appel porté par des anciens fonctionnaires de l’ONU et des acteurs associatifs demande aux États membres d’engager un processus de revitalisation de l’Organisation.',
        'author': 'Ariel FRANÇAIS',
        'published_at': '2026-10-02T00:00:00+00:00',
        'category': 'Géopolitique',
        'tags': ['Nations Unies', 'Multilatéralisme', 'Diplomatie'],
        'image': '',
        'source': 'https://www.i-dialogos.com/analyses1/revitaliser-les-nations-unies-un-appel-aux-%C3%89tats-membres-ariel-francais-merida-m%C3%A9xique',
        'summary': 'L’article présente une initiative adressée aux représentants permanents des États membres à New York. Elle vise à ouvrir un processus de revitalisation des Nations Unies, avec le soutien d’I-Dialogos et de contributeurs engagés dans les réflexions sur la paix.',
    },
]


def ensure_category(db, name: str) -> int:
    row = db.execute('SELECT id FROM categories WHERE LOWER(name)=LOWER(?)', (name,)).fetchone()
    if row:
        return row['id']
    base = slugify(name)
    slug, suffix = base, 2
    while db.execute('SELECT 1 FROM categories WHERE slug=?', (slug,)).fetchone():
        slug, suffix = f'{base}-{suffix}', suffix + 1
    db.execute('INSERT INTO categories(name,slug,created_at) VALUES(?,?,?)', (name, slug, now_iso()))
    return db.execute('SELECT id FROM categories WHERE slug=?', (slug,)).fetchone()['id']


def ensure_tag(db, name: str) -> int:
    row = db.execute('SELECT id FROM tags WHERE LOWER(name)=LOWER(?)', (name,)).fetchone()
    if row:
        return row['id']
    base = slugify(name)
    slug, suffix = base, 2
    while db.execute('SELECT 1 FROM tags WHERE slug=?', (slug,)).fetchone():
        slug, suffix = f'{base}-{suffix}', suffix + 1
    db.execute('INSERT INTO tags(name,slug,created_at) VALUES(?,?,?)', (name, slug, now_iso()))
    return db.execute('SELECT id FROM tags WHERE slug=?', (slug,)).fetchone()['id']


def import_articles() -> None:
    initialize()
    imported = 0
    skipped = 0
    with connect() as db:
        template = db.execute('SELECT id FROM templates WHERE is_default=1 LIMIT 1').fetchone()
        template_id = template['id'] if template else None
        for item in SOURCE_ARTICLES:
            slug = slugify(item['title'])
            if db.execute('SELECT id FROM articles WHERE slug=? OR LOWER(title)=LOWER(?)', (slug, item['title'])).fetchone():
                skipped += 1
                continue
            body = (
                f"<p>{item['summary']}</p>"
                f"<p><a href=\"{item['source']}\" target=\"_blank\" rel=\"noopener noreferrer\">Lire l’article original sur I-Dialogos</a></p>"
            )
            article_id = __import__('uuid').uuid4().hex
            created = now_iso()
            language = detect_language('\n'.join((item['title'], item['excerpt'], item['summary']))) or 'fr'
            db.execute(
                'INSERT INTO articles(id,title,slug,excerpt,body_html,featured_image,status,author,template_id,comments_enabled,created_at,updated_at,published_at,language) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                (article_id, item['title'], slug, item['excerpt'], safe_rich_html(body), item['image'], 'published', item['author'], template_id, 1, created, created, item['published_at'], language),
            )
            category_id = ensure_category(db, item['category'])
            db.execute('INSERT INTO article_categories(article_id,category_id) VALUES(?,?)', (article_id, category_id))
            for tag in item['tags']:
                tag_id = ensure_tag(db, tag)
                db.execute('INSERT INTO article_tags(article_id,tag_id) VALUES(?,?)', (article_id, tag_id))
            imported += 1
    print(f'Import terminé : {imported} article(s) ajouté(s), {skipped} doublon(s) ignoré(s).')


if __name__ == '__main__':
    import_articles()
