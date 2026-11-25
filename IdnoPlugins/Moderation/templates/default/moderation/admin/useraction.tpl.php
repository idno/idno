<?php

    /* A link from a row of the admin Users page to the member's moderation record */

    $user = $vars['user'] ?? null;

if ($user instanceof \Idno\Entities\User && !($user instanceof \Idno\Entities\RemoteUser)) {
    ?>
    <a class="mod-useraction" href="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/moderation/member/' . ((string) $user->getID()), ENT_QUOTES) ?>"><?php echo \Idno\Core\Idno::site()->language()->_('Moderation') ?></a>
    <?php if ($user->isBanned()) { ?>
<span class="mod-pill mod-pill-urgent"><?php echo \Idno\Core\Idno::site()->language()->_('Banned') ?></span><?php
    } ?>
    <br>
    <?php
}
