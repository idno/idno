<?php

    /*
     * The invitations that have not been used, for both looks: who, as what, by whom, when,
     * until when, with the link to copy, and the controls to send one again or withdraw it.
     * Everything in an invitation was typed by somebody, so everything is escaped.
     */

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    $invitations = $vars['invitations'] ?? [];
    if (empty($invitations)) {
        return;
    }
    ?>
<div class="invite-list">
    <?php foreach ($invitations as $invitation) {
        $email = htmlspecialchars((string) $invitation->email);
        $expired = $invitation->isExpired();
        $days = (int) ceil(($invitation->getExpiry() - time()) / 86400);
        ?>
        <div class="idno-invite-row">
            <div>
                <div class="idno-invite-address">
                    <?php echo $email ?>
                    <?php if ($invitation->isForAdministrator()) { ?>
                        <span class="idno-badge idno-badge-strong label label-primary"><?php echo $lang->_('Administrator') ?></span>
                    <?php } else { ?>
                        <span class="idno-badge label label-default"><?php echo $lang->_('Member') ?></span>
                    <?php } ?>
                    <?php if ($expired) { ?>
                        <span class="idno-badge idno-badge-warning label label-warning"><?php echo $lang->_('Expired') ?></span>
                    <?php } ?>
                </div>
                <div class="idno-invite-meta">
                    <?php
                    if ($invitation->getInviterName() !== '') {
                        echo $lang->esc_('Invited by %s', [$invitation->getInviterName()]) . ' &middot; ';
                    }
                    if (!empty($invitation->send_failed)) {
                        echo '<strong>' . $lang->_('email not sent') . '</strong>';
                    } else if (!empty($invitation->last_sent)) {
                        echo $lang->esc_('email sent %s', [date('j M Y, H:i', (int) $invitation->last_sent)]);
                    } else {
                        echo $lang->esc_('made %s', [date('j M Y', (int) $invitation->created)]);
                    }
                    echo ' &middot; ';
                    if ($expired) {
                        echo $lang->esc_('expired %s', [date('j M Y', $invitation->getExpiry())]);
                    } else {
                        echo $lang->esc_('expires %s (%d days)', [date('j M Y', $invitation->getExpiry()), max(1, $days)]);
                    }
                    ?>
                </div>
            </div>
            <div class="idno-invite-actions">
                <?php
                    echo \Idno\Core\Idno::site()->actions()->createLink(
                        $config->getDisplayURL() . 'admin/users',
                        $lang->_('Send again'),
                        ['invitation_id' => $invitation->getID(), 'action' => 'resend_invitation'],
                        ['class' => 'idno-btn idno-btn-sm btn btn-default btn-sm', 'confirm' => true, 'confirm-text' => $lang->_('Send the invitation to %s again? Its link will work for another %d days.', [(string) $invitation->email, \Idno\Entities\Invitation::lifetimeDays()])]
                    );
                    echo \Idno\Core\Idno::site()->actions()->createLink(
                        $config->getDisplayURL() . 'admin/users',
                        $lang->_('Withdraw'),
                        ['invitation_id' => $invitation->getID(), 'action' => 'remove_invitation'],
                        ['class' => 'idno-btn idno-btn-sm idno-btn-danger btn btn-default btn-sm', 'confirm' => true, 'confirm-text' => $lang->_('Withdraw the invitation for %s? Its link will stop working.', [(string) $invitation->email])]
                    );
                    ?>
            </div>
            <?php if (!$expired) { ?>
                <div class="idno-invite-link">
                    <input type="text" readonly class="idno-input form-control invite-link-field"
                           aria-label="<?php echo $lang->esc_('Invitation link for %s', [(string) $invitation->email]) ?>"
                           id="invite-link-<?php echo htmlspecialchars((string) $invitation->getID()) ?>"
                           value="<?php echo htmlspecialchars($invitation->getLink()) ?>">
                    <button type="button" class="idno-btn idno-btn-sm btn btn-default btn-sm invite-link-copy" hidden
                            data-copied="<?php echo $lang->_('Copied') ?>"><?php echo $lang->_('Copy link') ?></button>
                </div>
            <?php } ?>
        </div>
    <?php } ?>
</div>
<script>
    (function () {
        var buttons = document.querySelectorAll('.invite-link-copy');
        for (var i = 0; i < buttons.length; i++) {
            if (!navigator.clipboard) {
                continue;
            }
            buttons[i].hidden = false;
            buttons[i].addEventListener('click', function () {
                var button = this;
                var field = button.parentNode.querySelector('.invite-link-field');
                navigator.clipboard.writeText(field.value).then(function () {
                    var label = button.textContent;
                    button.textContent = button.getAttribute('data-copied');
                    setTimeout(function () {
                        button.textContent = label;
                    }, 2000);
                });
            });
        }
        var fields = document.querySelectorAll('.invite-link-field');
        for (var j = 0; j < fields.length; j++) {
            fields[j].addEventListener('focus', function () {
                this.select();
            });
        }
    })();
</script>
