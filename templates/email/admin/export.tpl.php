<?php

    /* Tells the administrator who asked for an export that the archive is ready. No variables. */

    use Idno\Core\Email;

    $lang = \Idno\Core\Idno::site()->language();
    $site = Email::siteName();

    Email::describe(
        [
        'subject' => $lang->_('Your export of %s is ready', [$site]),
        'preheader' => $lang->_('The archive is ready to download.'),
        'reason' => $lang->_('You got this email because you asked for an export of %s.', [$site]),
        ]
    );

    echo Email::part($t, 'heading', ['text' => $lang->_('Your export is ready')]);
    echo Email::part($t, 'paragraph', ['format' => $lang->get('Everything on %s has been gathered into one archive. Sign in as an administrator to download it.'), 'values' => [$site]]);
    echo Email::part($t, 'button', ['label' => $lang->_('Get your export'), 'url' => Email::siteUrl('admin/export/')]);
    echo Email::part($t, 'paragraph', ['muted' => true, 'text' => $lang->_('The archive is a full copy of the site, so keep it somewhere safe.')]);
