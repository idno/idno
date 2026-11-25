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
     * and getEmbedUrl().
     *
     * Open services (OPEN_PROVIDERS: PeerTube, Loops) are software that anybody can run,
     * on any host, so an address alone never gives a video: parse() returns null for one.
     * candidate() says what such an address would mean, still without a request, and
     * OpenVideo::resolve() asks the host whether it is so. For these the host is stored
     * with the provider and the id, and is held to HOST_PATTERN (a plain domain name: no
     * address literal, port or userinfo). Their players are drawn in a sandbox
     * (getSandbox()), because nobody has vouched for the host.
     */
    final class VideoUrl
    {

        /// The supported services, by provider key, in the order they are named to people
        const PROVIDERS = [
            'youtube'     => 'YouTube',
            'vimeo'       => 'Vimeo',
            'dailymotion' => 'Dailymotion',
            'tiktok'      => 'TikTok',
            'peertube'    => 'PeerTube',
            'loops'       => 'Loops',
        ];

        /// The providers that are software anybody can run, on any host
        const OPEN_PROVIDERS = ['peertube', 'loops'];

        /// The only hosts a player of a service with hosts of its own is ever loaded from
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
            'peertube'    => '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/',
            'loops'       => '/\A[A-Za-z0-9_-]{4,32}\z/',
        ];

        /// The short form of a PeerTube id, as in /w/<id>. The host is asked for the full one.
        const PEERTUBE_SHORT_ID = '/\A[A-Za-z0-9]{20,24}\z/';

        /// The host of an open service: a domain name in lower case, with a top-level
        /// domain that starts with a letter (so never an IP address), and nothing else
        const HOST_PATTERN = '/\A(?=.{4,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]\z/';

        /// What a player on a host nobody has vouched for may do: run its scripts and keep
        /// its own storage. It may not navigate this page, open windows or submit forms.
        const OPEN_SANDBOX = 'allow-scripts allow-same-origin allow-presentation';

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
        private string $host;

        private function __construct(string $provider, string $id, int $start, string $key, bool $portrait, string $host)
        {
            $this->host = $host;
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
            $read = self::read($address);
            if ($read === null || !isset(self::HOSTS[$read['host']])) {
                return null;
            }
            list('host' => $host, 'segments' => $segments, 'query' => $query) = $read;

            $provider = self::HOSTS[$host];

            $found = self::idFromAddress($provider, $host, $segments, $query);
            if ($found === null) {
                return null;
            }

            return self::fromStored($provider, $found['id'], $read['start'], $found['key'] ?? '', !empty($found['portrait']));
        }

        /**
         * What an address would mean if its host runs an open service (PeerTube, Loops):
         * the provider, the host, the id as it is in the address and the start time. No
         * request is made, so this is not yet a video: OpenVideo::resolve() asks the host.
         *
         * @param  mixed $address What a member typed
         * @return array|null 'provider', 'host', 'id' and 'start'; null when the address has no such form,
         *                    or is on the host of a service with hosts of its own
         */
        public static function candidate($address): ?array
        {
            $read = self::read($address);
            if ($read === null || isset(self::HOSTS[$read['host']]) || in_array($read['host'], self::EMBED_HOSTS, true)) {
                return null;
            }
            if (!preg_match(self::HOST_PATTERN, $read['host'])) {
                return null;
            }
            $segments = $read['segments'];
            $count = count($segments);

            $provider = null;
            if (($count === 2 && $segments[0] === 'w') || ($count === 3 && $segments[0] === 'videos' && in_array($segments[1], ['watch', 'embed'], true))) {
                $provider = 'peertube';
                $id = $segments[$count - 1];
                if (!preg_match(self::ID_PATTERNS['peertube'], $id) && !preg_match(self::PEERTUBE_SHORT_ID, $id)) {
                    return null;
                }
            } elseif ($count === 2 && in_array($segments[0], ['v', 'embed'], true)) {
                $provider = 'loops';
                $id = $segments[1];
                if (!preg_match(self::ID_PATTERNS['loops'], $id)) {
                    return null;
                }
            }
            if ($provider === null) {
                return null;
            }

            return ['provider' => $provider, 'host' => $read['host'], 'id' => $id, 'start' => $read['start']];
        }

        /**
         * Take an address apart, refusing anything that is not a plain https address.
         *
         * @param  mixed $address
         * @return array|null 'host' (lower case), 'segments' (the parts of the path), 'query' and 'start'
         */
        private static function read($address): ?array
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
            if (strtolower($authority) !== $host) {
                return null;
            }

            $path = $parts['path'] ?? '/';
            if (strpos($path, '%') !== false || strpos($path, '//') !== false || strpos($path, '..') !== false) {
                return null;
            }
            $segments = $path === '/' ? [] : explode('/', trim($path, '/'));

            $query = [];
            parse_str($parts['query'] ?? '', $query);
            $fragment = $parts['fragment'] ?? '';

            $start = 0;
            foreach ([$query['t'] ?? null, $query['start'] ?? null, self::timeInFragment($fragment)] as $time) {
                if ($start === 0) {
                    $start = self::seconds($time);
                }
            }

            return ['host' => $host, 'segments' => $segments, 'query' => $query, 'start' => $start];
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
         * @param  mixed $host     For an open service (PeerTube, Loops), the host it runs on
         * @return VideoUrl|null null when the provider is unknown or the id, key or host is not well formed
         */
        public static function fromStored($provider, $id, $start = 0, $key = '', $portrait = false, $host = ''): ?VideoUrl
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
            if (in_array($provider, self::OPEN_PROVIDERS, true)) {
                if (!is_string($host) || !preg_match(self::HOST_PATTERN, $host) || isset(self::HOSTS[$host]) || in_array($host, self::EMBED_HOSTS, true)) {
                    return null;
                }
            } elseif ($host !== '' && $host !== null && $host !== false) {
                return null;
            } else {
                $host = '';
            }
            // TikTok and Loops videos are always shot upright
            $portrait = $provider === 'tiktok' || $provider === 'loops' || !empty($portrait);

            return new VideoUrl($provider, $id, $start, $key, $portrait, $host);
        }

        /**
         * The names of the services whose videos can be posted.
         *
         * @param  bool $open Whether to name the open services (PeerTube, Loops) too
         * @return string[]
         */
        public static function supportedServices(bool $open = false): array
        {
            $names = [];
            foreach (self::PROVIDERS as $provider => $name) {
                if ($open || !in_array($provider, self::OPEN_PROVIDERS, true)) {
                    $names[] = $name;
                }
            }

            return $names;
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
         * @return bool Whether this is a video on an open service, which runs on a host of its own
         */
        public function isOpen(): bool
        {
            return $this->host !== '';
        }

        /**
         * @return string The host of an open service, or an empty string
         */
        public function getHost(): string
        {
            return $this->host;
        }

        /**
         * @return string The value of the player's sandbox attribute, or an empty string for none
         */
        public function getSandbox(): string
        {
            return $this->isOpen() ? self::OPEN_SANDBOX : '';
        }

        /**
         * The address of the provider's player for this video: https, on a host in
         * EMBED_HOSTS or, for an open service, on its own host, made of fixed text and
         * checked values only.
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

                case 'peertube':
                    return 'https://' . $this->host . '/videos/embed/' . $this->id
                        . ($this->start ? '?start=' . $this->start . 's' : '');

                case 'loops':
                    return 'https://' . $this->host . '/embed/' . $this->id
                        . ($this->start ? '?t=' . $this->start : '');

                case 'tiktok':
                default:
                    return 'https://www.tiktok.com/player/v1/' . $this->id;
            }
        }
    }

}
