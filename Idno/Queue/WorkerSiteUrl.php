<?php

declare(strict_types=1);

namespace Idno\Queue;

/**
 * The queue worker has no request to tell it the site's address, and the address (with its scheme)
 * ends up in what it signs and sends to other servers (ActivityPub actor and key ids, object ids). The
 * worker therefore never works it out: the boot configuration must hold an explicit, absolute `url`.
 * A pure check over that value.
 */
final class WorkerSiteUrl
{
    /**
     * Null when the value is an absolute http or https URL with a host; otherwise the message to show.
     */
    public static function problem(mixed $url): ?string
    {
        if (is_string($url)) {
            $parts = parse_url(trim($url));
            if (is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) && ($parts['host'] ?? '') !== '') {
                return null;
            }
        }

        return 'The event queue worker needs the site\'s address, and the site\'s configuration has none (or not an absolute one). '
            . 'Add url = "https://example.com/" to configuration/config.ini, or set IDNO_URL=https://example.com/ in the worker\'s environment. '
            . 'It must be the address visitors use, scheme (http or https) included: what the worker signs and sends to other servers names it.';
    }
}
