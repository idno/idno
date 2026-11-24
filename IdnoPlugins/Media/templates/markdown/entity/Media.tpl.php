<?php

/*
 * An audio or video post for the Markdown form: a link to each file (the converter makes one of a player), then the text.
 */

$object = $vars['object'];
$video = substr((string) $object->media_type, 0, 5) == 'video';

foreach ((array) $object->getAttachments() as $attachment) {
    $src = \Idno\Core\UrlValidator::httpUrl($this->makeDisplayURL(\Idno\Core\Idno::site()->config()->sanitizeAttachmentURL($attachment['url'] ?? '')));
    if ($src === '') {
        continue;
    }
    $name = trim(strip_tags((string) $object->getTitle()));
    if ($name === '') {
        $name = (string) ($attachment['filename'] ?? '');
    }
    ?>
    <p><a href="<?php echo htmlspecialchars($src) ?>"><?php echo htmlspecialchars(($video ? \Idno\Core\Idno::site()->language()->_('Video') : \Idno\Core\Idno::site()->language()->_('Audio')) . ($name !== '' ? ': ' . $name : '')) ?></a></p>
    <?php
}

echo $this->autop($this->parseHashtags($this->parseURLs($this->sanitize_html((string) $object->body))));
