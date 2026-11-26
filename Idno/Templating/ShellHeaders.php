<?php

declare(strict_types=1);

namespace Idno\Templating;

use Idno\Core\UrlValidator;

/**
 * The HTTP headers each template type's page carries, as data: the one place they are declared. No template sends a header.
 */
final class ShellHeaders
{
    private const CORS_CREDENTIALS = [
        ['Access-Control-Allow-Origin', '*'],
        ['Access-Control-Allow-Credentials', 'true'],
        ['Access-Control-Allow-Headers', 'Authorization, Content-Type'],
    ];

    /**
     * The headers of a page of a template type, in the order they are sent, as [name, value] pairs. Another type has none.
     *
     * @param string      $canonical            the address a Markdown document is a form of; used for a 200 only, and only
     *                                          when it is an http(s) address with no `<`, `>` or white space
     * @param bool        $settingsShellAsJsonp the page is a settings page asked for as JSONP, which is drawn as HTML
     * @return list<array{0: string, 1: string}>
     */
    public static function for(string $type, int $status = 200, ?string $canonical = null, bool $settingsShellAsJsonp = false): array
    {
        switch (strtolower($type)) {
            case 'default':
                return [['Content-type', 'text/html'], ['Access-Control-Allow-Origin', '*']];
            case 'json':
                return [['Content-type', 'application/json'], ...self::CORS_CREDENTIALS];
            case 'jsonp':
                if ($settingsShellAsJsonp) {
                    return [['Content-Type', 'text/html; charset=utf-8'], ['X-Content-Type-Options', 'nosniff']];
                }

                return [['Content-type', 'application/x-javascript; charset=UTF-8'], ...self::CORS_CREDENTIALS];
            case 'rss':
                return [['Content-type', 'application/rss+xml']];
            case 'kml':
                return [['Content-type', 'application/vnd.google-earth.kml+xml']];
            case 'jsonfeed':
                return [['Content-type', 'application/json'], ['Access-Control-Allow-Origin', '*']];
            case 'activitypub':
                // A page that is refused is JSON that says why, not an ActivityPub object
                if ($status >= 400) {
                    return [['Content-Type', 'application/json']];
                }

                return [['Content-Type', 'application/activity+json'], ['Access-Control-Allow-Origin', '*']];
            case 'markdown':
                // (the document is text, whatever a browser might make of its first bytes)
                $headers = [
                    ['Content-Type', 'text/markdown; charset=utf-8'],
                    ['X-Content-Type-Options', 'nosniff'],
                    ['Access-Control-Allow-Origin', '*'],
                ];
                if ($status === 200) {
                    $address = UrlValidator::httpUrl($canonical);
                    if ($address !== '' && preg_match('/[<>\s]/', $address) !== 1) {
                        $headers[] = ['Link', '<' . $address . '>; rel="canonical"'];
                    }
                }

                return $headers;
        }

        return [];
    }

    /** Whether a header of this name replaces one already sent (every one but `Link`, which is added). */
    public static function replaces(string $name): bool
    {
        return strtolower($name) !== 'link';
    }
}
