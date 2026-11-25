<?php

namespace IdnoPlugins\Video\Pages {

    use Idno\Core\Idno;
    use IdnoPlugins\Video\VideoUrl;

    /**
     * Administration: whether videos on PeerTube and Loops sites, which run on any host,
     * may be posted and played, and the hosts that may not.
     */
    class Admin extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->adminGatekeeper(); // Admins only

            $blocked = Idno::site()->config()->video_blocked_hosts;

            $t = Idno::site()->template();
            $t->__(array(
                'body'  => $t->__(array(
                    'open'    => \IdnoPlugins\Video\Video::openServicesAllowed(),
                    'blocked' => is_array($blocked) ? array_values(array_filter($blocked, 'is_string')) : [],
                ))->draw('video/admin'),
                'title' => Idno::site()->language()->_('Video')
            ))->drawPage();
        }

        function postContent()
        {
            $this->adminGatekeeper(); // Admins only

            Idno::site()->config()->video_open_services = !empty($this->getInput('video_open_services'));
            Idno::site()->config()->video_blocked_hosts = self::cleanHosts($this->getInput('video_blocked_hosts'));
            Idno::site()->config()->save();

            Idno::site()->session()->addMessage(Idno::site()->language()->_('Your video settings were saved.'));
            $this->forward(Idno::site()->config()->getDisplayURL() . 'admin/video/');
        }

        /**
         * The host names in a piece of text an administrator typed: one to a line, or
         * separated by spaces or commas; an address is cut down to its host.
         *
         * @param  mixed $text
         * @return string[] Host names in lower case, each once
         */
        static function cleanHosts($text): array
        {
            if (!is_string($text)) {
                return [];
            }
            $hosts = [];
            foreach (preg_split('/[\s,;]+/', strtolower($text)) as $entry) {
                $entry = preg_replace('~\Ahttps?://~', '', $entry);
                $entry = preg_replace('~[/?#].*\z~s', '', $entry);
                if ($entry !== '' && preg_match(VideoUrl::HOST_PATTERN, $entry)) {
                    $hosts[$entry] = true;
                }
            }

            return array_keys($hosts);
        }

    }

}
