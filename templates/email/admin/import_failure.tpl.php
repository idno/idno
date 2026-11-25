<?php

    /* Tells the administrator who started an import that it did not work. No variables. */

    use Idno\Core\Email;

    $lang = \Idno\Core\Idno::site()->language();
    $site = Email::siteName();

    Email::describe(
        [
        'subject' => $lang->_('Your import into %s did not finish', [$site]),
        'preheader' => $lang->_('Some or all of the file could not be imported.'),
        'reason' => $lang->_('You got this email because you started an import into %s.', [$site]),
        ]
    );

    echo Email::part($t, 'heading', ['text' => $lang->_('Your import did not finish')]);
    echo Email::part($t, 'paragraph', ['format' => $lang->get('Some or all of the file you uploaded to %s could not be imported.'), 'values' => [$site]]);
    echo Email::part($t, 'paragraph', ['text' => $lang->_("You can try the import again. If it fails again, the site's error log may say why.")]);
    echo Email::part($t, 'button', ['label' => $lang->_('Try the import again'), 'url' => Email::siteUrl('admin/import/')]);
