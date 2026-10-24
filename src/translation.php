<?php
/**
 * Détection de langue et traduction automatique des publications (même fonctionnement que la version Python).
 *
 * Fournisseur choisi dans les réglages :
 *   DEEPL_API_KEY : DeepL (clé gratuite se terminant par « :fx » ou clé payante) ;
 *   LIBRETRANSLATE_URL (+ LIBRETRANSLATE_API_KEY) : instance LibreTranslate ;
 *   sinon : service web public de Google Traduction (sans clé, non officiel).
 *   TRANSLATION_PROVIDER=none désactive la traduction.
 *
 * Les traductions sont mises en cache par passage de texte dans la table « translations », avec la même clé
 * (empreinte SHA-256 du texte) que la version Python : les traductions déjà faites sont réutilisées.
 */
declare(strict_types=1);

const RETRY_DELAY = 60;

const STOPWORDS = [
    'fr' => 'le la les un une des du de et est en que qui dans pour pas sur au aux ce cette ces son sa ses leur leurs nous vous ils elles avec par plus mais ou où sont été être avoir fait comme tout tous très aussi entre sans sous dont lui elle on ne se l d qu j c n s',
    'en' => 'the of and to in is that for it with as was on be by this are from at or an have not but which their they has were been will its can more would there all also who about than into these other our we',
    'es' => 'el la los las un una unos unas de del y en que es por para con no se su sus lo al como más pero sobre entre también ha son está fue ser muy sin este esta estos donde cuando porque hay',
    'it' => 'il lo la i gli le un una uno di del della dei delle e è che per con non si su nel nella al alla come più ma anche sono tra dal dalla questo questa essere ha hanno degli negli sulla',
    'de' => 'der die das und ist nicht ein eine einer zu den mit von im für auf dem des sich auch es sind wird werden als bei nach aus wie oder aber noch über',
    'pt' => 'o a os as um uma de do da dos das e em que é para com não se por no na nos nas ao como mais mas foi são está também entre sobre muito sem pela pelo',
];
const ACCENT_HINTS = ['fr' => 'çèêëœùûîï', 'es' => 'ñ¿¡', 'pt' => 'ãõ', 'de' => 'ßäöü', 'it' => 'ìò'];

const LANGUAGE_NAMES = [
    'fr' => ['fr' => 'français', 'en' => 'anglais', 'es' => 'espagnol', 'it' => 'italien', 'de' => 'allemand', 'pt' => 'portugais'],
    'en' => ['fr' => 'French', 'en' => 'English', 'es' => 'Spanish', 'it' => 'Italian', 'de' => 'German', 'pt' => 'Portuguese'],
    'es' => ['fr' => 'francés', 'en' => 'inglés', 'es' => 'español', 'it' => 'italiano', 'de' => 'alemán', 'pt' => 'portugués'],
    'it' => ['fr' => 'francese', 'en' => 'inglese', 'es' => 'spagnolo', 'it' => 'italiano', 'de' => 'tedesco', 'pt' => 'portoghese'],
    'de' => ['fr' => 'Französisch', 'en' => 'Englisch', 'es' => 'Spanisch', 'it' => 'Italienisch', 'de' => 'Deutsch', 'pt' => 'Portugiesisch'],
    'pt' => ['fr' => 'francês', 'en' => 'inglês', 'es' => 'espanhol', 'it' => 'italiano', 'de' => 'alemão', 'pt' => 'português'],
];
const NOTICES = [
    'fr' => ['Traduction automatique — langue d’origine : {source}.', 'Lire la version originale', 'Voir la traduction', 'La traduction automatique est momentanément indisponible : le texte est affiché dans sa langue d’origine.'],
    'en' => ['Automatic translation — original language: {source}.', 'Read the original', 'View the translation', 'Automatic translation is temporarily unavailable; the text is shown in its original language.'],
    'es' => ['Traducción automática — idioma original: {source}.', 'Leer la versión original', 'Ver la traducción', 'La traducción automática no está disponible en este momento; el texto se muestra en su idioma original.'],
    'it' => ['Traduzione automatica — lingua originale: {source}.', 'Leggi la versione originale', 'Vedi la traduzione', 'La traduzione automatica non è al momento disponibile; il testo è mostrato nella lingua originale.'],
    'de' => ['Automatische Übersetzung — Originalsprache: {source}.', 'Original lesen', 'Übersetzung anzeigen', 'Die automatische Übersetzung ist derzeit nicht verfügbar; der Text wird in der Originalsprache angezeigt.'],
    'pt' => ['Tradução automática — idioma original: {source}.', 'Ler a versão original', 'Ver a tradução', 'A tradução automática está temporariamente indisponível; o texto é apresentado no idioma original.'],
];

final class TranslationError extends RuntimeException
{
}

/** Détecte la langue d'un texte (fr, en, es, it, de, pt) par fréquence des mots outils. */
function detect_language(string $text): ?string
{
    $lowered = mb_strtolower($text, 'UTF-8');
    preg_match_all('/[' . LETTER . ']+/u', $lowered, $matches);
    $words = array_slice($matches[0], 0, 3000);
    if (!$words) {
        return null;
    }
    static $sets = null;
    $sets ??= array_map(fn(string $list) => array_flip(explode(' ', $list)), STOPWORDS);
    $scores = array_fill_keys(array_keys($sets), 0.0);
    foreach ($words as $word) {
        $owners = [];
        foreach ($sets as $code => $set) {
            if (isset($set[$word])) {
                $owners[] = $code;
            }
        }
        foreach ($owners as $code) {
            $scores[$code] += 1 / count($owners);
        }
    }
    foreach (ACCENT_HINTS as $code => $hints) {
        $count = 0;
        foreach (mb_str_split($hints, 1, 'UTF-8') as $char) {
            $count += mb_substr_count($lowered, $char, 'UTF-8');
        }
        $scores[$code] += 0.5 * min($count, 40);
    }
    $best = null;
    foreach ($scores as $code => $score) {
        if ($best === null || $score > $scores[$best]) {
            $best = $code;
        }
    }
    return ($best === null || $scores[$best] < 2) ? null : $best;
}

/** Textes du bandeau de traduction : [message, lien vers l'original, lien vers la traduction, indisponible]. */
function notice_texts(string $target, string $source): array
{
    [$translated, $originalLink, $translationLink, $unavailable] = NOTICES[$target] ?? NOTICES['en'];
    $name = (LANGUAGE_NAMES[$target] ?? LANGUAGE_NAMES['en'])[$source] ?? strtoupper($source);
    return [str_replace('{source}', $name, $translated), $originalLink, $translationLink, $unavailable];
}

function http_json(string $url, string $body, array $headers)
{
    if (!function_exists('curl_init')) {
        throw new TranslationError('Extension PHP curl indisponible.');
    }
    $timeout = (float) (setting('TRANSLATION_TIMEOUT', '10') ?: 10);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) ceil($timeout), CURLOPT_CONNECTTIMEOUT => (int) ceil($timeout),
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: Mozilla/5.0 (I-Dialogos)'], $headers),
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false || $status >= 400) {
        throw new TranslationError($error ?: "réponse HTTP $status");
    }
    $data = json_decode((string) $response, true);
    if ($data === null) {
        throw new TranslationError('Réponse de traduction illisible.');
    }
    return $data;
}

interface Translator
{
    public function name(): string;
    public function maxChars(): int;
    public function maxItems(): int;
    /** @return string[] */
    public function translate(array $texts, string $target): array;
}

final class GoogleWebTranslator implements Translator
{
    public function name(): string { return 'Google Traduction (service web public, sans clé)'; }
    public function maxChars(): int { return 4000; }
    public function maxItems(): int { return 100; }

    public function translate(array $texts, string $target): array
    {
        $url = 'https://translate.googleapis.com/translate_a/single?' . http_build_query(['client' => 'gtx', 'sl' => 'auto', 'tl' => $target, 'dt' => 't']);
        $response = http_json($url, http_build_query(['q' => implode("\n", $texts)]), ['Content-Type: application/x-www-form-urlencoded;charset=utf-8']);
        if (!is_array($response) || !is_array($response[0] ?? null)) {
            throw new TranslationError('Réponse de traduction inattendue.');
        }
        $joined = '';
        foreach ($response[0] as $part) {
            if (is_array($part) && isset($part[0]) && is_string($part[0])) {
                $joined .= $part[0];
            }
        }
        $lines = explode("\n", $joined);
        if (count($lines) === count($texts)) {
            return array_map('py_strip', $lines);
        }
        if (count($texts) === 1) {
            return [collapse($joined)];
        }
        // Le service a fusionné ou découpé des lignes : on retraduit chaque passage séparément.
        return array_map(fn(string $text) => $this->translate([$text], $target)[0], $texts);
    }
}

final class DeepLTranslator implements Translator
{
    public function __construct(private string $key) {}
    public function name(): string { return 'DeepL'; }
    public function maxChars(): int { return 30000; }
    public function maxItems(): int { return 50; }

    public function translate(array $texts, string $target): array
    {
        $url = str_ends_with($this->key, ':fx') ? 'https://api-free.deepl.com/v2/translate' : 'https://api.deepl.com/v2/translate';
        $code = ['en' => 'EN-GB', 'pt' => 'PT-PT'][$target] ?? strtoupper($target);
        $response = http_json($url, json_encode(['text' => array_values($texts), 'target_lang' => $code]),
            ['Authorization: DeepL-Auth-Key ' . $this->key, 'Content-Type: application/json']);
        if (!isset($response['translations']) || !is_array($response['translations'])) {
            throw new TranslationError('Réponse DeepL inattendue.');
        }
        return array_map(fn($item) => (string) ($item['text'] ?? ''), $response['translations']);
    }
}

final class LibreTranslator implements Translator
{
    public function __construct(private string $url, private string $key = '') {}
    public function name(): string { return 'LibreTranslate'; }
    public function maxChars(): int { return 8000; }
    public function maxItems(): int { return 50; }

    public function translate(array $texts, string $target): array
    {
        $body = ['q' => array_values($texts), 'source' => 'auto', 'target' => $target, 'format' => 'text'];
        if ($this->key !== '') {
            $body['api_key'] = $this->key;
        }
        $response = http_json(rtrim($this->url, '/') . '/translate', json_encode($body), ['Content-Type: application/json']);
        if (!isset($response['translatedText'])) {
            throw new TranslationError('Réponse LibreTranslate inattendue.');
        }
        return (array) $response['translatedText'];
    }
}

/** Fournisseur factice des tests automatiques : « [en] texte ». */
final class FakeTranslator implements Translator
{
    public function name(): string { return 'test'; }
    public function maxChars(): int { return 4000; }
    public function maxItems(): int { return 50; }
    public function translate(array $texts, string $target): array
    {
        return array_map(fn(string $text) => "[$target] $text", $texts);
    }
}

function translator(): ?Translator
{
    static $translator = false;
    if ($translator === false) {
        $choice = strtolower(setting('TRANSLATION_PROVIDER'));
        if ($choice === 'none') {
            $translator = null;
        } elseif ($choice === 'fake') {
            $translator = new FakeTranslator();
        } elseif (setting('DEEPL_API_KEY') !== '') {
            $translator = new DeepLTranslator(setting('DEEPL_API_KEY'));
        } elseif (setting('LIBRETRANSLATE_URL') !== '') {
            $translator = new LibreTranslator(setting('LIBRETRANSLATE_URL'), setting('LIBRETRANSLATE_API_KEY'));
        } else {
            $translator = new GoogleWebTranslator();
        }
    }
    return $translator;
}

function provider_name(): string
{
    return translator()?->name() ?? 'désactivée (TRANSLATION_PROVIDER=none)';
}

function translation_paused(): bool
{
    $file = sys_get_temp_dir() . '/idialogos-translation-pause';
    return is_file($file) && (int) @file_get_contents($file) > time();
}

function pause_translation(): void
{
    @file_put_contents(sys_get_temp_dir() . '/idialogos-translation-pause', (string) (time() + RETRY_DELAY));
}

function split_long(string $text, int $limit): array
{
    if (mb_strlen($text, 'UTF-8') <= $limit) {
        return [$text];
    }
    $pieces = [];
    $current = '';
    foreach (preg_split('/(?<=[.!?…;])\s+/u', $text) as $sentence) {
        while (mb_strlen($sentence, 'UTF-8') > $limit) {
            $pieces[] = mb_substr($sentence, 0, $limit, 'UTF-8');
            $sentence = mb_substr($sentence, $limit, null, 'UTF-8');
        }
        if ($current !== '' && mb_strlen($current, 'UTF-8') + mb_strlen($sentence, 'UTF-8') + 1 > $limit) {
            $pieces[] = $current;
            $current = $sentence;
        } else {
            $current = trim("$current $sentence");
        }
    }
    if ($current !== '') {
        $pieces[] = $current;
    }
    return $pieces;
}

/** Traduit une liste de textes vers $target. null pour chaque texte non traduit. */
function translate_texts(Db $db, array $texts, string $target): array
{
    $results = array_fill(0, count($texts), null);
    if (!$texts) {
        return $results;
    }
    $keys = array_map(fn(string $text) => hash('sha256', $text), $texts);
    $cached = [];
    foreach ($db->all('SELECT hash,translated FROM translations WHERE target=? AND hash = ANY(?)', [$target, array_values(array_unique($keys))]) as $row) {
        $cached[$row['hash']] = $row['translated'];
    }
    $missing = [];
    foreach ($keys as $index => $key) {
        if (!isset($cached[$key]) && !isset($missing[$key])) {
            $missing[$key] = $texts[$index];
        }
    }
    $provider = translator();
    if ($missing && $provider && !translation_paused()) {
        $pieces = [];
        foreach ($missing as $key => $text) {
            foreach (split_long($text, $provider->maxChars()) as $piece) {
                $pieces[] = [$key, $piece];
            }
        }
        $translated = [];
        $failed = [];
        $batch = [];
        $flush = function () use (&$batch, &$translated, $provider, $target): void {
            $outputs = $provider->translate(array_column($batch, 1), $target);
            if (count($outputs) !== count($batch)) {
                throw new TranslationError('Nombre de passages traduits incohérent.');
            }
            foreach ($batch as $index => [$key]) {
                $translated[$key][] = $outputs[$index];
            }
            $batch = [];
        };
        try {
            foreach ($pieces as $piece) {
                $size = array_sum(array_map(fn($item) => mb_strlen($item[1], 'UTF-8') + 1, $batch));
                if ($batch && ($size + mb_strlen($piece[1], 'UTF-8') > $provider->maxChars() || count($batch) >= $provider->maxItems())) {
                    $flush();
                }
                $batch[] = $piece;
            }
            if ($batch) {
                $flush();
            }
        } catch (TranslationError $error) {
            pause_translation();
            $failed = array_flip(array_column($batch, 0));
            error_log('Traduction indisponible (' . $error->getMessage() . ') : nouvel essai dans ' . RETRY_DELAY . ' s.');
        }
        $expected = [];
        foreach ($pieces as [$key]) {
            $expected[$key] = ($expected[$key] ?? 0) + 1;
        }
        $now = now_iso();
        foreach ($translated as $key => $outputs) {
            if (isset($failed[$key]) || count($outputs) !== $expected[$key]) {
                continue;
            }
            $value = implode(' ', array_map('py_strip', array_filter($outputs, fn($output) => $output !== '')));
            if ($value !== '') {
                $cached[$key] = $value;
                $db->execute('INSERT OR IGNORE INTO translations(hash,target,translated,created_at) VALUES(?,?,?,?)', [$key, $target, $value, $now]);
            }
        }
    }
    foreach ($keys as $index => $key) {
        $results[$index] = $cached[$key] ?? null;
    }
    return $results;
}

const INLINE_TAGS = ['a', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'span', 'font', 'code', 'small', 'mark'];

/**
 * Un passage de texte continu (paragraphe, titre, élément de liste…) traduit d'un seul tenant.
 * Les balises en ligne (gras, lien…) sont remplacées par des marqueurs <g1>…</g1> que le service conserve.
 */
final class Segment
{
    public array $tags = [];
    public string $source;
    public string $lead;
    public string $trail;
    public array $nodes;

    public function __construct(public array $tokens)
    {
        $raw = '';
        foreach ($tokens as $token) {
            if ($token[0] === 'open') {
                $this->tags[$token[1]] = [$token[2], ''];
                $raw .= '<g' . $token[1] . '>';
            } elseif ($token[0] === 'close') {
                $this->tags[$token[1]][1] = $token[2];
                $raw .= '</g' . $token[1] . '>';
            } else {
                $raw .= $token[1];
            }
        }
        $this->source = collapse($raw);
        $this->lead = starts_with_space($raw) ? ' ' : '';
        $this->trail = ends_with_space($raw) ? ' ' : '';
        $this->nodes = [];
        foreach ($tokens as $token) {
            if ($token[0] === 'text' && has_letter($token[1])) {
                $this->nodes[] = collapse($token[1]);
            }
        }
    }

    public function original(): string
    {
        $out = '';
        foreach ($this->tokens as $token) {
            $out .= $token[0] === 'text' ? esc_text($token[1]) : $token[2];
        }
        return $out;
    }

    /** Restaure les balises dans la traduction ; null si les marqueurs ont été altérés. */
    public function render(string $translated): ?string
    {
        $out = '';
        $stack = [];
        $seen = [];
        $first = mb_substr($this->nodes[0], 0, 1, 'UTF-8');
        $capitalize = $first !== '' && mb_strtoupper($first, 'UTF-8') === $first && mb_strtolower($first, 'UTF-8') !== $first;
        foreach (preg_split('/(<\/?g\d+>)/', $translated, -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
            if (!preg_match('/^<(\/?)g(\d+)>$/D', $piece, $m)) {
                if ($capitalize && preg_match('/[' . LETTER . ']/u', $piece, $letter, PREG_OFFSET_CAPTURE)) {
                    // Le service perd parfois la majuscule initiale quand la phrase commence par un marqueur.
                    $position = $letter[0][1];
                    $char = $letter[0][0];
                    $piece = substr($piece, 0, $position) . mb_strtoupper($char, 'UTF-8') . substr($piece, $position + strlen($char));
                    $capitalize = false;
                }
                $out .= esc_text($piece);
                continue;
            }
            $number = (int) $m[2];
            if (!isset($this->tags[$number])) {
                return null;
            }
            if ($m[1] === '') {
                if (isset($seen[$number])) {
                    return null;
                }
                $seen[$number] = true;
                $stack[] = $number;
                $out .= $this->tags[$number][0];
            } else {
                if (!$stack || end($stack) !== $number) {
                    return null;
                }
                array_pop($stack);
                $out .= $this->tags[$number][1];
            }
        }
        if ($stack || count($seen) !== count($this->tags)) {
            return null;
        }
        return $this->lead . py_strip($out) . $this->trail;
    }

    /** Solution de repli : chaque morceau de texte traduit séparément, balises inchangées. */
    public function renderNodes(array $translations): string
    {
        $out = '';
        $index = 0;
        foreach ($this->tokens as $token) {
            if ($token[0] === 'text' && has_letter($token[1])) {
                $out .= (starts_with_space($token[1]) ? ' ' : '') . esc_text($translations[$index++]) . (ends_with_space($token[1]) ? ' ' : '');
            } else {
                $out .= $token[0] === 'text' ? esc_text($token[1]) : $token[2];
            }
        }
        return $out;
    }
}

/** Découpe un HTML (déjà nettoyé) en balises conservées et passages à traduire. */
final class Segmenter
{
    public array $parts = [];
    public array $segments = [];
    private array $tokens = [];
    private array $openStack = [];
    private int $counter = 0;

    public function __construct(string $html)
    {
        foreach (html_tokens($html) as $token) {
            if ($token[0] === 'text') {
                $this->tokens[] = ['text', $token[1]];
            } elseif ($token[0] === 'start') {
                if ($token[4]) {
                    $this->flush();
                    $this->parts[] = $token[3];
                } elseif (in_array($token[1], INLINE_TAGS, true)) {
                    $this->counter++;
                    $this->tokens[] = ['open', $this->counter, $token[3]];
                    $this->openStack[] = [$this->counter, $token[1]];
                } else {
                    $this->flush();
                    $this->parts[] = $token[3];
                }
            } else {
                $top = end($this->openStack);
                if ($top && $top[1] === $token[1]) {
                    array_pop($this->openStack);
                    $this->tokens[] = ['close', $top[0], '</' . $token[1] . '>'];
                } else {
                    $this->flush();
                    $this->parts[] = '</' . $token[1] . '>';
                }
            }
        }
        $this->flush();
    }

    private function flush(): void
    {
        // Une balise ouverte dans ce passage mais fermée plus loin reste hors traduction.
        $unclosed = array_flip(array_column($this->openStack, 0));
        $tokens = $this->tokens;
        $this->tokens = [];
        $this->openStack = [];
        $chunk = [];
        foreach ($tokens as $token) {
            if ($token[0] === 'open' && isset($unclosed[$token[1]])) {
                $this->emit($chunk);
                $this->parts[] = $token[2];
                $chunk = [];
            } else {
                $chunk[] = $token;
            }
        }
        $this->emit($chunk);
    }

    private function emit(array $tokens): void
    {
        if (!$tokens) {
            return;
        }
        $segment = new Segment($tokens);
        if ($segment->nodes) {
            $this->parts[] = $segment;
            $this->segments[] = $segment;
        } else {
            $this->parts[] = $segment->original();
        }
    }

    public function rebuild(array $rendered): string
    {
        $out = '';
        foreach ($this->parts as $part) {
            $out .= $part instanceof Segment ? $rendered[spl_object_id($part)] : $part;
        }
        return $out;
    }
}

/**
 * Renvoie des copies des enregistrements avec les champs traduits vers $target.
 * Chaque copie reçoit translated_from (langue d'origine si traduit), translation_missing et display_language.
 */
function translate_records(Db $db, array $records, string $target, array $fields = [], array $htmlFields = [], string $languageKey = 'language'): array
{
    $copies = [];
    $plainJobs = [];
    $htmlJobs = [];
    $texts = [];
    foreach ($records as $i => $record) {
        $copy = $record;
        $copy['translated_from'] = '';
        $copy['translation_missing'] = false;
        $copy['display_language'] = $target;
        $copies[$i] = $copy;
        $source = strtolower((string) ($record[$languageKey] ?? ''));
        if ($source === $target) {
            continue;
        }
        foreach ($fields as $field) {
            $value = collapse((string) ($record[$field] ?? ''));
            if (has_letter($value)) {
                $plainJobs[] = [$i, $field, count($texts)];
                $texts[] = $value;
            }
        }
        foreach ($htmlFields as $field) {
            $segmenter = new Segmenter((string) ($record[$field] ?? ''));
            if ($segmenter->segments) {
                $htmlJobs[] = [$i, $field, $segmenter, count($texts)];
                foreach ($segmenter->segments as $segment) {
                    $texts[] = $segment->source;
                }
            }
        }
    }
    $results = translate_texts($db, $texts, $target);

    $rendered = [];
    $fallback = [];
    $fallbackTexts = [];
    foreach ($htmlJobs as [, , $segmenter, $start]) {
        foreach ($segmenter->segments as $offset => $segment) {
            $value = $results[$start + $offset];
            $id = spl_object_id($segment);
            $rendered[$id] = $value !== null ? $segment->render($value) : null;
            if ($value !== null && $rendered[$id] === null) {
                $fallback[] = [$segment, count($fallbackTexts)];
                array_push($fallbackTexts, ...$segment->nodes);
            }
        }
    }
    if ($fallbackTexts) {
        $fallbackResults = translate_texts($db, $fallbackTexts, $target);
        foreach ($fallback as [$segment, $start]) {
            $values = array_slice($fallbackResults, $start, count($segment->nodes));
            if (!in_array(null, $values, true)) {
                $rendered[spl_object_id($segment)] = $segment->renderNodes($values);
            }
        }
    }
    $mark = function (array &$copy) use ($languageKey): void {
        $source = strtolower((string) ($copy[$languageKey] ?? ''));
        $copy['translated_from'] = $source !== '' ? $source : 'auto';
    };
    foreach ($plainJobs as [$i, $field, $index]) {
        if ($results[$index] === null) {
            $copies[$i]['translation_missing'] = true;
        } else {
            $copies[$i][$field] = $results[$index];
            $mark($copies[$i]);
        }
    }
    foreach ($htmlJobs as [$i, $field, $segmenter]) {
        $complete = true;
        foreach ($segmenter->segments as $segment) {
            if (($rendered[spl_object_id($segment)] ?? null) === null) {
                $complete = false;
                break;
            }
        }
        if (!$complete) {
            $copies[$i]['translation_missing'] = true;
        } else {
            $copies[$i][$field] = $segmenter->rebuild($rendered);
            $mark($copies[$i]);
        }
    }
    return array_values($copies);
}
