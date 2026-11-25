<?php

    /* The test mail an administrator sends from the email settings. No variables. */

    use Idno\Core\Email;

    $lang = \Idno\Core\Idno::site()->language();
    $site = Email::siteName();

    Email::describe(
        [
        'subject' => $lang->_('Test email from %s', [$site]),
        'preheader' => $lang->_('If you can read this, the email settings work.'),
        'reason' => $lang->_('You got this email because an administrator of %s sent a test to this address.', [$site]),
        ]
    );

    echo Email::part($t, 'heading', ['text' => $lang->_('The email settings work')]);
    echo Email::part($t, 'paragraph', ['format' => $lang->get('If you can read this, %s can send email.'), 'values' => [$site]]);
    echo Email::part($t, 'paragraph', ['text' => $lang->_('If it arrived in the spam folder, check the DNS records your mail service asks for (SPF, DKIM and DMARC).')]);
    echo Email::part($t, 'button', ['label' => $lang->_('Back to the email settings'), 'url' => Email::siteUrl('admin/email/')]);
