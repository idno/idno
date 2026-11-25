<?php

namespace IdnoPlugins\StandardSiteSync;

/**
 * What has to be asked for before a post can be written: the DID behind a handle, and
 * the record a post replies to or quotes. Bluesky's public API is asked (a fixed host),
 * and a picture for a card is fetched from the fixed host PostSource names; every
 * request goes through Webservice::safeSend(), over https only.
 *
 * Every method answers null when it could not find out; a post is then written without
 * the mention, as a card and not a quote, or without a picture.
 */
class BlueskyLookup
{
    /// Bluesky's public API, which describes public posts and accounts to anybody
    const API = 'https://public.api.bsky.app/xrpc/';

    /// The most that is read of an answer, and the largest picture a card may have
    const MAX_ANSWER = 524288;
    const MAX_IMAGE = 1000000;

    /// The address of a post on bsky.app
    const POST_ADDRESS = '~\Ahttps://bsky\.app/profile/([^/?#\s]+)/post/([a-z2-7]{13})/?(?:[?#].*)?\z~';

    const HANDLE_PATTERN = '/\A(?=.{4,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

    /**
     * Replaces the fetcher, for tests: a callable that takes an address and returns
     * what Webservice::safeSend() would ('response', 'content' and 'header').
     *
     * @var callable|null
     */
    public static $fetch = null;

    /**
     * @param  mixed $handle
     * @return string|null The DID of the account that has the handle
     */
    public function resolveHandle($handle): ?string
    {
        if (!is_string($handle) || !preg_match(self::HANDLE_PATTERN, strtolower($handle))) {
            return null;
        }
        $answer = $this->json(self::API . 'com.atproto.identity.resolveHandle?handle=' . rawurlencode(strtolower($handle)));
        $did = $answer['did'] ?? null;

        return is_string($did) && preg_match(RichText::DID_PATTERN, $did) ? $did : null;
    }

    /**
     * The AT-URI of the post an address names: an at:// address of a post, or the
     * address of a post on bsky.app (whose handle is resolved).
     *
     * @param  mixed $address
     * @return string|null
     */
    public function postUri($address): ?string
    {
        if (!is_string($address)) {
            return null;
        }
        $address = trim($address);

        if (strpos($address, 'at://') === 0) {
            $isPost = preg_match(PostRecord::AT_URI_PATTERN, $address) && strpos($address, '/' . PostRecord::COLLECTION . '/') !== false;

            return $isPost ? $address : null;
        }
        if (!preg_match(self::POST_ADDRESS, $address, $found)) {
            return null;
        }
        $actor = rawurldecode($found[1]);
        $did = preg_match(RichText::DID_PATTERN, $actor) ? $actor : $this->resolveHandle($actor);

        return $did ? 'at://' . $did . '/' . PostRecord::COLLECTION . '/' . $found[2] : null;
    }

    /**
     * A post as it is now: what a reply to it or a quote of it must name.
     *
     * @param  mixed $uri The AT-URI of the post, with a DID
     * @return array|null 'uri' and 'cid' of the post, and 'root' ('uri' and 'cid'): the first post of
     *                    the thread it is in, which is the post itself when it is not a reply
     */
    public function post($uri): ?array
    {
        if (!is_string($uri) || !preg_match(PostRecord::AT_URI_PATTERN, $uri)) {
            return null;
        }
        $answer = $this->json(self::API . 'app.bsky.feed.getPosts?uris=' . rawurlencode($uri));
        $post = $answer['posts'][0] ?? null;
        if (!is_array($post) || ($post['uri'] ?? null) !== $uri) {
            return null;
        }
        $ref = ['uri' => $uri, 'cid' => $post['cid'] ?? null];
        if (!PostRecord::isStrongRef($ref)) {
            return null;
        }

        $root = $post['record']['reply']['root'] ?? null;
        $ref['root'] = PostRecord::isStrongRef($root) ? ['uri' => $root['uri'], 'cid' => $root['cid']] : ['uri' => $ref['uri'], 'cid' => $ref['cid']];

        return $ref;
    }

    /**
     * Fetch a picture for a card.
     *
     * @param  mixed $url An https address
     * @return array|null 'bytes' and 'type' (image/jpeg, image/png or image/webp); null unless the answer
     *                    is a picture of one of those types and of at most a megabyte
     */
    public function image($url): ?array
    {
        if (!is_string($url)) {
            return null;
        }
        $result = $this->fetch($url);
        $bytes = $result['content'] ?? '';
        if ((int) ($result['response'] ?? 0) !== 200 || !is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_IMAGE) {
            return null;
        }

        // The type is read from the file itself, not from what the server calls it
        $type = null;
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            $type = 'image/jpeg';
        } elseif (strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0) {
            $type = 'image/png';
        } elseif (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') {
            $type = 'image/webp';
        }

        return $type ? ['bytes' => $bytes, 'type' => $type] : null;
    }

    private function json(string $url): ?array
    {
        $result = $this->fetch($url);
        if ((int) ($result['response'] ?? 0) !== 200) {
            return null;
        }
        $content = $result['content'] ?? '';
        if (!is_string($content) || $content === '' || strlen($content) > self::MAX_ANSWER) {
            return null;
        }
        $decoded = json_decode($content, true, 64);

        return (is_array($decoded) && !array_is_list($decoded)) ? $decoded : null;
    }

    private function fetch(string $url): array
    {
        if (strncmp($url, 'https://', 8) !== 0) {
            return ['response' => 0, 'content' => ''];
        }
        try {
            if (self::$fetch) {
                return (array) call_user_func(self::$fetch, $url);
            }

            return (array) \Idno\Core\Webservice::safeSend('get', $url);
        } catch (\Throwable $e) {
            return ['response' => 0, 'content' => ''];
        }
    }
}
