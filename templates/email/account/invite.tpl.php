<?php

    /*
     * The invitation mail: 'link' (made by Invitation::getLink()), 'email', 'inviter',
     * 'is_admin', 'expires'. Written with the parts of templates/email/parts, which escape
     * everything a person typed (the inviter's name, the site's name and description) and
     * draw the mail as HTML and as text (Email::setBodyFromTemplate()).
     */

    use Idno\Core\Email;

    $lang = \Idno\Core\Idno::site()->language();
    $site = Email::siteName();
    $inviter = Email::oneLine($vars['inviter'] ?? '');
    $admin = !empty($vars['is_admin']);
    $until = date('j F Y', (int) $vars['expires']);
    // What the site says it is, unless that is still the text every new site starts with
    $description = Email::oneLine(\Idno\Core\Idno::site()->config()->description);
if ($description === 'A social website powered by Idno') {
    $description = '';
}

    Email::describe(
        [
        'subject' => $lang->_("You've been invited to join %s", [$site]),
        'preheader' => $inviter !== ''
            ? $lang->_('%s invited you. The invitation works until %s.', [$inviter, $until])
            : $lang->_('Create your account. The invitation works until %s.', [$until]),
        'reason' => $inviter !== ''
            ? $lang->_('You got this email because %s invited this address to join %s.', [$inviter, $site])
            : $lang->_('You got this email because an administrator invited this address to join %s.', [$site]),
        ]
    );

    echo Email::part($t, 'heading', ['text' => $lang->_("You're invited to join %s", [$site])]);

    if ($inviter !== '') {
        echo Email::part(
            $t, 'paragraph', $admin
            ? ['format' => $lang->get('%s has invited you to join %s as an administrator.'), 'values' => [$inviter, $site]]
            : ['format' => $lang->get('%s has invited you to join %s.'), 'values' => [$inviter, $site]]
        );
    } else {
        echo Email::part(
            $t, 'paragraph', $admin
            ? ['format' => $lang->get('You have been invited to join %s as an administrator.'), 'values' => [$site]]
            : ['format' => $lang->get('You have been invited to join %s.'), 'values' => [$site]]
        );
    }
    if ($description !== '') {
        echo Email::part($t, 'quote', ['text' => $description]);
    }
    if ($admin) {
        echo Email::part($t, 'paragraph', ['text' => $lang->_("As an administrator you will be able to change the site's settings, manage its members and invite others.")]);
    }
    echo Email::part($t, 'paragraph', ['text' => $lang->_('To accept, create your account:')]);
    echo Email::part($t, 'button', ['label' => $lang->_('Create your account'), 'url' => (string) $vars['link']]);
    echo Email::part(
        $t, 'rows', ['rows' => [
        $lang->_('Invitation for') => (string) $vars['email'],
        $lang->_('Role') => $admin ? $lang->_('Administrator') : $lang->_('Member'),
        $lang->_('Works until') => $until,
        ]]
    );
    echo Email::part($t, 'paragraph', ['muted' => true, 'text' => $lang->_('This invitation is for %s only and works until %s.', [Email::oneLine($vars['email']), $until]) . ' ' . $lang->_("If you weren't expecting it, you can ignore this email.")]);
