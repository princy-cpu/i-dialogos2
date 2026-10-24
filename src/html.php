<?php
/** Outils HTML : échappement, adresses, nettoyage du contenu riche, analyse des balises. */
declare(strict_types=1);

/** Échappe comme html.escape(quote=True) de Python. */
function esc($value): string
{
    return strtr((string) ($value ?? ''), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#x27;']);
}

/** Échappe le texte sans toucher aux guillemets (html.escape(quote=False)). */
function esc_text(string $value): string
{
    return strtr($value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
}

/** Encode un segment d'adresse comme urllib.parse.quote (la barre oblique reste telle quelle). */
function q($value): string
{
    return str_replace('%2F', '/', rawurlencode((string) $value));
}

/** Espaces au sens de Python (str.isspace), espaces insécables comprises. */
const WS = '\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{1C}-\x{1F}\x{85}';
/** Lettres au sens de Python [^\W\d_]. */
const LETTER = '\p{L}\p{Nl}\p{No}';

function collapse(string $value): string
{
    return trim(preg_replace('/[' . WS . ']+/u', ' ', $value) ?? $value, " ");
}

/** Supprime les espaces au début et à la fin (str.strip() de Python). */
function py_strip(string $value): string
{
    return (string) preg_replace('/^[' . WS . ']+|[' . WS . ']+$/u', '', $value);
}

function starts_with_space(string $value): bool
{
    return $value !== '' && preg_match('/^[' . WS . ']/u', $value) === 1;
}

function ends_with_space(string $value): bool
{
    return $value !== '' && preg_match('/[' . WS . ']$/u', $value) === 1;
}

function has_letter(string $value): bool
{
    return preg_match('/[' . LETTER . ']/u', $value) === 1;
}

function mb_cut(string $value, int $length): string
{
    return mb_substr($value, 0, $length, 'UTF-8');
}

function html_to_text(?string $source): string
{
    return html_entity_decode(preg_replace('/<[^>]*>/', ' ', $source ?? '') ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

const TRANSLIT = [
    'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e',
    'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
    'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'À' => 'A', 'Á' => 'A', 'Â' => 'A',
    'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I',
    'Î' => 'I', 'Ï' => 'I', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Ú' => 'U',
    'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y',
];

/** Identifiant d'adresse : « L’eau, été » → « l-eau-ete » (90 caractères au plus), comme la version Python. */
function slugify(string $value): string
{
    if (class_exists('Normalizer')) {
        $value = (string) Normalizer::normalize($value, Normalizer::FORM_KD);
    } else {
        $value = strtr($value, TRANSLIT);
    }
    $ascii = strtolower((string) preg_replace('/[^\x00-\x7F]/u', '', $value));
    $result = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
    return substr($result, 0, 90) ?: 'article';
}

const MONTHS = [
    'fr' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
    'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    'es' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
    'it' => ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'],
];

/** « 2026-10-09… » → « 9 octobre 2026 » dans la langue d'affichage. */
function long_date(?string $value, string $language): string
{
    $value = (string) $value;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return substr($value, 0, 10);
    }
    [$year, $monthNumber, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    $month = (MONTHS[$language] ?? MONTHS['fr'])[$monthNumber - 1];
    if ($language === 'en') {
        return "$month $day, $year";
    }
    if ($language === 'es') {
        return "$day de $month de $year";
    }
    $dayText = ($language === 'fr' && $day === 1) ? '1er' : (string) $day;
    return "$dayText $month $year";
}

/**
 * Découpe un HTML en jetons, comme html.parser de Python :
 *   ['start', balise, attributs, texte brut, auto-fermante] ; ['end', balise] ; ['text', texte décodé].
 * Les commentaires et déclarations sont ignorés ; le contenu de <script> et <style> est conservé comme texte brut.
 */
function html_tokens(string $source): array
{
    $tokens = [];
    $length = strlen($source);
    $position = 0;
    $text = '';
    $flush = function () use (&$text, &$tokens): void {
        if ($text !== '') {
            $tokens[] = ['text', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
            $text = '';
        }
    };
    while ($position < $length) {
        $next = strpos($source, '<', $position);
        if ($next === false) {
            $text .= substr($source, $position);
            break;
        }
        $text .= substr($source, $position, $next - $position);
        $position = $next;
        if (substr($source, $position, 4) === '<!--') {
            $end = strpos($source, '-->', $position + 4);
            $flush();
            $position = $end === false ? $length : $end + 3;
            continue;
        }
        if (preg_match('/\G<\/([a-zA-Z][^\s\/>]*)[^>]*>/A', $source, $m, 0, $position)) {
            $flush();
            $tokens[] = ['end', strtolower($m[1])];
            $position += strlen($m[0]);
            continue;
        }
        if (preg_match('/\G<([a-zA-Z][^\s\/>]*)((?:[\s\/]*[^\s\/>"\'=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*)\s*(\/?)>/A', $source, $m, 0, $position)) {
            $flush();
            $tag = strtolower($m[1]);
            $attributes = [];
            preg_match_all('/([^\s\/>"\'=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $m[2], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $raw = $pair[2] ?? '';
                if (($pair[3] ?? '') !== '') {
                    $raw = $pair[3];
                } elseif (($pair[4] ?? '') !== '') {
                    $raw = $pair[4];
                }
                $hasValue = isset($pair[2]) || isset($pair[3]) || isset($pair[4]);
                $attributes[strtolower($pair[1])] = $hasValue ? html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
            }
            $tokens[] = ['start', $tag, $attributes, $m[0], $m[3] === '/'];
            $position += strlen($m[0]);
            if (in_array($tag, ['script', 'style'], true)) {
                $close = stripos($source, '</' . $tag, $position);
                $content = $close === false ? substr($source, $position) : substr($source, $position, $close - $position);
                if ($content !== '') {
                    $tokens[] = ['text', $content];
                }
                $position = $close === false ? $length : $close;
            }
            continue;
        }
        if (preg_match('/\G<[!?][^>]*>/A', $source, $m, 0, $position)) {
            $flush();
            $position += strlen($m[0]);
            continue;
        }
        $text .= '<';
        $position++;
    }
    $flush();
    return $tokens;
}

/** Nettoie le HTML saisi dans l'éditeur : seules les balises et styles autorisés sont conservés. */
function safe_rich_html(string $source): string
{
    static $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'h2', 'h3', 'h4', 'blockquote', 'ul', 'ol', 'li', 'a', 'hr', 'div', 'span', 'font', 'img'];
    static $void = ['br', 'hr', 'img'];
    static $blocked = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form'];
    static $sizes = ['1' => '10px', '2' => '13px', '3' => '16px', '4' => '18px', '5' => '24px', '6' => '32px', '7' => '48px'];
    static $faces = ['Arial' => 'Arial, sans-serif', 'Georgia' => 'Georgia, serif', 'Times New Roman' => 'Times New Roman, serif', 'Courier New' => 'Courier New, monospace'];

    $out = [];
    $stack = [];
    $blockDepth = 0;
    foreach (html_tokens(mb_cut($source, 500000)) as $token) {
        if ($token[0] === 'text') {
            if (!$blockDepth) {
                $out[] = esc($token[1]);
            }
            continue;
        }
        if ($token[0] === 'end') {
            $tag = $token[1];
            if (in_array($tag, $blocked, true) && $blockDepth) {
                $blockDepth--;
                continue;
            }
            if ($blockDepth) {
                continue;
            }
            if ($tag === 'font') {
                $tag = 'span';
            }
            if (!in_array($tag, $stack, true)) {
                continue;
            }
            while ($stack) {
                $current = array_pop($stack);
                $out[] = "</$current>";
                if ($current === $tag) {
                    break;
                }
            }
            continue;
        }
        [, $tag, $values] = $token;
        if (in_array($tag, $blocked, true)) {
            if (!$token[4]) {
                $blockDepth++;
            }
            continue;
        }
        if ($blockDepth || !in_array($tag, $allowed, true)) {
            continue;
        }
        $rendered = $tag;
        $safe = '';
        $style = (string) ($values['style'] ?? '');
        if ($tag === 'font') {
            $rendered = 'span';
            $styles = [];
            $size = $sizes[(string) ($values['size'] ?? '')] ?? '';
            if ($size !== '') {
                $styles[] = 'font-size:' . $size;
            }
            $face = (string) ($values['face'] ?? '');
            if (isset($faces[$face])) {
                $styles[] = 'font-family:' . $faces[$face];
            }
            $color = (string) ($values['color'] ?? '');
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/D', $color)) {
                $styles[] = 'color:' . $color;
            }
            $safe = $styles ? ' style="' . esc(implode(';', $styles)) . '"' : '';
        } elseif ($tag === 'span') {
            $styles = [];
            if (preg_match('/font-size\s*:\s*(\d{1,2}px|\d{1,2}(?:\.\d+)?(?:rem|em|%))/i', $style, $m)) {
                $styles[] = 'font-size:' . $m[1];
            }
            if (preg_match('/font-family\s*:\s*(Arial|Georgia|Times New Roman|Courier New)/i', $style, $m)) {
                $styles[] = 'font-family:' . $m[1];
            }
            if (preg_match('/color\s*:\s*(#[0-9a-f]{3,8}|(?:rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)))/i', $style, $m)) {
                $styles[] = 'color:' . $m[1];
            }
            $safe = $styles ? ' style="' . esc(implode(';', $styles)) . '"' : '';
        } elseif (in_array($tag, ['p', 'div', 'h2', 'h3', 'h4', 'blockquote'], true)) {
            if (preg_match('/text-align\s*:\s*(left|center|right|justify)/i', $style, $m)) {
                $safe = ' style="text-align:' . strtolower($m[1]) . '"';
            }
        } elseif ($tag === 'a') {
            $href = trim((string) ($values['href'] ?? ''));
            if (preg_match('#^(https://|http://|mailto:|/)#', $href)) {
                $safe = ' href="' . esc($href) . '" rel="noopener noreferrer"';
            }
        } elseif ($tag === 'img') {
            $src = trim((string) ($values['src'] ?? ''));
            if (!str_starts_with($src, '/uploads/') && !str_starts_with($src, 'https://')) {
                continue;
            }
            $safe = ' src="' . esc($src) . '" alt="' . esc($values['alt'] ?? '') . '" loading="lazy"';
        }
        $out[] = "<$rendered$safe>";
        if (!in_array($rendered, $void, true)) {
            if ($token[4]) {
                $out[] = "</$rendered>";  // <p/> : ouverte puis fermée aussitôt, comme html.parser
            } else {
                $stack[] = $rendered;
            }
        }
    }
    while ($stack) {
        $out[] = '</' . array_pop($stack) . '>';
    }
    return implode('', $out);
}
