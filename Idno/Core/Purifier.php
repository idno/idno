<?php

namespace Idno\Core {

    class Purifier extends \Idno\Common\Component
    {

        protected $purifier;

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

