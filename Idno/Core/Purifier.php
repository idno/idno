<?php

namespace Idno\Core {

    class Purifier extends \Idno\Common\Component
    {

        protected $purifier;

        function init()
        {
            $upload_dir = Idno::site()->config()->getUploadPath();
            if (empty($upload_dir) || str_starts_with($upload_dir, 's3://')) {
                $upload_dir = \Idno\Core\Idno::site()->config()->getTempDir();
            }

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
            $config->set('Cache.SerializerPath', $upload_dir);
            $config->set('HTML.SafeIframe', true);
            $config->set('URI.SafeIframeRegexp', '%^https?://('.implode('|', $allowedIframes).')%');
            $this->purifier = new \HTMLPurifier($config);
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

                $upload_dir = Idno::site()->config()->getUploadPath();
                if (empty($upload_dir) || str_starts_with($upload_dir, 's3://')) {
                    $upload_dir = \Idno\Core\Idno::site()->config()->getTempDir();
                }

                $config->set('Cache.SerializerPath', $upload_dir);
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

