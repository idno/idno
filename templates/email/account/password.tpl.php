<?php

    /*
     * The password reset mail: 'email', 'code'. Drawn as HTML and as text
     * (Email::setBodyFromTemplate()).
     */

    use Idno\Core\Email;

    $lang = \Idno\Core\Idno::site()->language();
    $site = Email::siteName();
    $link = Email::siteUrl('account/password/reset/') . '?email=' . urlencode((string) $vars['email']) . '&code=' . urlencode((string) $vars['code']);

    Email::describe(
        [
        'subject' => $lang->_('Reset your password for %s', [$site]),
        'preheader' => $lang->_('Choose a new password within three hours.'),
        'reason' => $lang->_('You got this email because somebody asked to reset the password of the account with this address on %s.', [$site]),
        ]
    );

    echo Email::part($t, 'heading', ['text' => $lang->_('Reset your password')]);
    echo Email::part($t, 'paragraph', ['format' => $lang->get('Somebody, probably you, asked to reset the password of your account on %s.'), 'values' => [$site]]);
    echo Email::part($t, 'paragraph', ['text' => $lang->_('To choose a new password, use this link within three hours:')]);
    echo Email::part($t, 'button', ['label' => $lang->_('Choose a new password'), 'url' => $link]);
    echo Email::part($t, 'paragraph', ['muted' => true, 'text' => $lang->_("If you didn't ask for this, you can ignore this email: your password stays as it is.")]);
