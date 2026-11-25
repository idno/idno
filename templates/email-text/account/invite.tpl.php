<?php

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    // Plain text: one line per value, so nothing typed can look like a line of the mail's own
    $line = function ($text) {
        return trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
    };
    $site = $line($config->title);
    $inviter = $line($vars['inviter'] ?? '');
    // What the site says it is, unless that is still the text every new site starts with
    $description = $line($config->description);
    if ($description === 'A social website powered by Idno') {
        $description = '';
    }

    $heading = $lang->_("You're invited to join %s", [$site]);
    echo $heading . "\n" . str_repeat('=', mb_strlen($heading)) . "\n\n";

if ($inviter !== '') {
    if (!empty($vars['is_admin'])) {
        echo $lang->_('%s has invited you to join %s as an administrator.', [$inviter, $site]);
    } else {
        echo $lang->_('%s has invited you to join %s.', [$inviter, $site]);
    }
} else {
    if (!empty($vars['is_admin'])) {
        echo $lang->_('You have been invited to join %s as an administrator.', [$site]);
    } else {
        echo $lang->_('You have been invited to join %s.', [$site]);
    }
}
    echo "\n\n";
if ($description !== '') {
    echo $description . "\n\n";
}
if (!empty($vars['is_admin'])) {
    echo $lang->_('As an administrator you will be able to change the site\'s settings, manage its members and invite others.') . "\n\n";
}
    echo $lang->_('To accept, create your account:') . "\n\n";
    echo $vars['link'] . "\n\n";
    echo $lang->_('This invitation is for %s only and works until %s.', [$line($vars['email']), date('j F Y', (int) $vars['expires'])]) . ' ';
    echo $lang->_("If you weren't expecting it, you can ignore this email.") . "\n";
