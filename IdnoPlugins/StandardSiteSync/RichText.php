<?php

namespace IdnoPlugins\StandardSiteSync;

/**
 * The text of an app.bsky.feed.post record and its facets (app.bsky.richtext.facet):
 * plain text, with links, mentions and hashtags marked by the range of BYTES of the
 * UTF-8 text they cover (byteStart inclusive, byteEnd exclusive).
 *
 * The text of a post may hold 300 graphemes and 3000 bytes. Text is only ever cut
 * between two graphemes (so never inside an emoji, or between a letter and its
 * combining accent), and never inside a link, a mention or a hashtag.
 *
 * Everything here works on the values it is given and asks nothing of anybody.
 */
class RichText
{
    const MAX_GRAPHEMES = 300;
    const MAX_BYTES = 3000;

    /// A hashtag may hold 64 graphemes and 640 bytes
    const MAX_TAG_GRAPHEMES = 64;
    const MAX_TAG_BYTES = 640;

    /// The syntax of a DID (https://atproto.com/specs/did)
    const DID_PATTERN = '/\Adid:[a-z]+:[a-zA-Z0-9._:%-]*[a-zA-Z0-9._-]\z/';

    /// An address written out in text
    const URL_PATTERN = <<<'PATTERN'
~(?<![\p{L}\p{N}@/._-])https?://[^\s<>"\x{201C}\x{201D}]+~iu
PATTERN;

    /// A mention: "@" and a handle, which is a domain name
    const MENTION_PATTERN = <<<'PATTERN'
~(?<![\p{L}\p{N}@/._-])@((?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)~u
PATTERN;

    /// A hashtag: "#" and letters, digits or underscores of any script
    const TAG_PATTERN = <<<'PATTERN'
~(?<![\p{L}\p{N}_&/#])[#＃]([\p{L}\p{N}\p{M}_]+)~u
PATTERN;

    /// Elements that start and end a paragraph of text
    const BLOCKS = ['p', 'div', 'li', 'ul', 'ol', 'blockquote', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'tr', 'section', 'article', 'figure', 'figcaption', 'hr'];

    /// Elements whose content is not text
    const SKIPPED = ['script', 'style', 'head', 'title', 'template', 'iframe', 'object', 'svg', 'audio', 'video'];

    /**
     * Text that can go into a record: valid UTF-8, with "\n" for every line ending.
     *
     * @param  mixed $text
     * @return string
     */
    public static function clean($text): string
    {
        if (!is_string($text) || $text === '') {
            return '';
        }
        $text = mb_scrub($text, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }

    /**
     * The graphemes of a text: what a reader takes for its characters.
     *
     * @param  string $text Valid UTF-8
     * @return string[]
     */
    public static function graphemes(string $text): array
    {
        if ($text === '' || !preg_match_all('/\X/u', $text, $found)) {
            return [];
        }

        return $found[0];
    }

    /**
     * @param  string $text
     * @return int The number of graphemes
     */
    public static function length(string $text): int
    {
        return count(self::graphemes($text));
    }

    /**
     * Shorten a text to a number of graphemes (and of bytes), ending it with "…" when
     * anything was left out.
     *
     * @param  string $text
     * @param  int    $maxGraphemes
     * @param  int    $maxBytes
     * @return string
     */
    public static function truncate(string $text, int $maxGraphemes, int $maxBytes = PHP_INT_MAX): string
    {
        $text = trim($text);
        if (self::length($text) <= $maxGraphemes && strlen($text) <= $maxBytes) {
            return $text;
        }
        $cut = self::cutPoint($text, [], $maxGraphemes - 1, $maxBytes - strlen('…'));

        return rtrim(substr($text, 0, $cut)) . '…';
    }

    /**
     * Plain text as a member typed it (a status update): HTML entities are read, tags
     * are not.
     *
     * @param  mixed $text
     * @return array 'text' and 'links' (none)
     */
    public static function fromText($text): array
    {
        $text = self::clean(html_entity_decode(self::clean($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return ['text' => trim((string) preg_replace('/\n{3,}/', "\n\n", $text)), 'links' => []];
    }

    /**
     * The text of a piece of HTML, and the links in it.
     *
     * @param  mixed $html
     * @return array 'text' (plain text) and 'links': a list of ['start' => byte, 'end' => byte,
     *               'uri' => address] for each <a> with an http or https address
     */
    public static function fromHtml($html): array
    {
        $html = self::clean($html);
        if (trim($html) === '') {
            return ['text' => '', 'links' => []];
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><html><body><div>' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $loaded ? $document->getElementsByTagName('body')->item(0) : null;
        if (!$body) {
            return self::fromText(strip_tags($html));
        }

        $out = '';
        $links = [];
        self::walk($body, $out, $links);

        $text = rtrim($out);
        $kept = [];
        foreach ($links as $link) {
            $link['end'] = min($link['end'], strlen($text));
            // The text of a link without the white space at its end
            $link['end'] = $link['start'] + strlen(rtrim(substr($text, $link['start'], $link['end'] - $link['start'])));
            if ($link['end'] > $link['start']) {
                $kept[] = $link;
            }
        }

        return ['text' => $text, 'links' => $kept];
    }

    /**
     * Add the text of a node and of what is in it.
     */
    private static function walk(\DOMNode $node, string &$out, array &$links): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                self::append($out, $child->nodeValue ?? '', self::inPre($child));
                continue;
            }
            if (!($child instanceof \DOMElement)) {
                continue;
            }
            $name = strtolower($child->nodeName);
            if (in_array($name, self::SKIPPED, true)) {
                continue;
            }
            if ($name === 'br') {
                self::append($out, "\n", true);
                continue;
            }
            $block = in_array($name, self::BLOCKS, true);
            if ($block) {
                self::append($out, "\n\n", true);
            }
            if ($name === 'a' && preg_match('~\Ahttps?://[^\s/]+~i', trim($child->getAttribute('href')))) {
                // White space before the text of a link is not part of the link
                $inner = '';
                $innerLinks = [];
                self::walk($child, $inner, $innerLinks);
                $inner = trim($inner);
                if ($inner !== '') {
                    self::append($out, ' ' === substr($child->textContent, 0, 1) ? ' ' : '', false);
                    $start = strlen($out);
                    $out .= $inner;
                    $links[] = ['start' => $start, 'end' => strlen($out), 'uri' => trim($child->getAttribute('href'))];
                }
            } else {
                self::walk($child, $out, $links);
            }
            if ($block) {
                self::append($out, "\n\n", true);
            }
        }
    }

    private static function inPre(\DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent; $parent = $parent->parentNode) {
            if (strtolower($parent->nodeName) === 'pre') {
                return true;
            }
        }

        return false;
    }

    /**
     * Add text to what has been gathered, without ever changing what is already there
     * (the places of links are byte offsets into it): no white space at the start, no
     * two spaces together, no more than one empty line.
     *
     * @param string $out
     * @param string $text
     * @param bool   $lines Whether line endings in $text are meant (they are not in HTML outside <pre>)
     */
    private static function append(string &$out, string $text, bool $lines): void
    {
        if ($text === '') {
            return;
        }
        $text = str_replace("\u{00A0}", ' ', $text);
        if (!$lines) {
            $text = (string) preg_replace('/\s+/u', ' ', $text);
        }
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/ ?\n ?/', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        if ($out === '') {
            $text = ltrim($text);
        } else {
            $trailing = strlen($out) - strlen(rtrim($out, "\n"));
            $leading = strspn($text, "\n");
            if ($leading > max(0, 2 - $trailing)) {
                $text = substr($text, $leading - max(0, 2 - $trailing));
            }
            $last = substr($out, -1);
            if ($last === "\n" || $last === ' ') {
                $text = ltrim($text, ' ');
            }
        }
        $out .= $text;
    }

    /**
     * The handles mentioned in a text ("@alice.example.com"), in lower case.
     *
     * @param  string $text
     * @return string[]
     */
    public static function handles(string $text): array
    {
        if (!preg_match_all(self::MENTION_PATTERN, $text, $found)) {
            return [];
        }

        return array_values(array_unique(array_map('strtolower', $found[1])));
    }

    /**
     * The facets of a text: the links it was given, then the addresses, mentions and
     * hashtags written in it.
     *
     * @param  string $text  The text of the post
     * @param  array  $links Links already known: ['start', 'end', 'uri'] with byte offsets (see fromHtml())
     * @param  array  $dids  The DID of each mentioned handle (lower case) that has one; a mention
     *                       of any other handle stays plain text
     * @return array A list of app.bsky.richtext.facet objects, in the order of the text
     */
    public static function facets(string $text, array $links = [], array $dids = []): array
    {
        $spans = [];
        $add = function (int $start, int $end, array $feature) use (&$spans, $text) {
            if ($start < 0 || $end <= $start || $end > strlen($text)) {
                return;
            }
            foreach ($spans as $span) {
                if ($start < $span['end'] && $end > $span['start']) {
                    return;
                }
            }
            $spans[] = ['start' => $start, 'end' => $end, 'feature' => $feature];
        };

        foreach ($links as $link) {
            if (is_array($link) && is_int($link['start'] ?? null) && is_int($link['end'] ?? null) && self::isHttpUri($link['uri'] ?? null)) {
                $add($link['start'], $link['end'], ['$type' => 'app.bsky.richtext.facet#link', 'uri' => $link['uri']]);
            }
        }

        if (preg_match_all(self::URL_PATTERN, $text, $found, PREG_OFFSET_CAPTURE)) {
            foreach ($found[0] as [$url, $offset]) {
                $url = self::withoutTrailingPunctuation($url);
                if (self::isHttpUri($url)) {
                    $add($offset, $offset + strlen($url), ['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]);
                }
            }
        }

        if (preg_match_all(self::MENTION_PATTERN, $text, $found, PREG_OFFSET_CAPTURE)) {
            foreach ($found[0] as $i => [$mention, $offset]) {
                $did = $dids[strtolower($found[1][$i][0])] ?? null;
                if (is_string($did) && preg_match(self::DID_PATTERN, $did)) {
                    $add($offset, $offset + strlen($mention), ['$type' => 'app.bsky.richtext.facet#mention', 'did' => $did]);
                }
            }
        }

        if (preg_match_all(self::TAG_PATTERN, $text, $found, PREG_OFFSET_CAPTURE)) {
            foreach ($found[0] as $i => [$hashtag, $offset]) {
                $tag = $found[1][$i][0];
                if (self::isTag($tag)) {
                    $add($offset, $offset + strlen($hashtag), ['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $tag]);
                }
            }
        }

        usort($spans, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        return array_map(function ($span) {
            return [
                'index'    => ['byteStart' => $span['start'], 'byteEnd' => $span['end']],
                'features' => [$span['feature']],
            ];
        }, $spans);
    }

    /**
     * Make a text and its facets fit a post. A text that is too long is cut between two
     * graphemes (at the end of a word where there is one near, and never inside a
     * facet), and ends with "…" and a link to the whole post.
     *
     * @param  string $text
     * @param  array  $facets    What facets() returned for $text
     * @param  string $permalink The address of the whole post; may be empty
     * @param  int    $maxGraphemes What the text may hold: a Bluesky post's limits unless given
     * @param  int    $maxBytes
     * @return array 'text', 'facets' and 'truncated'
     */
    public static function fit(string $text, array $facets, string $permalink = '', int $maxGraphemes = self::MAX_GRAPHEMES, int $maxBytes = self::MAX_BYTES): array
    {
        if (self::length($text) <= $maxGraphemes && strlen($text) <= $maxBytes) {
            return ['text' => $text, 'facets' => $facets, 'truncated' => false];
        }

        $label = self::isHttpUri($permalink) ? self::shortUrl($permalink) : '';
        $suffix = $label === '' ? '…' : '… ' . $label;

        $cut = self::cutPoint($text, $facets, $maxGraphemes - self::length($suffix), $maxBytes - strlen($suffix));
        $kept = rtrim(substr($text, 0, $cut));

        $facets = array_values(array_filter($facets, function ($facet) use ($kept) {
            return $facet['index']['byteEnd'] <= strlen($kept);
        }));
        if ($label !== '') {
            $start = strlen($kept) + strlen('… ');
            $facets[] = [
                'index'    => ['byteStart' => $start, 'byteEnd' => $start + strlen($label)],
                'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $permalink]],
            ];
        }

        return ['text' => $kept . $suffix, 'facets' => $facets, 'truncated' => true];
    }

    /**
     * Where to cut a text so that what is kept has at most so many graphemes and bytes:
     * a byte offset that is between two graphemes and not inside a facet.
     */
    private static function cutPoint(string $text, array $facets, int $maxGraphemes, int $maxBytes): int
    {
        $cut = 0;
        $count = 0;
        foreach (self::graphemes($text) as $grapheme) {
            if ($count + 1 > $maxGraphemes || $cut + strlen($grapheme) > $maxBytes) {
                break;
            }
            $count++;
            $cut += strlen($grapheme);
        }
        if ($cut >= strlen($text)) {
            return strlen($text);
        }

        // Prefer the end of a word, when one is near
        if (!in_array($text[$cut], [' ', "\n"], true)) {
            $head = substr($text, 0, $cut);
            $space = max((int) strrpos($head, ' '), (int) strrpos($head, "\n"));
            if ($space > 0 && $cut - $space <= 80) {
                $cut = $space;
            }
        }

        foreach ($facets as $facet) {
            if ($facet['index']['byteStart'] < $cut && $cut < $facet['index']['byteEnd']) {
                $cut = $facet['index']['byteStart'];
            }
        }

        return $cut;
    }

    /**
     * An address as it is shown in a post: without its scheme, and not long.
     *
     * @param  string $url
     * @return string
     */
    public static function shortUrl(string $url): string
    {
        $short = rtrim((string) preg_replace('~\Ahttps?://~i', '', $url), '/');
        if (self::length($short) > 32) {
            $short = implode('', array_slice(self::graphemes($short), 0, 31)) . '…';
        }

        return $short;
    }

    /**
     * Whether a word can be a hashtag: not only digits, and not too long.
     *
     * @param  mixed $tag The tag without "#"
     * @return bool
     */
    public static function isTag($tag): bool
    {
        return is_string($tag)
            && $tag !== ''
            && !preg_match('/\A[\p{N}_]+\z/u', $tag)
            && strlen($tag) <= self::MAX_TAG_BYTES
            && self::length($tag) <= self::MAX_TAG_GRAPHEMES;
    }

    /**
     * @param  mixed $uri
     * @return bool Whether this is an http or https address
     */
    public static function isHttpUri($uri): bool
    {
        return is_string($uri) && strlen($uri) <= 2048 && preg_match('~\Ahttps?://[^\s/?#]+[^\s]*\z~i', $uri) === 1;
    }

    /**
     * An address found in text, without the punctuation of the sentence around it.
     */
    private static function withoutTrailingPunctuation(string $url): string
    {
        while ($url !== '') {
            $last = substr($url, -1);
            if (strpos('.,;:!?\'"*', $last) !== false) {
                $url = substr($url, 0, -1);
            } elseif ($last === ')' && substr_count($url, '(') < substr_count($url, ')')) {
                $url = substr($url, 0, -1);
            } else {
                break;
            }
        }

        return $url;
    }
}
