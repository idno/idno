<?php

    /*
     * What the row of the Users page says about an account that is closed (it is being
     * deleted, or its deletion stopped), for both looks: that it is, what could not be removed
     * if anything, and the control that carries on. "Continue deleting" is the delete action
     * again, which goes on where the job stopped.
     */

    $user = $vars['user'] ?? null;
    if (!($user instanceof \Idno\Entities\User) || !$user->isClosed()) {
        return;
    }
    $lang = \Idno\Core\Idno::site()->language();
    $left = is_array($user->account_removal) ? $user->account_removal : [];
    $busy = (int) $user->account_removing_until > time();
    ?>
<span class="idno-badge idno-badge-warning label label-warning"><?php echo $lang->_('Being deleted') ?></span><br>
<small>
    <?php
    echo $lang->esc_('Closed %s. Nobody can sign in to it, and its posts are hidden.', [date('j M Y, H:i', (int) $user->account_closed)]);
    if ($busy) {
        echo ' ' . $lang->_('Its content is being removed now.');
    } else if (!empty($left['reason'])) {
        if ($left['reason'] === 'refused' && !empty($left['classes']) && is_array($left['classes'])) {
            $kinds = [];
            foreach ($left['classes'] as $class => $count) {
                $kinds[] = (int) $count . ' × ' . $class;
            }
            echo ' ' . $lang->esc_('The last run could not remove: %s. A plugin may have refused; the log says more.', [implode(', ', $kinds)]);
        } else {
            echo ' ' . $lang->_('The last run could not read everything the account owns; the log says more.');
        }
    }
    ?>
</small><br>
<?php
if (!$busy && $user->getUUID() != \Idno\Core\Idno::site()->session()->currentUserUUID()) {
    echo \Idno\Core\Idno::site()->actions()->createLink(
        \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users',
        $lang->_('Continue deleting'),
        ['user' => $user->getUUID(), 'action' => 'delete'],
        ['class' => 'idno-user-action-link idno-user-action-danger', 'confirm' => true, 'confirm-text' => $lang->_('Carry on deleting this account and all its content?')]
    );
}
