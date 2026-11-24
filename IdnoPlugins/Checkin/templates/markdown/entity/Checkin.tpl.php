<?php

/*
 * A check-in for the Markdown form: the place and its coordinates (as precise as this viewer may see them), then the text.
 */

$object = $vars['object'];
$lang = \Idno\Core\Idno::site()->language();

$place = $object->canSeePreciseLocation() ? trim(strip_tags((string) $object->placename)) : '';
$lat = $object->lat();
$long = $object->long();

if ($place !== '' || ($lat !== null && $long !== null)) {
    ?>
    <p><?php
    echo htmlspecialchars($lang->_('Location')) . ': ';
    if ($place !== '') {
        echo '<strong>' . htmlspecialchars($place) . '</strong>';
    }
    if ($lat !== null && $long !== null) {
        $map = 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string) $lat) . '&mlon=' . rawurlencode((string) $long) . '#map=16/' . rawurlencode((string) $lat) . '/' . rawurlencode((string) $long);
        echo ($place !== '' ? ' (' : '') . '<a href="' . htmlspecialchars($map) . '">' . htmlspecialchars($lat . ', ' . $long) . '</a>' . ($place !== '' ? ')' : '');
    }
    ?></p>
    <?php
}

if (!empty($object->body)) {
    echo $this->autop($this->parseURLs($this->parseHashtags($this->sanitize_html((string) $object->body))));
}
