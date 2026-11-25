<?php

    /*
     * The mail to the addresses of the oops_notify setting when the site hits an error
     * (\Idno\Core\Logging::oopsAlert()): 'title' (the kind of error), 'message', 'site',
     * 'user', 'agent', 'qs', 'referrer'. The last three come from the request as they were
     * sent, and the message can repeat text from it; the parts escape them all.
     */

    use Idno\Core\Email;

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    $host = (string) $config->host !== '' ? (string) $config->host : Email::siteName();
    $title = Email::oneLine($vars['title'] ?? '');
    $user = Email::oneLine($vars['user'] ?? '');

    Email::describe(
        [
        'subject' => $lang->_('Error on %s', [$host]) . ($title !== '' ? ': ' . $title : ''),
        'preheader' => mb_substr(Email::oneLine($vars['message'] ?? ''), 0, 140),
        'reason' => $lang->_('You got this email because this address is set to be told about errors on %s (the oops_notify setting).', [Email::siteName()]),
        ]
    );

    echo Email::part($t, 'heading', ['text' => $lang->_('An error occurred on %s', [$host])]);
    if ($title !== '') {
        echo Email::part($t, 'paragraph', ['text' => $title]);
    }
    echo Email::part($t, 'code', ['text' => (string) ($vars['message'] ?? '')]);
    echo Email::part(
        $t, 'rows', ['rows' => [
        $lang->_('Signed-in member') => ($user === '' || $user === 'UNKNOWN') ? $lang->_('Nobody') : $user,
        $lang->_('Browser') => (string) ($vars['agent'] ?? ''),
        $lang->_('Query string') => (string) ($vars['qs'] ?? ''),
        $lang->_('Referrer') => (string) ($vars['referrer'] ?? ''),
        ]]
    );
