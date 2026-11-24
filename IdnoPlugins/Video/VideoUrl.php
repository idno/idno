<?php

namespace IdnoPlugins\Video {

    /**
     * The address of a video on a video sharing service, taken apart.
     *
     * parse() recognises the address of one video on a supported service and gives the
     * provider, the id of the video and the address of the provider's player. Everything
     * comes from the text of the address: no request is made to the service or to anything
     * else. An address that is not recognised gives null.
     *
     * The player address is always built here, from a fixed https address on a host in
     * EMBED_HOSTS and an id that has matched the provider's pattern. Nothing a member
     * typed is copied into it. fromStored() makes the same checks on values read back
     * from the database, which are not trusted either.
     *
     * To add a service: add it to PROVIDERS, give its hosts in HOSTS, its id pattern in
     * ID_PATTERNS and its player host in EMBED_HOSTS, and add a case to idFromAddress()
     * and getEmbedUrl(). A service that runs on any host (PeerTube) cannot be added this
     * way: the player would be framed from a host that nobody has vouched for.
     */
    final class VideoUrl
    {

        /// The supported services, by provider key, in the order they are named to people
        const PROVIDERS = [
            'youtube'     => 'YouTube',
            'vimeo'       => 'Vimeo',
            'dailymotion' => 'Dailymotion',
            'tiktok'      => 'TikTok',
        ];

        /// The only hosts a player is ever loaded from
        const EMBED_HOSTS = [
            'www.youtube-nocookie.com',
            'player.vimeo.com',
            'geo.dailymotion.com',
            'www.tiktok.com',
        ];

        /// The hosts whose addresses are read, and the provider each belongs to
        const HOSTS = [
            'youtube.com'              => 'youtube',
            'www.youtube.com'          => 'youtube',
            'm.youtube.com'            => 'youtube',
            'music.youtube.com'        => 'youtube',
            'youtu.be'                 => 'youtube',
            'www.youtube-nocookie.com' => 'youtube',
            'vimeo.com'                => 'vimeo',
            'www.vimeo.com'            => 'vimeo',
            'player.vimeo.com'         => 'vimeo',
            'dailymotion.com'          => 'dailymotion',
            'www.dailymotion.com'      => 'dailymotion',
            'geo.dailymotion.com'      => 'dailymotion',
            'dai.ly'                   => 'dailymotion',
            'tiktok.com'               => 'tiktok',
            'www.tiktok.com'           => 'tiktok',
            'm.tiktok.com'             => 'tiktok',
        ];

        /// What the id of a video looks like, for each provider
        const ID_PATTERNS = [
            'youtube'     => '/\A[A-Za-z0-9_-]{11}\z/',
            'vimeo'       => '/\A[0-9]{1,12}\z/',
            'dailymotion' => '/\A[a-z0-9]{5,12}\z/',
            'tiktok'      => '/\A[0-9]{15,22}\z/',
        ];

        /// The key of an unlisted Vimeo video (the "h" in its address)
        const KEY_PATTERN = '/\A[0-9a-f]{6,16}\z/';

        /// Longest address that is looked at
        const MAX_LENGTH = 2048;

        /// Latest start time: a day
        const MAX_START = 86400;

        private string $provider;
        private string $id;
        private int $start;
        private string $key;
        private bool $portrait;

        private function __construct(string $provider, string $id, int $start, string $key, bool $portrait)
        {
            $this->provider = $provider;
            $this->id = $id;
            $this->start = $start;
            $this->key = $key;
            $this->portrait = $portrait;
        }

        /**
         * Recognise the address of a video.
         *
         * @param  mixed $address What a member typed
         * @return VideoUrl|null null when it is not the address of a video on a supported service
         */
        public static function parse($address): ?VideoUrl
        {
            if (!is_string($address)) {
                return null;
            }
            $address = trim($address, " \t\r\n");
            if ($address === '' || strlen($address) > self::MAX_LENGTH) {
                return null;
            }
            // Printable ASCII only, and no character that has no place in an address:
            // this also refuses control characters, spaces, quotes, brackets and backslashes
            if (!preg_match('/\A[\x21-\x7E]+\z/', $address) || preg_match('/["\'<>\\\\^`{|}]/', $address)) {
                return null;
            }
            if (strncasecmp($address, 'https://', 8) !== 0) {
                return null;
            }

            $parts = parse_url($address);
            if ($parts === false || empty($parts['host'])) {
                return null;
            }
            if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
                return null;
            }
            // The text before the first "/", "?" or "#" must be the host and nothing else,
            // whatever parse_url() made of it
            $authority = substr($address, 8, strcspn($address, '/?#', 8));
            $host = strtolower($parts['host']);
            if (strtolower($authority) !== $host || !isset(self::HOSTS[$host])) {
                return null;
            }

            $provider = self::HOSTS[$host];
            $path = $parts['path'] ?? '/';
            if (strpos($path, '%') !== false || strpos($path, '//') !== false || strpos($path, '..') !== false) {
                return null;
            }
            $segments = $path === '/' ? [] : explode('/', trim($path, '/'));

            $query = [];
            parse_str($parts['query'] ?? '', $query);
            $fragment = $parts['fragment'] ?? '';

            $found = self::idFromAddress($provider, $host, $segments, $query);
            if ($found === null) {
                return null;
            }

            $start = 0;
            foreach ([$query['t'] ?? null, $query['start'] ?? null, self::timeInFragment($fragment)] as $time) {
                if ($start === 0) {
                    $start = self::seconds($time);
                }
            }

            return self::fromStored($provider, $found['id'], $start, $found['key'] ?? '', !empty($found['portrait']));
        }

        /**
         * A video from the values that were stored for it. They are checked as strictly as
         * the parts of an address are.
         *
         * @param  mixed $provider A key of PROVIDERS
         * @param  mixed $id       The provider's id of the video
         * @param  mixed $start    Seconds into the video at which to start
         * @param  mixed $key      The key of an unlisted Vimeo video
         * @param  mixed $portrait Whether the video is taller than it is wide
         * @return VideoUrl|null null when the provider is unknown or the id or key is not well formed
         */
        public static function fromStored($provider, $id, $start = 0, $key = '', $portrait = false): ?VideoUrl
        {
            if (!is_string($provider) || !isset(self::PROVIDERS[$provider])) {
                return null;
            }
            if (!is_string($id) || !preg_match(self::ID_PATTERNS[$provider], $id)) {
                return null;
            }
            if ($key === null || $key === '' || $key === false) {
                $key = '';
            } elseif ($provider !== 'vimeo' || !is_string($key) || !preg_match(self::KEY_PATTERN, $key)) {
                return null;
            }
            if ((is_int($start) || (is_string($start) && ctype_digit($start))) && (int) $start > 0 && (int) $start <= self::MAX_START) {
                $start = (int) $start;
            } else {
                $start = 0;
            }
            // TikTok videos are always shot upright
            $portrait = $provider === 'tiktok' || !empty($portrait);

            return new VideoUrl($provider, $id, $start, $key, $portrait);
        }

        /**
         * The names of the services whose videos can be posted.
         *
         * @return string[]
         */
        public static function supportedServices(): array
        {
            return array_values(self::PROVIDERS);
        }

        /**
         * Find the id of the video in a provider's address.
         *
         * @param  string   $provider
         * @param  string   $host     Lower case
         * @param  string[] $segments The parts of the path
         * @param  array    $query    The query string, parsed
         * @return array|null 'id', and where they apply 'key' and 'portrait'. The id is not checked yet.
         */
        private static function idFromAddress(string $provider, string $host, array $segments, array $query): ?array
        {
            $count = count($segments);
            $string = function ($value) {
                return is_string($value) ? $value : null;
            };

            switch ($provider) {
                case 'youtube':
                    if ($host === 'youtu.be') {
                        return $count === 1 ? ['id' => $segments[0]] : null;
                    }
                    if ($count === 1 && $segments[0] === 'watch' && $host !== 'www.youtube-nocookie.com') {
                        $id = $string($query['v'] ?? null);

                        return $id === null ? null : ['id' => $id];
                    }
                    if ($count === 2 && in_array($segments[0], ['embed', 'live', 'shorts', 'v'], true)) {
                        if ($host === 'www.youtube-nocookie.com' && $segments[0] !== 'embed') {
                            return null;
                        }

                        return ['id' => $segments[1], 'portrait' => $segments[0] === 'shorts'];
                    }

                    return null;

                case 'vimeo':
                    $key = $string($query['h'] ?? '');
                    if ($key === null) {
                        return null;
                    }
                    if ($host === 'player.vimeo.com') {
                        return ($count === 2 && $segments[0] === 'video') ? ['id' => $segments[1], 'key' => $key] : null;
                    }
                    if ($count === 1) {
                        return ['id' => $segments[0], 'key' => $key];
                    }
                    if ($count === 2 && ctype_digit($segments[0])) {
                        // vimeo.com/<id>/<key>: an unlisted video
                        return ['id' => $segments[0], 'key' => $segments[1]];
                    }
                    if ($count === 3 && $segments[0] === 'channels') {
                        return ['id' => $segments[2], 'key' => $key];
                    }
                    if ($count === 4 && $segments[0] === 'groups' && $segments[2] === 'videos') {
                        return ['id' => $segments[3], 'key' => $key];
                    }

                    return null;

                case 'dailymotion':
                    if ($host === 'dai.ly') {
                        return $count === 1 ? ['id' => $segments[0]] : null;
                    }
                    if ($host === 'geo.dailymotion.com') {
                        // The player's own address: player.html?video=<id>
                        $id = $string($query['video'] ?? null);

                        return ($count === 1 && $segments[0] === 'player.html' && $id !== null) ? ['id' => $id] : null;
                    }
                    if ($count === 2 && $segments[0] === 'video') {
                        // The id may be followed by "_" and a title
                        return ['id' => explode('_', $segments[1], 2)[0]];
                    }
                    if ($count === 3 && $segments[0] === 'embed' && $segments[1] === 'video') {
                        return ['id' => $segments[2]];
                    }

                    return null;

                case 'tiktok':
                    if ($count === 3 && preg_match('/\A@[A-Za-z0-9_.]{1,32}\z/', $segments[0]) && $segments[1] === 'video') {
                        return ['id' => $segments[2]];
                    }
                    if ($host === 'm.tiktok.com' && $count === 2 && $segments[0] === 'v' && substr($segments[1], -5) === '.html') {
                        return ['id' => substr($segments[1], 0, -5)];
                    }
                    if ($host === 'www.tiktok.com' && $count === 3 && $segments[0] === 'player' && $segments[1] === 'v1') {
                        return ['id' => $segments[2]];
                    }

                    return null;
            }

            return null;
        }

        /**
         * The time in a fragment such as "t=1m30s".
         */
        private static function timeInFragment(string $fragment): ?string
        {
            return preg_match('/\At=([0-9hms]{1,12})\z/', $fragment, $match) ? $match[1] : null;
        }

        /**
         * A start time ("90", "90s", "1m30s", "1h2m3s") in seconds; 0 when it is not one.
         *
         * @param  mixed $time
         * @return int
         */
        private static function seconds($time): int
        {
            if (!is_string($time) || $time === '' || strlen($time) > 12) {
                return 0;
            }
            if (ctype_digit($time)) {
                $seconds = (int) $time;
            } elseif (preg_match('/\A(?:([0-9]{1,3})h)?(?:([0-9]{1,4})m)?(?:([0-9]{1,5})s)?\z/', $time, $match)) {
                $seconds = ((int) ($match[1] ?? 0)) * 3600 + ((int) ($match[2] ?? 0)) * 60 + (int) ($match[3] ?? 0);
            } else {
                return 0;
            }

            return ($seconds > 0 && $seconds <= self::MAX_START) ? $seconds : 0;
        }

        /**
         * @return string A key of PROVIDERS
         */
        public function getProvider(): string
        {
            return $this->provider;
        }

        /**
         * @return string The name of the service, as people know it
         */
        public function getProviderName(): string
        {
            return self::PROVIDERS[$this->provider];
        }

        public function getId(): string
        {
            return $this->id;
        }

        /**
         * @return int Seconds into the video at which to start; 0 for the beginning
         */
        public function getStart(): int
        {
            return $this->start;
        }

        /**
         * @return string The key of an unlisted Vimeo video, or an empty string
         */
        public function getKey(): string
        {
            return $this->key;
        }

        /**
         * @return bool Whether the video is taller than it is wide (TikTok, YouTube Shorts)
         */
        public function isPortrait(): bool
        {
            return $this->portrait;
        }

        /**
         * The address of the provider's player for this video: https, on a host in
         * EMBED_HOSTS, made of fixed text and checked values only.
         *
         * @return string
         */
        public function getEmbedUrl(): string
        {
            switch ($this->provider) {
                case 'youtube':
                    return 'https://www.youtube-nocookie.com/embed/' . $this->id
                        . ($this->start ? '?start=' . $this->start : '');

                case 'vimeo':
                    return 'https://player.vimeo.com/video/' . $this->id
                        . '?' . ($this->key !== '' ? 'h=' . $this->key . '&' : '') . 'dnt=1'
                        . ($this->start ? '#t=' . $this->start . 's' : '');

                case 'dailymotion':
                    // www.dailymotion.com/embed/video/<id> only redirects here
                    return 'https://geo.dailymotion.com/player.html?video=' . $this->id
                        . ($this->start ? '&startTime=' . $this->start : '');

                case 'tiktok':
                default:
                    return 'https://www.tiktok.com/player/v1/' . $this->id;
            }
        }
    }

}
