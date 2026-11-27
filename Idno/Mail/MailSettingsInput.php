<?php

declare(strict_types=1);

namespace Idno\Mail;

/**
 * What the administrator typed into the email settings form, checked: the values to store, and for each field that is wrong
 * a code (the page turns each into a sentence). Pure: it reads nothing of the site.
 *
 * Only the form fills in the usual port of a security setting when the port is left empty. A site whose stored settings have
 * a host and no port keeps the port MailTransportDsn reads for them (25) until the form is saved.
 */
final class MailSettingsInput
{
    /** The fields of the form that are stored as typed, apart from the password */
    public const FIELDS = ['from_email', 'from_name', 'reply_to_email', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_username'];

    /**
     * The security choices with the port each one usually goes with. 'tls' is STARTTLS (the connection starts in plain text
     * and is then encrypted: what hosted mail services expect on port 587); 'ssl' is TLS from the first byte (port 465). The
     * names are the ones stored in smtp_secure.
     */
    public const SECURITY_PORTS = ['tls' => 587, 'ssl' => 465, '' => 25];

    /** The longest From name */
    public const NAME_LENGTH = 100;

    public const HOST = 'host';
    public const PORT = 'port';
    public const FROM_REQUIRED = 'from-required';
    public const ADDRESS = 'address';
    public const NAME_TOO_LONG = 'name-too-long';

    /**
     * @param array<string, mixed> $input the fields as sent
     * @return array{0: array<string, string>, 1: array<string, string>} the values to store (every field of FIELDS, as text),
     *                                                                   and the code of what is wrong with each field that is wrong
     */
    public static function validate(array $input): array
    {
        $values = [];
        $errors = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = MailDesign::headerText($input[$field] ?? '');
        }
        if (!in_array($values['smtp_secure'], ['tls', 'ssl'], true)) {
            $values['smtp_secure'] = '';
        }

        // A host name or an IP address: no scheme, no path, no port
        $host = $values['smtp_host'];
        if ($host !== '' && !filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $errors['smtp_host'] = self::HOST;
        }

        if ($values['smtp_port'] === '') {
            $values['smtp_port'] = (string) self::SECURITY_PORTS[$values['smtp_secure']];
        }
        if (!ctype_digit($values['smtp_port']) || (int) $values['smtp_port'] < 1 || (int) $values['smtp_port'] > 65535) {
            $errors['smtp_port'] = self::PORT;
        }

        if ($values['from_email'] === '') {
            if ($host !== '') {
                $errors['from_email'] = self::FROM_REQUIRED;
            }
        } elseif (!filter_var($values['from_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['from_email'] = self::ADDRESS;
        }
        if ($values['reply_to_email'] !== '' && !filter_var($values['reply_to_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['reply_to_email'] = self::ADDRESS;
        }
        if (mb_strlen($values['from_name']) > self::NAME_LENGTH) {
            $errors['from_name'] = self::NAME_TOO_LONG;
        }

        return [$values, $errors];
    }
}
