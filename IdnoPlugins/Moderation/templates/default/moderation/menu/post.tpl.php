<?php

    /*
     * "Report" items in the "More" menu of a post, for a signed-in member who is not its
     * author. Administrators also get a way into the queue when the post has an open report.
     */

    $object = $vars['object'] ?? null;
    $session = \Idno\Core\Idno::site()->session();

if ($object instanceof \Idno\Common\Entity && $session->isLoggedOn() && \IdnoPlugins\Moderation\Moderation::isPost($object) && !$object->isModerated()) {
    $language = \Idno\Core\Idno::site()->language();
    $base = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'moderation/report?';
    $owner = $object->getOwner();
    $mine = $object->getOwnerID() == $session->currentUserUUID();

    if ($session->isAdmin()) {
        $case = \IdnoPlugins\Moderation\Moderation::openCaseFor(\IdnoPlugins\Moderation\Entities\ModerationCase::keyFor('post', $object->getUUID()));
        if ($case && \IdnoPlugins\Moderation\Moderation::canHandle($session->currentUser(), $case)) {
            ?>
            <div class="idno-more-separator" role="separator"></div>
            <a class="idno-more-item" role="menuitem" href="<?php echo htmlspecialchars($case->getAdminURL(), ENT_QUOTES) ?>"><?php echo $language->_('View in moderation queue') ?></a>
            <?php
        }
    }
    if (!$mine) {
        ?>
        <div class="idno-more-separator" role="separator"></div>
        <a class="idno-more-item idno-more-item-danger" role="menuitem" data-mod-report
           href="<?php echo htmlspecialchars($base . http_build_query(['type' => 'post', 'id' => ((string) $object->getID())]), ENT_QUOTES) ?>"><?php echo $language->_('Report post') ?></a>
        <?php if ($owner instanceof \Idno\Entities\User && !($owner instanceof \Idno\Entities\RemoteUser)) { ?>
        <a class="idno-more-item idno-more-item-danger" role="menuitem" data-mod-report
           href="<?php echo htmlspecialchars($base . http_build_query(['type' => 'profile', 'id' => ((string) $owner->getID())]), ENT_QUOTES) ?>"><?php echo htmlspecialchars($language->_('Report %s', [strip_tags((string) $owner->getTitle())])) ?></a>
        <?php } ?>
        <?php
    }
}
