<?php

/*
 * A post as a Markdown document: YAML front matter with the field names of an RSS 2.0 item, then the post's content.
 *
 * The content is the entity's own HTML, drawn as a feed draws it (`entity/<Class>` of this type, else the `classic` one),
 * purified, and converted to Markdown. The page (Page::drawMarkdown()) sends the headers. Without an entity (an error page
 * drawn after the type was chosen) the document is the page's title and body.
 */

$site = \Idno\Core\Idno::site();
$converter = new \Idno\Markdown\HtmlToMarkdown($site->config()->getDisplayURL());
$oneLine = static function ($text): string {
    $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim((string) preg_replace('/\s+/u', ' ', mb_scrub($text, 'UTF-8')));
};

$object = $vars['object'] ?? null;

if (!($object instanceof \Idno\Common\Entity) || $object instanceof \Idno\Entities\User) {
    echo \Idno\Markdown\FrontMatter::render(['title' => $oneLine($vars['title'] ?? '')]);
    $body = $converter->convert($this->sanitize_html((string) ($vars['body'] ?? '')));
    if ($body !== '') {
        echo "\n" . $body . "\n";
    }

    return;
}

// The title an RSS item of the post has
$title = $oneLine($object->getTitle());
if ($title === '') {
    $title = $oneLine($object->getShortDescription(5));
}
if ($title === '') {
    $title = 'New ' . $object->getContentTypeTitle();
}

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

$body = $converter->convert($html);
if ($body !== '') {
    echo "\n" . $body . "\n";
}
