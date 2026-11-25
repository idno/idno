<?php

    /* Tells the administrator who started an import that it is done. No variables. */

    use Idno\Core\Email;

    $lang = \Idno\Core\Idno::site()->language();
    $site = Email::siteName();

    Email::describe(
        [
        'subject' => $lang->_('Your import into %s is complete', [$site]),
        'preheader' => $lang->_('The posts from your file are on the site.'),
        'reason' => $lang->_('You got this email because you started an import into %s.', [$site]),
        ]
    );

    echo Email::part($t, 'heading', ['text' => $lang->_('Your import is complete')]);
    echo Email::part($t, 'paragraph', ['format' => $lang->get('The file you uploaded has been turned into posts on %s.'), 'values' => [$site]]);
    echo Email::part($t, 'button', ['label' => $lang->_('Visit your site'), 'url' => Email::siteUrl()]);
