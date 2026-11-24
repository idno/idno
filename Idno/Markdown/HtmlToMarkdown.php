<?php

declare(strict_types=1);

namespace Idno\Markdown;

use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * A post's HTML as Markdown.
 *
 * The HTML is tidied first, with an HTML5 parser: what has no place in a text document is removed (scripts, styles, frames,
 * forms), an audio or video player becomes a link to each of its files, addresses are made absolute against the site's
 * address, a link that is not http, https or mailto keeps only its text and a picture that is not http or https only its
 * alternative text. The conversion itself is league/html-to-markdown's.
 */
final class HtmlToMarkdown
{
    /** Elements removed with everything in them */
    private const REMOVED = ['script', 'style', 'iframe', 'noscript', 'template', 'object', 'embed', 'form', 'button', 'input', 'select', 'textarea', 'svg', 'canvas', 'map', 'head', 'title', 'link', 'meta'];

    private const LINK_SCHEMES = ['http', 'https', 'mailto'];
    private const IMAGE_SCHEMES = ['http', 'https'];

    private readonly string $origin;
    private readonly string $base;
    private readonly string $scheme;

    /**
     * @param string $siteUrl the site's address (`https://example.com/` or `https://example.com/blog/`): a relative address is under it
     */
    public function __construct(string $siteUrl)
    {
        $parts = parse_url($siteUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new \InvalidArgumentException('The site address must be an absolute http or https address.');
        }
        $this->scheme = $scheme;
        $this->origin = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->base = $this->origin . rtrim((string) ($parts['path'] ?? ''), '/') . '/';
    }

    public function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = \Dom\HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . mb_scrub($html, 'UTF-8') . '</body></html>',
            LIBXML_NOERROR,
            'UTF-8'
        );
        $body = $document->body;
        if ($body === null) {
            return '';
        }

        foreach ($this->all($body, implode(',', self::REMOVED)) as $element) {
            $element->remove();
        }
        foreach ($this->all($body, 'audio, video') as $player) {
            $this->replacePlayer($document, $player);
        }
        foreach ($this->all($body, 'img') as $image) {
            $this->tidyImage($document, $image);
        }
        // Inner links first, so that an outer one that is unwrapped keeps tidied children
        foreach (array_reverse($this->all($body, 'a')) as $link) {
            $this->tidyLink($link);
        }

        $converter = new HtmlConverter([
            'header_style' => 'atx',
            'strip_tags' => true,
            'strip_placeholder_links' => true,
            'remove_nodes' => implode(' ', self::REMOVED),
            'hard_break' => false,
            'list_item_style' => '-',
        ]);
        $converter->getEnvironment()->addConverter(new TableConverter());

        $markdown = $converter->convert($body->innerHTML);

        return $this->tidy($markdown);
    }

    /**
     * The white space the HTML's own layout left behind, removed where it means nothing in Markdown: a line of only white
     * space, one space before the first line of a block, one space at the end of a line (two are a line break), more than one space after the marks of a heading, more than
     * one empty line between blocks. A fenced code block is left as it is.
     */
    private function tidy(string $markdown): string
    {
        $lines = [];
        $fence = null;
        $previous = '';
        foreach (explode("\n", str_replace("\r", '', $markdown)) as $line) {
            if ($fence !== null) {
                if (preg_match('/^ {0,3}' . preg_quote($fence, '/') . '+\s*$/', $line) === 1) {
                    $fence = null;
                }
            } else if (preg_match('/^ {0,3}(```|~~~)/', $line, $match) === 1) {
                $fence = $match[1];
            } else {
                if (trim($line) === '') {
                    $line = '';
                } else {
                    if ($previous === '' && preg_match('/^ (?! )/', $line) === 1) {
                        $line = substr($line, 1);
                    }
                    $line = (string) preg_replace('/(?<! ) $/', '', $line);
                    // One space after the marks of a heading
                    $line = (string) preg_replace('/^(#{1,6}) {2,}(?=\S)/', '$1 ', $line);
                }
                if ($line === '' && $previous === '') {
                    continue;
                }
            }
            $lines[] = $line;
            $previous = $line;
        }

        return trim(implode("\n", $lines), "\n");
    }

    /**
     * @return list<\Dom\Element>
     */
    private function all(\Dom\Element $root, string $selector): array
    {
        // A copy of the list: the elements are removed or replaced while it is walked
        return iterator_to_array($root->querySelectorAll($selector), false);
    }

    private function replacePlayer(\Dom\HTMLDocument $document, \Dom\Element $player): void
    {
        if ($player->parentNode === null) {
            return;
        }
        $sources = [];
        if ($player->hasAttribute('src')) {
            $sources[] = (string) $player->getAttribute('src');
        }
        foreach ($player->querySelectorAll('source') as $source) {
            $sources[] = (string) $source->getAttribute('src');
        }
        $label = $player->localName === 'video' ? 'Video' : 'Audio';
        $replacement = $document->createDocumentFragment();
        $seen = [];
        foreach ($sources as $source) {
            $address = $this->absolute($source, self::IMAGE_SCHEMES);
            if ($address === null || isset($seen[$address])) {
                continue;
            }
            if ($seen !== []) {
                $replacement->appendChild($document->createElement('br'));
            }
            $seen[$address] = true;
            $link = $document->createElement('a');
            $link->setAttribute('href', $address);
            $name = rawurldecode(basename((string) parse_url($address, PHP_URL_PATH)));
            $link->textContent = $name !== '' && mb_check_encoding($name, 'UTF-8') ? $label . ': ' . $name : $label;
            $replacement->appendChild($link);
        }
        $player->replaceWith($replacement);
    }

    private function tidyImage(\Dom\HTMLDocument $document, \Dom\Element $image): void
    {
        $address = $this->absolute((string) $image->getAttribute('src'), self::IMAGE_SCHEMES);
        if ($address === null) {
            $image->replaceWith($document->createTextNode((string) $image->getAttribute('alt')));

            return;
        }
        $alt = trim((string) preg_replace('/\s+/u', ' ', (string) $image->getAttribute('alt')));
        foreach (iterator_to_array($image->attributes) as $attribute) {
            $image->removeAttribute($attribute->name);
        }
        $image->setAttribute('src', $address);
        $image->setAttribute('alt', $alt);
    }

    private function tidyLink(\Dom\Element $link): void
    {
        $address = $link->hasAttribute('href') ? $this->absolute((string) $link->getAttribute('href'), self::LINK_SCHEMES) : null;
        if ($address === null) {
            // Only its text is kept
            $link->replaceWith(...iterator_to_array($link->childNodes, false));

            return;
        }
        foreach (iterator_to_array($link->attributes) as $attribute) {
            $link->removeAttribute($attribute->name);
        }
        $link->setAttribute('href', $address);
    }

    /**
     * The address as an absolute one in one of the schemes, or null: an empty one, a fragment of the page, one with a control
     * character or a backslash in it, and any other scheme (`javascript:`, `data:`).
     *
     * @param list<string> $schemes
     */
    private function absolute(string $address, array $schemes): ?string
    {
        $address = trim($address);
        if ($address === '' || $address[0] === '#' || preg_match('/[\x00-\x1F\x7F\\\\]/', $address) === 1) {
            return null;
        }
        $address = str_replace(' ', '%20', $address);
        if (preg_match('/\A([A-Za-z][A-Za-z0-9+.-]*):/', $address, $match) === 1) {
            $scheme = strtolower($match[1]);
            if (!in_array($scheme, $schemes, true)) {
                return null;
            }
            if ($scheme !== 'mailto' && preg_match('/\Ahttps?:\/\/[^\/?#]/i', $address) !== 1) {
                return null;
            }

            return $address;
        }
        if (str_starts_with($address, '//')) {
            return strlen($address) > 2 && $address[2] !== '/' ? $this->scheme . ':' . $address : null;
        }
        if ($address[0] === '/') {
            return $this->origin . $address;
        }
        return $this->base . $address;
    }
}
