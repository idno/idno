<?php

    $language = \Idno\Core\Idno::site()->language();
    $url = \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/moderation/settings';

    echo $this->__(['tab' => 'settings'])->draw('moderation/admin/header');

?>
<div class="mod-wide">
    <form method="post" action="<?php echo htmlspecialchars($url, ENT_QUOTES) ?>" class="mod-card mod-settings">
        <label class="mod-label" for="moderation_contact_email"><?php echo $language->_('Contact address for appeals') ?></label>
        <p class="mod-muted" id="mod-contact-help"><?php echo $language->_('A member whose post was removed, or who was banned, is told they can write to this address.') ?>
            <?php if ((string) $vars['contact'] === '' && (string) $vars['effective_contact'] !== '') echo htmlspecialchars($language->_('Left empty, it is %s.', [$vars['effective_contact']])); ?>
            <?php if ((string) $vars['effective_contact'] === '') echo '<strong>' . $language->_('No address is set, and the site has no sending address either: the mails will not name one.') . '</strong>'; ?></p>
        <input class="mod-input" type="email" id="moderation_contact_email" name="moderation_contact_email" aria-describedby="mod-contact-help" value="<?php echo htmlspecialchars((string) $vars['contact'], ENT_QUOTES) ?>" placeholder="moderators@example.com">

        <label class="mod-check"><input type="checkbox" name="moderation_notify_admins" value="1" <?php if (!empty($vars['notify'])) echo 'checked'; ?>> <span><strong><?php echo $language->_('Email administrators about each new report') ?></strong><small><?php echo $language->_('Reports about child safety, threats or self-harm are marked urgent in the subject.') ?></small></span></label>

        <?php echo \Idno\Core\Idno::site()->actions()->signForm($url) ?>
        <button type="submit" class="mod-btn mod-btn-primary"><?php echo $language->_('Save settings') ?></button>
    </form>

    <div class="mod-card mod-legal">
        <h3 class="mod-card-title"><?php echo $language->_('What this cannot do for you') ?></h3>
        <p><?php echo $language->_('Some reports carry legal duties for whoever runs the site: above all anything involving the safety of a child, and in many places credible threats, copyright notices and requests about personal data. Removing a post or banning a member here does not report anything to anyone, and deleting content permanently can destroy evidence you are required to keep. If you are unsure, take the content down (which keeps it) and get advice before deleting.') ?></p>
    </div>
</div>
