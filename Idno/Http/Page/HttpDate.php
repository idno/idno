<?php

declare(strict_types=1);

namespace Idno\Http\Page;

/**
 * The date of an HTTP header (RFC 9110 section 5.6.7). Static and pure: not a service.
 */
final class HttpDate
{
    /**
     * The preferred form, IMF-fixdate (`Tue, 14 Nov 2023 22:13:20 GMT`), as the site has always written it.
     */
    public static function format(int $timestamp): string
    {
        return gmdate('D, d M Y H:i:s T', $timestamp);
    }

    /**
     * The instant a header's date names, or null when the value is not an HTTP-date: it must be exactly one of the three forms a recipient
     * accepts (IMF-fixdate, the obsolete RFC 850 form and asctime), in GMT, with a day name that is the date's. Never `strtotime()`, which reads
     * `tomorrow` and a bare number.
     */
    public static function parse(string $value): ?int
    {
        $forms = [
            // the form, then what it is written back as (the value must equal it: a day that does not exist, a wrong day name and white space the form does not have are refused)
            ['D, d M Y H:i:s \G\M\T', static fn (int $t): string => gmdate('D, d M Y H:i:s \G\M\T', $t)],
            ['l, d-M-y H:i:s \G\M\T', static fn (int $t): string => gmdate('l, d-M-y H:i:s \G\M\T', $t)],
            ['D M j H:i:s Y', static fn (int $t): string => gmdate('D M ', $t) . str_pad(gmdate('j', $t), 2, ' ', STR_PAD_LEFT) . gmdate(' H:i:s Y', $t)],
        ];
        foreach ($forms as [$format, $write]) {
            // (the day of asctime is padded with a space, which `j` does not read: it is read as one or two digits after the single space the form has)
            $candidate = $format === 'D M j H:i:s Y' ? (string) preg_replace('/\A(\w{3} \w{3}) ( \d)/', '$1$2', $value) : $value;
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $candidate, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }
            $timestamp = $date->getTimestamp();
            if ($write($timestamp) === $value) {
                return $timestamp;
            }
        }

        return null;
    }
}
