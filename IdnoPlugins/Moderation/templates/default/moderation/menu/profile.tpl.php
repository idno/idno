<?php

    /*
     * "Report <name>" in the "More" menu of a profile that is not your own. Administrators
     * get a link to the member's moderation record.
     */

    $user = $vars['user'] ?? null;
    $session = \Idno\Core\Idno::site()->session();

if ($user instanceof \Idno\Entities\User && !($user instanceof \Idno\Entities\RemoteUser) && $session->isLoggedOn() && $user->getUUID() !== $session->currentUserUUID()) {
    $language = \Idno\Core\Idno::site()->language();
    $base = \Idno\Core\Idno::site()->config()->getDisplayURL();
    ?>
    <div class="idno-more-separator" role="separator"></div>
    <?php if ($session->isAdmin()) { ?>
    <a class="idno-more-item" role="menuitem" href="<?php echo htmlspecialchars($base . 'admin/moderation/member/' . ((string) $user->getID()), ENT_QUOTES) ?>"><?php echo $language->_('Moderation record') ?></a>
    <?php } ?>
    <a class="idno-more-item idno-more-item-danger" role="menuitem" data-mod-report
       href="<?php echo htmlspecialchars($base . 'moderation/report?' . http_build_query(['type' => 'profile', 'id' => ((string) $user->getID())]), ENT_QUOTES) ?>"><?php echo htmlspecialchars($language->_('Report %s', [strip_tags((string) $user->getTitle())])) ?></a>
    <?php
}
