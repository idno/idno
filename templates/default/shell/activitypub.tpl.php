<?php

/*
 * The ActivityPub form of this page, for a page that is the permalink of a member (the actor)
 * or of a public post (the post's id, which answers with the post as ActivityPub).
 *
 * This reads the entity the page is the permalink of, never $vars['user'] or $vars['object']:
 * template variables stay set between draws, so they may be those of something drawn earlier.
 */

$currentPage = \Idno\Core\Idno::site()->currentPage();
$entity = ($currentPage && $currentPage->isPermalink()) ? $currentPage->getEntity() : null;
$alt_link = '';

if ($entity instanceof \Idno\Entities\User) {
    $alt_link = $entity->getActivityPubActorID();
} elseif ($entity instanceof \Idno\Common\Entity && $entity->isPublic()) {
    $alt_link = $entity->getUUID();
}

$alt_link = \Idno\Core\UrlValidator::httpUrl($alt_link);

if (!empty($alt_link)) {
    ?>
    <link rel="alternate" type="application/activity+json" href="<?php echo htmlspecialchars($alt_link) ?>" />
    <?php
}
