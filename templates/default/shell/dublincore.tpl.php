<?php

    $currentPage = \Idno\Core\Idno::site()->currentPage();

?>
<!-- Dublin Core -->
<link rel="schema.DC" href="http://purl.org/dc/elements/1.1/">
<meta name="DC.title" content="<?php echo htmlspecialchars($vars['title']) ?>">
<meta name="DC.description" content="<?php echo htmlspecialchars($vars['description']) ?>"><?php

if ($currentPage->isPermalink()) {
    /* @var \Idno\Common\Entity $object */
    if (!empty($vars['object']) && $vars['object'] instanceof \Idno\Common\Entity && $currentPage->isPermalinkFor($vars['object'])) {
        $object = $vars['object'];
        if ($creator = $object->getOwner()) {
            ?>
                <meta name="DC.creator" content="<?php echo htmlentities($creator->getTitle()) ?>"><?php
        }
        if ($created = $object->created) {
            ?>
                <meta name="DC.date" content="<?php echo date('c', $created) ?>"><?php
        }
        if ($url = $object->getDisplayURL()) {
            ?>
                <meta name="DC.identifier" content="<?php echo htmlspecialchars($url) ?>"><?php
        }

    }
}

