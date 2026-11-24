<?php

/*
 * A bookmark, like or repost for the Markdown form: a link to its target, then the note about it.
 */

$object = $vars['object'];
$lang = \Idno\Core\Idno::site()->language();

if (!empty($object->likeof)) {
    $kind = $lang->_('Liked');
} else if (!empty($object->repostof)) {
    $kind = $lang->_('Reposted');
} else {
    $kind = $lang->_('Bookmarked');
}

$target = \Idno\Core\UrlValidator::httpUrl($object->body);
$label = trim(strip_tags((string) (!empty($object->pageTitle) ? $object->pageTitle : $object->body)));

if ($target !== '') {
    ?>
    <p><?php echo htmlspecialchars($kind) ?>: <a href="<?php echo htmlspecialchars($target) ?>"><?php echo htmlspecialchars($label !== '' ? $label : $target) ?></a></p>
    <?php
} else if ($label !== '') {
    ?>
    <p><?php echo htmlspecialchars($kind . ': ' . $label) ?></p>
    <?php
}

if (!empty($object->description)) {
    echo $this->__(['value' => $object->description, 'object' => $object])->draw('forms/output/richtext');
}
