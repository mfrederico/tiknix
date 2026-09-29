<?php
/**
 * HtmlSanitizer — allowlist HTML cleaning that survives the tricks a regex can't.
 *
 * The inbox renders member- and webhook-supplied HTML *raw* (a reply, an inbound
 * email body). Two controllers used to guard that sink with `strip_tags` + a
 * handful of `preg_replace`s, and both leaked:
 *
 *   - `<img/onerror=alert(1)>`  — the `\son\w+` rule needs whitespace before `on`,
 *     but a `/` separates the tag name from the attribute just as well for a parser.
 *   - `href="java&#09;script:x"` / `href="jav&#97;script:"` — a tab, newline, NUL or
 *     HTML entity inside the scheme defeats a literal `javascript:` match, and the
 *     browser decodes it back before it navigates.
 *   - `href="data:text/html,..."`, `vbscript:` — never in the regex at all.
 *
 * A regex sees text; a browser sees a parse tree. So we parse the same tree the
 * browser will (DOMDocument), drop every tag and attribute not on an allowlist,
 * and validate URL attributes by their *decoded* scheme. Nothing outside the
 * allowlist can survive, so a new evasion is not a new bypass.
 *
 * Not a general-purpose purifier — it is deliberately narrow (a fixed inline/blocky
 * tag set, http/https/mailto/tel plus relative and cid: URLs). If a caller needs
 * more it passes an explicit larger tag set; it can never pass a laxer URL policy.
 */

namespace app;

class HtmlSanitizer
{
    /** Tags every caller gets. Inline formatting + basic structure, no forms/scripts/media. */
    public const BASE_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li',
        'a', 'h1', 'h2', 'h3', 'h4', 'span', 'div', 'blockquote',
    ];

    /** Attributes allowed per tag. Anything else (style, class, on*, data-*) is stripped. */
    private const ATTR_ALLOW = [
        'a'   => ['href', 'title'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
    ];

    /** URL schemes we let through, lower-cased and decoded first. */
    private const URL_SCHEMES = ['http', 'https', 'mailto', 'tel', 'cid'];

    /**
     * @param string   $html        Untrusted HTML.
     * @param string[] $extraTags   Additional allowed tags (e.g. ['pre','code','img']).
     * @return string  Cleaned HTML, safe to render raw.
     */
    public static function clean(string $html, array $extraTags = []): string
    {
        if (trim($html) === '') {
            return '';
        }

        $allowedTags = array_flip(array_map('strtolower', array_merge(self::BASE_TAGS, $extraTags)));

        $dom = new \DOMDocument('1.0', 'UTF-8');
        // A wrapper + explicit UTF-8 meta so DOMDocument doesn't mangle multibyte input,
        // and the LIBXML flags keep it from inventing <html>/<body> or emitting warnings.
        $prev = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"><div id="__sanitize_root__">' . $html . '</div>';
        $ok = $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            // Parsing failed outright — never fall back to returning the raw input.
            // Strip to text so the message is still readable but inert.
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $root = $dom->getElementById('__sanitize_root__');
        if ($root === null) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        self::scrub($root, $allowedTags);

        // innerHTML of the wrapper: save each child, not the wrapper itself.
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }

    /** Depth-first: unwrap disallowed tags, strip bad attributes, neutralize bad URLs. */
    private static function scrub(\DOMNode $node, array $allowedTags): void
    {
        // Snapshot children first — we mutate the live NodeList as we go.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                /** @var \DOMElement $child */
                $tag = strtolower($child->nodeName);

                if (!isset($allowedTags[$tag])) {
                    // Disallowed tag: recurse into it first (keep its safe text/children),
                    // then unwrap by hoisting those children into the parent and removing it.
                    self::scrub($child, $allowedTags);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                self::scrubAttributes($child, $tag);
                self::scrub($child, $allowedTags);
            }
            // Text/CDATA/etc. are left as-is; saveHTML re-encodes entities safely.
        }
    }

    private static function scrubAttributes(\DOMElement $el, string $tag): void
    {
        $allow = self::ATTR_ALLOW[$tag] ?? [];
        // Snapshot: removing attributes mutates the live NamedNodeMap.
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            if (!in_array($name, $allow, true)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }
            if ($name === 'href' || $name === 'src') {
                if (!self::urlIsSafe($attr->nodeValue)) {
                    $el->removeAttribute($attr->nodeName);
                }
            }
        }

        // Anchors that survived get safe rel/target so an opened link can't reach us.
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('rel', 'noopener noreferrer nofollow');
            $el->setAttribute('target', '_blank');
        }
    }

    /**
     * A URL is safe if, once decoded and de-obfuscated, its scheme is on the allowlist
     * or it is scheme-relative to the current page (relative path, anchor, query).
     */
    private static function urlIsSafe(?string $url): bool
    {
        if ($url === null) {
            return false;
        }
        // Decode HTML entities the browser would decode (&#106; etc.), then strip the
        // control/whitespace chars a browser ignores inside a scheme: NUL, tab, CR, LF.
        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/[\x00-\x20]+/', '', $decoded);
        if ($decoded === null || $decoded === '') {
            return false;
        }

        // No scheme (relative path, #anchor, ?query, //host is rejected below) → safe.
        if (!preg_match('#^([a-z][a-z0-9+.\-]*):#i', $decoded, $m)) {
            // Protocol-relative "//evil.com" has no scheme but IS absolute — reject it,
            // it inherits the page scheme and points off-site with no policy applied.
            return strncmp($decoded, '//', 2) !== 0;
        }

        return in_array(strtolower($m[1]), self::URL_SCHEMES, true);
    }
}
