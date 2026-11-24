<?php

/*
 * A page as a Markdown document: YAML front matter, then the content. The headers are DefaultTemplate::sendMarkdownHeaders()'s.
 *
 * - A post's permalink (Page::drawMarkdown() names the entity): front matter with the field names of an RSS 2.0 item, then
 *   the post's content.
 * - A page that lists posts (the home page, a profile, a tag, a search: the page gave its `items`): the page's title and
 *   address, then each post under a heading that links to it, and links to the newer and older pages of the list.
 * - Any other page (a form, a settings page, an error): the page's title and its body.
 *
 * Content is always HTML first (an entity's own, drawn as a feed draws it: `entity/<Class>` of this type, else the
 * `classic` one; or the page's body), purified, and converted by HtmlToMarkdown, the one place Markdown is made.
 */

$site = \Idno\Core\Idno::site();
$converter = new \Idno\Markdown\HtmlToMarkdown($site->config()->getDisplayURL());
$oneLine = static function ($text): string {
    $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim((string) preg_replace('/\s+/u', ' ', mb_scrub($text, 'UTF-8')));
};

// The title an RSS item of the post has
$titleOf = static function (\Idno\Common\Entity $object) use ($oneLine): string {
    $title = $oneLine($object->getTitle());
    if ($title === '') {
        $title = $oneLine($object->getShortDescription(5));
    }
    if ($title === '') {
        $title = 'New ' . $object->getContentTypeTitle();
    }

    return $title;
};

// The post's content as Markdown
$contentOf = function (\Idno\Common\Entity $object) use ($site, $converter): string {
    $html = '';

    // What the post answers
    $inReplyTo = [];
    foreach ((array) $object->inreplyto as $address) {
        $address = \Idno\Core\UrlValidator::httpUrl($address);
        if ($address !== '') {
            $inReplyTo[] = '<a href="' . htmlspecialchars($address) . '">' . htmlspecialchars($address) . '</a>';
        }
    }
    if (!empty($inReplyTo)) {
        $html .= '<p>' . $site->language()->esc_('In reply to') . ' ' . implode(', ', array_unique($inReplyTo)) . '</p>';
    }

    $html .= $this->sanitize_html((string) $object->draw(true));

    return $converter->convert($html);
};

$object = $vars['markdown_entity'] ?? null;

if ($object instanceof \Idno\Common\Entity && !($object instanceof \Idno\Entities\User)) {
    $title = $titleOf($object);
    $owner = $object->getOwner();

    // What the HTML page says of itself in its description
    // (a tag is a space first, so that the words of two paragraphs stay two words)
    $description = $object->short_description;
    if (empty($description) || !is_string($description)) {
        $description = $this->sampleText($oneLine(preg_replace('/<[^>]*>/', ' ', (string) $object->getDescription())));
    }
    $description = $oneLine($description);
    if ($description === $title) {
        $description = '';
    }

    $categories = [];
    foreach ((array) $object->getTags() as $tag) {
        $tag = ltrim($oneLine($tag), '#');
        if ($tag !== '' && !in_array(mb_strtolower($tag), array_map('mb_strtolower', $categories), true)) {
            $categories[] = $tag;
        }
    }

    echo \Idno\Markdown\FrontMatter::render([
        'title' => $title,
        'author' => !empty($owner) ? $oneLine($owner->getTitle()) : null,
        'pubDate' => date(DATE_RSS, (int) $object->created),
        'description' => $description !== '' ? $description : null,
        'link' => (string) $object->getURL(),
        'guid' => (string) $object->getUUID(),
        'category' => $categories,
    ]);

    $body = $contentOf($object);
    if ($body !== '') {
        echo "\n" . $body . "\n";
    }

    return;
}

// Any other page: its title, what it says of itself and its own address (without the `_t` that asked for Markdown)
$title = $oneLine($vars['title'] ?? '');
if ($title === '') {
    $title = $oneLine($site->config()->getTitle());
}
$description = $oneLine($vars['description'] ?? '');
$link = \Idno\Core\UrlValidator::httpUrl($this->getCurrentURLWithoutVar('_t'));
if (\Idno\Core\PageHandler::markdownSuffixRequested()) {
    // (a permalink that could not be shown, asked for as `<permalink>.md`)
    $link = (string) preg_replace('/\.md(?=$|\?)/', '', $link, 1);
}

echo \Idno\Markdown\FrontMatter::render([
    'title' => $title,
    'description' => $description !== '' && $description !== $title ? $description : null,
    'link' => $link !== '' ? $link : null,
]);

$items = [];
foreach ((array) ($vars['items'] ?? []) as $item) {
    if ($item instanceof \Idno\Common\Entity && !($item instanceof \Idno\Entities\User)) {
        $items[] = $item;
    }
}

if (empty($items) || http_response_code() !== 200) {
    // A page that lists nothing (or an error page): its body
    $body = $converter->convert($this->sanitize_html((string) ($vars['body'] ?? '')));
    if ($body !== '') {
        echo "\n" . $body . "\n";
    }

    return;
}

echo "\n" . $converter->convert('<h1>' . htmlspecialchars($title) . '</h1>') . "\n";

foreach ($items as $item) {
    $owner = $item->getOwner();
    $address = \Idno\Core\UrlValidator::httpUrl($item->getURL());
    $heading = htmlspecialchars($titleOf($item));
    if ($address !== '') {
        $heading = '<a href="' . htmlspecialchars($address) . '">' . $heading . '</a>';
    }
    $byline = htmlspecialchars((!empty($owner) ? $oneLine($owner->getTitle()) . ', ' : '') . date(DATE_RSS, (int) $item->created));

    echo "\n" . $converter->convert('<h2>' . $heading . '</h2><p><em>' . $byline . '</em></p>') . "\n";
    $body = $contentOf($item);
    if ($body !== '') {
        echo "\n" . $body . "\n";
    }
}

// The newer and older pages of the list, as Markdown too
$offset = max(0, (int) ($vars['offset'] ?? 0));
$count = (int) ($vars['count'] ?? 0);
$limit = max(1, (int) $site->config()->items_per_page);
$pages = [];
if ($offset > 0) {
    $pages[] = '<a href="' . htmlspecialchars($this->getURLWithVar('offset', max(0, $offset - $limit))) . '">' . $site->language()->esc_('Newer') . '</a>';
}
if ($offset + $limit < $count) {
    $pages[] = '<a href="' . htmlspecialchars($this->getURLWithVar('offset', $offset + $limit)) . '">' . $site->language()->esc_('Older') . '</a>';
}
if (!empty($pages)) {
    echo "\n" . $converter->convert('<hr><p>' . implode(' · ', $pages) . '</p>') . "\n";
}
