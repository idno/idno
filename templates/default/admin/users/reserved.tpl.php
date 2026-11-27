<?php

    /*
     * The usernames kept back because the account that had them was deleted, for both looks,
     * a page at a time, each with the control that releases it. A username was typed by
     * somebody, so it is escaped.
     */

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    $reserved = $vars['reserved_handles'] ?? [];
    if (empty($reserved['items'])) {
        return;
    }
    ?>
<p>
    <?php echo $lang->_('When an account is deleted its username is kept back, so that nobody who registers it later is taken for the member who had it, here or on other servers. Release a username to let it be registered again.'); ?>
</p>
<div class="invite-list">
    <?php foreach ($reserved['items'] as $handle) { ?>
        <div class="idno-invite-row">
            <div>
                <div class="idno-invite-address"><?php echo htmlspecialchars((string) $handle->display) ?></div>
                <div class="idno-invite-meta"><?php echo $lang->esc_('account deleted %s', [date('j M Y', (int) $handle->created)]) ?></div>
            </div>
            <div class="idno-invite-actions">
                <?php
                    echo \Idno\Core\Idno::site()->actions()->createLink(
                        $config->getDisplayURL() . 'admin/users',
                        $lang->_('Release'),
                        ['handle' => (string) $handle->handle, 'action' => 'release_handle'],
                        ['class' => 'idno-btn idno-btn-small btn btn-default btn-sm', 'confirm' => true, 'confirm-text' => $lang->_('Release this username? Whoever registers it next gets the address the deleted member had.')]
                    );
                ?>
            </div>
        </div>
    <?php } ?>
</div>
<?php
$offset = (int) $reserved['offset'];
$per_page = (int) $reserved['per_page'];
$count = (int) $reserved['count'];
if ($count > $per_page) {
    ?>
    <p>
        <?php if ($offset > 0) { ?>
            <a href="<?php echo htmlspecialchars($config->getDisplayURL() . 'admin/users?reserved_offset=' . max(0, $offset - $per_page)) ?>#reserved-usernames"><?php echo $lang->_('Newer') ?></a>
        <?php } ?>
        <?php if ($offset + $per_page < $count) { ?>
            <a href="<?php echo htmlspecialchars($config->getDisplayURL() . 'admin/users?reserved_offset=' . ($offset + $per_page)) ?>#reserved-usernames"><?php echo $lang->_('Older') ?></a>
        <?php } ?>
    </p>
    <?php
}
