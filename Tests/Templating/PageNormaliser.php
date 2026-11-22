<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * Makes a rendered page comparable between runs, databases and (later) template engines: it replaces the
 * values that are random or made from the site's address by nature, and the white space between tags, and
 * changes nothing else. Each replacement is listed, with an example, in Tests\Unit\Templating\PageNormaliserTest.
 */
final class PageNormaliser
{
    /**
     * @param bool $html whether the body is an HTML document: only then is the white space between tags collapsed
     */
    public static function normalise(string $body, string $baseUrl, bool $html = false): string
    {
        $origin = rtrim($baseUrl, '/');
        $host = (string) parse_url($baseUrl, PHP_URL_HOST) . ':' . (string) parse_url($baseUrl, PHP_URL_PORT);
        // The site's address, as it is written in HTML and text, in JSON (`\/`) and percent-encoded in a query.
        foreach ([$origin, str_replace('/', '\\/', $origin), rawurlencode($origin)] as $variant) {
            $body = str_replace($variant, '{site}', $body);
        }
        $body = str_replace($host, '{site-host}', $body);
        // The port written on its own, as the number the page's script is given (`"host":"127.0.0.1","port":8089`).
        $body = preg_replace('/("host":"' . preg_quote((string) parse_url($baseUrl, PHP_URL_HOST), '/') . '","port":)' . preg_quote((string) parse_url($baseUrl, PHP_URL_PORT), '/') . '\b/', '$1{site-port}', $body) ?? $body;

        $body = preg_replace('/(name="__bTs" value=")\d{9,12}"/', '$1{token-time}"', $body) ?? $body;
        $body = preg_replace('/(name="__bTk" value=")[0-9a-f]{64}"/', '$1{token}"', $body) ?? $body;
        // The same token in the JSON of a page (`"csrf": [{"tid": ..., "time": 1795368191, "token": "..."}]`).
        $body = preg_replace('/("time": )\d{9,12}(,\s+"token": ")[0-9a-f]{64}"/', '$1{token-time}$2{token}"', $body) ?? $body;
        $body = preg_replace('/\btid[0-9a-f]{32}\b/', 'tid{id}', $body) ?? $body;

        // The ids forms/link gives a link and its hidden form (uniqid(), `f` and 13 hex digits): numbered by first appearance,
        // so that the pairing of a link with its form is kept.
        if (preg_match_all('/data-form-id="(f[0-9a-f]{13})"/', $body, $found) > 0) {
            $numbers = [];
            foreach ($found[1] as $id) {
                $numbers[$id] ??= '{form-' . (count($numbers) + 1) . '}';
            }
            $body = strtr($body, $numbers);
        }

        // The id of the access control of a form (md5 of a random number), numbered by first appearance.
        if (preg_match_all('/access-control-id-acl-([0-9a-f]{32})/', $body, $found) > 0) {
            $numbers = [];
            foreach ($found[1] as $id) {
                $numbers['acl-' . $id] ??= 'acl-{id-' . (count($numbers) + 1) . '}';
            }
            $body = strtr($body, $numbers);
        }

        // The address of an annotation in the link that deletes it, base64 encoded: it holds the site's address with its port.
        $body = preg_replace_callback('/([?&]permalink=)([A-Za-z0-9+\/]{16,}={0,2})/', static function (array $match) use ($origin): string {
            $decoded = base64_decode($match[2], true);

            return $decoded !== false && str_starts_with($decoded, $origin) ? $match[1] . '{base64:' . '{site}' . substr($decoded, strlen($origin)) . '}' : $match[0];
        }, $body) ?? $body;

        // The cache-buster of the built script and style sheet: the modification time of the file (`js/1794616379/idno.min.js`), which is
        // the time the checkout was made.
        $body = preg_replace('#\b(js|css)/\d{9,10}/#', '$1/{built}/', $body) ?? $body;

        // The code in the link of a password reset mail (32 random hex digits).
        $body = preg_replace('/(account\/password\/reset\/\?email=[^&"\s]*&code=)[0-9a-f]{32}/', '$1{reset-code}', $body) ?? $body;

        // The number the media template draws from rand() into the ids of its player (`player7`, `waveform7`, `wavesurfer7`):
        // numbered by first appearance.
        if (preg_match_all('/\bid="player(\d{1,4})"/', $body, $found) > 0) {
            $numbers = [];
            foreach ($found[1] as $number) {
                $numbers[$number] ??= (string) (count($numbers) + 1);
            }
            $body = preg_replace_callback('/\b(player|waveform|wavesurfer)(\d{1,4})\b/', static fn (array $m): string => isset($numbers[$m[2]]) ? $m[1] . '{' . $numbers[$m[2]] . '}' : $m[0], $body) ?? $body;
        }

        // The ids of the rich text editor and the long text field (`body` or `editor-` and a number from rand(): `body6640`,
        // `editor-7555-editor`): numbered by first appearance, every use of the id with it.
        if (preg_match_all('/\bid="(?:body|editor-)(\d{1,4})"/', $body, $found) > 0) {
            $numbers = [];
            foreach ($found[1] as $number) {
                $numbers[$number] ??= (string) (count($numbers) + 1);
            }
            $body = preg_replace_callback('/\b(body|editor-)(\d{1,4})\b/', static fn (array $m): string => isset($numbers[$m[2]]) ? $m[1] . '{' . $numbers[$m[2]] . '}' : $m[0], $body) ?? $body;
        }

        // The ids made from md5(rand()): an image field (`photo-<hex>`, `photo-<hex>_preview`), the spinner of an edit form, a
        // static pages category: numbered by first appearance.
        if (preg_match_all('/\b(?:photo-|spinner_|category-name-|edit-category-)([0-9a-f]{32})(?![0-9a-f])/', $body, $found) > 0) {
            $numbers = [];
            foreach ($found[1] as $hex) {
                $numbers[$hex] ??= '{' . (count($numbers) + 1) . '}';
            }
            $body = preg_replace_callback('/\b(photo-|spinner_|category-name-|edit-category-)([0-9a-f]{32})(?![0-9a-f])/', static fn (array $m): string => $m[1] . $numbers[$m[2]], $body) ?? $body;
        }

        if (!$html) {
            return $body;
        }

        // White space between a tag and the next is one line break, except inside <pre> and <textarea>: how a template
        // is indented is not what it renders, but the text of a pre-formatted block is.
        return preg_replace_callback('#(<(pre|textarea)\b.*?</\2>)(\s+(?=<))?|>\s+(?=<)#is', static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[1] . (($m[3] ?? '') !== '' ? "\n" : '') : ">\n", $body) ?? $body;
    }
}
