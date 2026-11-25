<?php

namespace IdnoPlugins\Video {

    /**
     * Asks a host whether it runs an open video service (PeerTube, Loops) and has the video
     * that an address names. VideoUrl::candidate() reads the address; this confirms it.
     *
     * The requests go to the host in the address the member gave, so they are made with
     * Webservice::safeSend(), which refuses addresses on this machine and on private
     * networks and checks every redirect. They are made over https only, when a post is
     * saved, never when one is drawn.
     *
     * An answer in the right form shows that the host answered as the software does at
     * that moment. It does not show that the host can be trusted: any server can give
     * these answers. That is why the players of these services are drawn in a sandbox
     * (VideoUrl::getSandbox()), and why a site's admin can turn them off or block a host.
     */
    class OpenVideo
    {

        /// The most that is read of an answer
        const MAX_ANSWER = 524288;

        /// Longest title that is taken from a host
        const MAX_TITLE = 200;

        /**
         * Replaces the fetcher, for tests: a callable that takes an address and returns
         * what Webservice::safeSend() would ('response' and 'content').
         *
         * @var callable|null
         */
        public static $fetch = null;

        /**
         * Confirm a candidate with its host.
         *
         * @param  mixed $candidate What VideoUrl::candidate() returned
         * @return array|null 'video' (a VideoUrl) and 'title' (the host's title for the video: plain text,
         *                    may be empty); null when the host did not answer as the service does
         */
        public static function resolve($candidate): ?array
        {
            if (!is_array($candidate)) {
                return null;
            }
            $provider = $candidate['provider'] ?? null;
            $host = $candidate['host'] ?? null;
            $id = $candidate['id'] ?? null;
            $start = $candidate['start'] ?? 0;

            if (!is_string($provider) || !in_array($provider, VideoUrl::OPEN_PROVIDERS, true)) {
                return null;
            }
            if (!is_string($host) || !preg_match(VideoUrl::HOST_PATTERN, $host) || isset(VideoUrl::HOSTS[$host])) {
                return null;
            }
            if (!is_string($id)) {
                return null;
            }

            if ($provider === 'peertube') {
                return self::resolvePeerTube($host, $id, $start);
            }

            return self::resolveLoops($host, $id, $start);
        }

        /**
         * PeerTube describes a video at /api/v1/videos/<id>, by its full or its short id.
         */
        private static function resolvePeerTube(string $host, string $id, $start): ?array
        {
            if (!preg_match(VideoUrl::ID_PATTERNS['peertube'], $id) && !preg_match(VideoUrl::PEERTUBE_SHORT_ID, $id)) {
                return null;
            }

            $described = self::json('https://' . $host . '/api/v1/videos/' . $id);
            if ($described === null) {
                return null;
            }

            $uuid = $described['uuid'] ?? null;
            $short = $described['shortUUID'] ?? null;
            $embedPath = $described['embedPath'] ?? null;
            if (!is_string($uuid) || !preg_match(VideoUrl::ID_PATTERNS['peertube'], $uuid)) {
                return null;
            }
            // The answer must be about the video that was asked for
            if ($id !== $uuid && (!is_string($short) || $id !== $short)) {
                return null;
            }
            if ($embedPath !== '/videos/embed/' . $uuid && (!is_string($short) || $embedPath !== '/videos/embed/' . $short)) {
                return null;
            }

            $ratio = $described['aspectRatio'] ?? null;
            $portrait = (is_int($ratio) || is_float($ratio)) && $ratio > 0 && $ratio < 1;

            $video = VideoUrl::fromStored('peertube', $uuid, $start, '', $portrait, $host);
            if (!$video) {
                return null;
            }

            return ['video' => $video, 'title' => self::plainTitle($described['name'] ?? '')];
        }

        /**
         * Loops has no public description of one video, so the host is asked what software
         * it runs (NodeInfo), and the description must be on the host itself.
         */
        private static function resolveLoops(string $host, string $id, $start): ?array
        {
            if (!preg_match(VideoUrl::ID_PATTERNS['loops'], $id)) {
                return null;
            }

            $index = self::json('https://' . $host . '/.well-known/nodeinfo');
            if ($index === null || !isset($index['links']) || !is_array($index['links'])) {
                return null;
            }

            $address = null;
            foreach ($index['links'] as $link) {
                $rel = is_array($link) ? ($link['rel'] ?? null) : null;
                $href = is_array($link) ? ($link['href'] ?? null) : null;
                if (!is_string($rel) || strpos($rel, 'http://nodeinfo.diaspora.software/ns/schema/2.') !== 0) {
                    continue;
                }
                if (is_string($href) && preg_match('~\Ahttps://' . preg_quote($host, '~') . '/[A-Za-z0-9._/-]{1,200}\z~', $href) && strpos($href, '..') === false) {
                    $address = $href;
                }
            }
            if ($address === null) {
                return null;
            }

            $nodeinfo = self::json($address);
            $software = is_array($nodeinfo) && is_array($nodeinfo['software'] ?? null) ? ($nodeinfo['software']['name'] ?? null) : null;
            if ($software !== 'loops') {
                return null;
            }

            $video = VideoUrl::fromStored('loops', $id, $start, '', true, $host);

            return $video ? ['video' => $video, 'title' => ''] : null;
        }

        /**
         * Fetch an address and read its answer as a JSON object.
         *
         * @param  string $url An https address
         * @return array|null null unless the answer is 200 and a JSON object of a reasonable size
         */
        private static function json(string $url): ?array
        {
            $result = self::$fetch ? call_user_func(self::$fetch, $url) : self::fetch($url);

            if (!is_array($result) || (int) ($result['response'] ?? 0) !== 200) {
                return null;
            }
            $content = $result['content'] ?? '';
            if (!is_string($content) || $content === '' || strlen($content) > self::MAX_ANSWER) {
                return null;
            }
            $decoded = json_decode($content, true, 32);

            return (is_array($decoded) && !array_is_list($decoded)) ? $decoded : null;
        }

        /**
         * Fetch an address named by a member: https only, and never on this machine or a
         * private network, directly or through a redirect.
         *
         * @param  string $url
         * @return array What Webservice::safeSend() returns
         */
        public static function fetch(string $url): array
        {
            if (strncmp($url, 'https://', 8) !== 0) {
                return ['response' => 0, 'content' => '', 'error' => 'Only https addresses are fetched'];
            }

            return (array) \Idno\Core\Webservice::safeSend('get', $url, null, ['Accept: application/json']);
        }

        /**
         * A title from another server, as plain text of a reasonable length.
         *
         * @param  mixed $title
         * @return string
         */
        private static function plainTitle($title): string
        {
            if (!is_string($title)) {
                return '';
            }
            $title = trim(preg_replace('/\s+/u', ' ', strip_tags($title)) ?? '');

            return mb_substr($title, 0, self::MAX_TITLE);
        }
    }

}
