<?php

    /*
     * "Report comment" in the "More" menu of a comment, for a signed-in member who did not
     * write it.
     */

    $object = $vars['object'] ?? null;
    $annotation = $vars['annotation'] ?? null;
    $local = (string) ($vars['annotation_permalink'] ?? '');
    $session = \Idno\Core\Idno::site()->session();

if ($object instanceof \Idno\Common\Entity && is_array($annotation) && $local !== '' && $session->isLoggedOn() && !$object->isModerated()) {
    $language = \Idno\Core\Idno::site()->language();
    $user = $session->currentUser();
    $mine = !empty($annotation['owner_url']) && in_array($annotation['owner_url'], [$user->getURL(), $user->getDisplayURL()], true);
    $hash = basename((string) parse_url($local, PHP_URL_PATH));

    if ($session->isAdmin()) {
        $case = \IdnoPlugins\Moderation\Moderation::openCaseFor(\IdnoPlugins\Moderation\Entities\ModerationCase::keyFor('comment', $object->getUUID(), $local));
        if ($case && \IdnoPlugins\Moderation\Moderation::canHandle($user, $case)) {
            ?>
            <div class="idno-more-separator" role="separator"></div>
            <a class="idno-more-item" role="menuitem" href="<?php echo htmlspecialchars($case->getAdminURL(), ENT_QUOTES) ?>"><?php echo $language->_('View in moderation queue') ?></a>
            <?php
        }
    }
    if (!$mine && preg_match('/^[A-Za-z0-9-]+$/', $hash)) {
        ?>
        <div class="idno-more-separator" role="separator"></div>
        <a class="idno-more-item idno-more-item-danger" role="menuitem" data-mod-report
           href="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'moderation/report?' . http_build_query(['type' => 'comment', 'id' => $object->getID(), 'comment' => $hash]), ENT_QUOTES) ?>"><?php echo $language->_('Report comment') ?></a>
        <?php
    }
}
