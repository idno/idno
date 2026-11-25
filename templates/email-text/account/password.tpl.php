<?php

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    $site = trim(preg_replace('/\s+/u', ' ', (string) $config->title) ?? '');

    $heading = $lang->_('Reset your password');
    echo $heading . "\n" . str_repeat('=', mb_strlen($heading)) . "\n\n";
    echo $lang->_('Somebody, probably you, asked to reset the password of your account on %s.', [$site]) . "\n\n";
    echo $lang->_('To choose a new password, open this address within three hours:') . "\n\n";
    echo $config->getDisplayURL() . 'account/password/reset/?email=' . urlencode((string) $vars['email']) . '&code=' . urlencode((string) $vars['code']) . "\n\n";
    echo $lang->_("If you didn't ask for this, you can ignore this email: your password stays as it is.") . "\n";
