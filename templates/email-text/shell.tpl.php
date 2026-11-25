<?php

    /*
     * The end of every plain-text mail: why the reader got it, and which site sent it.
     * 'body' (the mail), 'reason'. Plain text: nothing is escaped.
     */

    use Idno\Core\Email;

    echo rtrim((string) $vars['body']) . "\n\n-- \n";
    $reason = Email::oneLine($vars['reason'] ?? '');
if ($reason !== '') {
    echo Email::wrapText($reason) . "\n";
}
    echo Email::siteName() . "\n" . Email::siteUrl() . "\n";
