<?php

declare(strict_types=1);

namespace Idno\Templating;

use Idno\Core\UrlValidator;

/**
 * What a post's text links to that a page may show in place of the link: pictures, and players for Internet Archive, BitChute,
 * YouTube, Vimeo and Instagram (embedded content code from Aaron Parecki, slightly modified:
 * http://aaronparecki.com/articles/2013/05/09/1/experimenting-with-auto-embedding-content). Pure: it reads the text and says what
 * it found; the `content/embed` template draws each find. The text is a post's, so a picture's address is checked here (only an
 * http or https address is kept), and the identifiers of a player are what the patterns allow: letters, digits, `-` and `_`.
 */
final class EmbedFinder
{
    /**
     * @return list<array{kind: string, id: string}> in the order the players are drawn: `image` (id: the address),
     *                                                 `archive`, `bitchute`, `youtube` (a video), `youtube-user`, `vimeo`, `instagram` (id: the post)
     */
    public static function find(string $body): array
    {
        $found = [];

        if (preg_match_all('/https?:\/\/([^\s]+\.[^\s\.]+\.(png|jpg|jpeg|gif))/i', $body, $matches) > 0) {
            foreach ($matches[0] as $match) {
                // The match is taken from the text of the post and can hold quotes and tags
                $source = UrlValidator::httpUrl(html_entity_decode($match, ENT_QUOTES, 'UTF-8'));
                if ($source === '') {
                    continue;
                }
                $found[] = ['kind' => 'image', 'id' => $source];
            }
        }
        if (preg_match_all('/archive\.org\/embed\/([a-z0-9\-\_]+)/i', $body, $matches) > 0) {
            foreach ($matches[1] as $id) {
                $found[] = ['kind' => 'archive', 'id' => $id];
            }
        }
        if (preg_match_all('/bitchute\.com\/video\/([a-z0-9\-\_]+)/i', $body, $matches) > 0) {
            foreach ($matches[1] as $id) {
                $found[] = ['kind' => 'bitchute', 'id' => $id];
            }
        }
        if (preg_match_all('/(youtube\.com|youtu\.be)\/watch\?v=([a-z0-9\-\_]+)/i', $body, $matches) > 0) {
            foreach ($matches[2] as $id) {
                $found[] = ['kind' => 'youtube', 'id' => $id];
            }
        } elseif (preg_match_all('/(youtube\.com|youtu\.be)\/c\/([a-z0-9\-\_]+)/i', $body, $matches) > 0
            || preg_match_all('/(youtube\.com|youtu\.be)\/channel\/([a-z0-9\-\_]+)/i', $body, $matches) > 0
        ) {
            // A YouTube channel has no embed that Idno draws.
        } elseif (preg_match_all('/(youtube\.com|youtu\.be)\/user\/([a-z0-9\-\_]+)/i', $body, $matches) > 0) {
            foreach ($matches[2] as $id) {
                $found[] = ['kind' => 'youtube-user', 'id' => $id];
            }
        } elseif (preg_match_all('/(youtube\.com|youtu\.be)\/([a-z0-9\-\_]+)/i', $body, $matches) > 0) {
            foreach ($matches[2] as $id) {
                $found[] = ['kind' => 'youtube', 'id' => $id];
            }
        }
        if (preg_match_all('/vimeo\.com\/([0-9]+)/i', $body, $matches) > 0) {
            foreach ($matches[1] as $id) {
                $found[] = ['kind' => 'vimeo', 'id' => $id];
            }
        }
        if (preg_match_all('/https?:\/\/(www\.)?instagram\.com\/p\/([A-Za-z0-9\-\_]+)\/?/i', $body, $matches) > 0) {
            foreach ($matches[2] as $id) {
                $found[] = ['kind' => 'instagram', 'id' => $id];
            }
        }

        return $found;
    }
}
