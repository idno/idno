<?php

namespace Idno\Core {

    class Purifier extends \Idno\Common\Component
    {

        protected $purifier;

        // What the purified output depends on besides the text: the library's version and the allowed iframes
        private string $configKey = '';

        // The outputs of this request, by key (bounded: a queue worker is one process for many messages)
        private array $memo = [];

        /** How long a purified text is kept in the cache (the key holds the library's version and the settings, so a change makes new keys) */
        public const CACHE_SECONDS = 604800;

        function init()
        {
            $allowedIframes = [
                'www.youtube-nocookie.com/embed/',
                'www.youtube.com/embed/',
                'player.vimeo.com/video/',
                'embed.radiopublic.com/e',
                'w.soundcloud.com/player/',
                'maps.google.com/',
                'archive.org/embed',
                'cdn.knightlab.com'
            ];

            if (!empty(Idno::site()->config()->allowedIframes) && is_array(Idno::site()->config()->allowedIframes)) {
                $allowedIframes = array_merge($allowedIframes, Idno::site()->config()->allowedIframes);
            }

            $config = \HTMLPurifier_Config::createDefault();
            $this->useCacheDirectory($config);
            $config->set('HTML.SafeIframe', true);
            $config->set('URI.SafeIframeRegexp', '%^https?://('.implode('|', $allowedIframes).')%');
            $this->purifier = new \HTMLPurifier($config);
            $this->configKey = sha1(\HTMLPurifier::VERSION . "\0" . $config->get('URI.SafeIframeRegexp'));
        }

        /**
         * The cache key of a text's purified output. Purifying a text costs about 100 microseconds, and the same text is purified in every
         * request that shows it.
         *
         * @param string $html
         * @param bool   $basic_html
         * @return string
         */
        public function cacheKey($html, $basic_html = false)
        {
            return 'purify_' . hash('sha256', ($basic_html ? $this->basicConfigKey() : $this->configKey) . "\0" . $html);
        }

        private function basicConfigKey()
        {
            return 'basic' . sha1(\HTMLPurifier::VERSION);
        }

        /**
         * Keeps HTMLPurifier's serialised definitions in the kernel's cache directory (never in the uploads)
         *
         * @param \HTMLPurifier_Config $config
         */
        private function useCacheDirectory(\HTMLPurifier_Config $config)
        {
            $directory = \Idno\Core\Idno::container()->get('kernel')->getCacheDir() . '/htmlpurifier';
            if (!is_dir($directory)) {
                @mkdir($directory, 0755, true);
            }
            if (is_dir($directory) && is_writable($directory)) {
                $config->set('Cache.SerializerPath', $directory);
            } else {
                // Without a directory to write to, definitions are built again for each request
                $config->set('Cache.DefinitionImpl', null);
            }
        }

        function registerEventHooks()
        {
            \Idno\Core\Idno::site()->events()->addListener(
                'text/filter', function (\Idno\Core\Event $event) {
                    $text = $event->response();
                    $text = $this->purify($text);
                    $event->setResponse($text);
                }
            );
            \Idno\Core\Idno::site()->events()->addListener(
                'text/filter/basic', function (\Idno\Core\Event $event) {
                    $text = $event->response();
                    $text = $this->purify($text, true);
                    $event->setResponse($text);
                }
            );
        }

        /**
         * Purifies HTML code
         *
         * @param  $html
         * @param  $basic_html Should the purifier strip out inline styles and similar attributes? Defaults to false.
         * @return string Purified HTML
         */
        function purify($html, $basic_html = false)
        {
            if (!is_string($html) || $html === '') {
                return $this->purifyNow($html, $basic_html);
            }
            $key = $this->cacheKey($html, $basic_html);
            if (isset($this->memo[$key])) {
                return $this->memo[$key];
            }
            try {
                $cache = \Idno\Core\Idno::site()->cache();
                $found = $cache->load($key);
            } catch (\Throwable) {
                $cache = null;
                $found = null;
            }
            if (is_string($found)) {
                $output = $found;
            } else {
                $output = $this->purifyNow($html, $basic_html);
                if ($cache !== null && is_string($output)) {
                    try {
                        $cache->store($key, $output, self::CACHE_SECONDS);
                    } catch (\Throwable) {
                        // A page is never refused because a purified text could not be kept
                    }
                }
            }
            if (count($this->memo) >= 500) {
                $this->memo = [];
            }

            return $this->memo[$key] = $output;
        }

        private function purifyNow($html, $basic_html)
        {
            if ($basic_html) {
                $config = \HTMLPurifier_Config::createDefault();

                $this->useCacheDirectory($config);
                $config->set('CSS.AllowedProperties', []);
                $config->set('HTML.TidyRemove', 'br@clear');
                $purifier = new \HTMLPurifier($config);
            } else {
                $purifier = $this->purifier;
            }

            return $purifier->purify($html);
        }
    }

}

