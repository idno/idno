<?php

declare(strict_types=1);

namespace Idno\Http;

/**
 * The one rule for where a redirect taken from a request may go: this site, and nowhere else.
 *
 * A static helper with no dependency on the legacy application (the site's address is given), so any code that
 * redirects to an address a request supplied (the Referer, a forward field) asks it.
 *
 * What it answers, in order:
 * - the trimmed address is empty: the site's address;
 * - it holds a backslash or a control character (browsers treat backslashes as slashes and strip control
 *   characters): the site's address;
 * - it starts with one slash: kept; it starts with two (protocol-relative): the site's address;
 * - otherwise it must be an http or https address whose host is the site's host (compared lower-cased, as parsed, so
 *   `https://site.example.evil.test/` and `https://site.example@evil.test/` are refused) and whose port is the site's
 *   (both none, or the same): kept as given; anything else is the site's address.
 *
 * Two answers that are kept as they were and may be changed on purpose: the scheme is not compared with the site's (an
 * `http://` address of the site's host is kept on an https site), and user information in front of the site's own host
 * (`https://user@site.example/`) is kept.
 */
final class RedirectTarget
{
    public static function sanitize(string $url, string $siteUrl): string
    {
        $url = trim($url);

        if ($url === '') {
            return $siteUrl;
        }

        if (str_contains($url, '\\') || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return $siteUrl;
        }

        if ($url[0] === '/') {
            return (strlen($url) > 1 && $url[1] === '/') ? $siteUrl : $url;
        }

        $target = parse_url($url);
        $site = parse_url($siteUrl);
        if (empty($target['scheme']) || empty($target['host']) || empty($site['host'])) {
            return $siteUrl;
        }
        if (!in_array(strtolower($target['scheme']), ['http', 'https'], true)) {
            return $siteUrl;
        }
        if (strtolower($target['host']) !== strtolower($site['host'])) {
            return $siteUrl;
        }
        if (($target['port'] ?? null) !== ($site['port'] ?? null)) {
            return $siteUrl;
        }

        return $url;
    }
}
