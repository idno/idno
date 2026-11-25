<?php

namespace IdnoPlugins\Video {

    /**
     * A video post: a video on a video sharing service, with an optional title and a
     * description.
     *
     * What is stored:
     *
     * - video_source: where the video is. Always "link" for now: a video on another
     *   service. A later version that keeps video files on this site will store "file"
     *   and attach the file as other entities do (attachments); posts stored with "link"
     *   will need no change.
     * - video_url: the address the member gave, as typed. Only ever printed as a link.
     * - video_provider, video_id, video_start, video_key, video_portrait: what VideoUrl
     *   made of that address. The player is built from these, after VideoUrl has checked
     *   them again (see getVideo()).
     * - video_host: for a video on an open service (PeerTube, Loops), the host it is on.
     *   The host was asked when the post was saved (OpenVideo); it is not asked again.
     * - title, body (the description, HTML) and tags.
     */
    class Video extends \Idno\Common\Entity
    {

        /// The title the video's own host gave when the address was set, if any
        private $titleFromHost = '';

        /// Why the last address given to setVideoAddress() was refused, in words for the member
        private $refusal = '';

        /**
         * Whether videos on open services (PeerTube, Loops), which run on any host, may be
         * posted and played. A site's admin can turn this off; it is on unless they have.
         *
         * @return bool
         */
        static function openServicesAllowed(): bool
        {
            $setting = \Idno\Core\Idno::site()->config()->video_open_services;

            return !($setting === false || $setting === 0 || $setting === '0' || $setting === 'false');
        }

        /**
         * Whether the site's admin has blocked a host (or the domain it is under).
         *
         * @param  string $host
         * @return bool
         */
        static function isBlockedHost(string $host): bool
        {
            $blocked = \Idno\Core\Idno::site()->config()->video_blocked_hosts;
            if (empty($blocked) || !is_array($blocked)) {
                return false;
            }
            $host = strtolower($host);
            foreach ($blocked as $entry) {
                if (!is_string($entry) || $entry === '') {
                    continue;
                }
                $entry = strtolower($entry);
                if ($host === $entry || substr($host, -strlen($entry) - 1) === '.' . $entry) {
                    return true;
                }
            }

            return false;
        }

        /**
         * The names of the services a member can post a video from, as the site is set up.
         *
         * @return string[]
         */
        static function serviceNames(): array
        {
            return VideoUrl::supportedServices(self::openServicesAllowed());
        }

        /**
         * The title the member gave or, without one, the kind of video this is.
         *
         * @return string
         */
        function getTitle()
        {
            if (!empty($this->title) && is_string($this->title)) {
                return $this->title;
            }
            if ($video = $this->getVideo()) {
                return \Idno\Core\Idno::site()->language()->_('Video on %s', [$video->getProviderName()]);
            }

            return \Idno\Core\Idno::site()->language()->_('Video');
        }

        function getDescription()
        {
            return is_string($this->body) ? $this->body : '';
        }

        /**
         * Video posts have type 'video', which federates as a Note
         * @return 'video'
         */
        function getActivityStreamsObjectType()
        {
            return 'video';
        }

        /**
         * The video, from the stored provider and id. They are checked again here,
         * because what is stored may not have been written by this class.
         *
         * @return VideoUrl|null null when there is no video that can be played
         */
        function getVideo(): ?VideoUrl
        {
            if ($this->video_source !== 'link') {
                return null;
            }

            $video = VideoUrl::fromStored($this->video_provider, $this->video_id, $this->video_start, $this->video_key, $this->video_portrait, $this->video_host);

            // A host that anybody can run is framed only while the site's admin allows it
            if ($video && $video->isOpen() && (!self::openServicesAllowed() || self::isBlockedHost($video->getHost()))) {
                return null;
            }

            return $video;
        }

        /**
         * The address of the video as the member gave it, if it can be printed as a link.
         *
         * @return string An http or https address, or an empty string
         */
        function getVideoAddress(): string
        {
            return is_string($this->video_url) ? \Idno\Core\UrlValidator::httpUrl($this->video_url) : '';
        }

        /**
         * Set the video from its address on a supported service. Nothing is changed when
         * the address is not recognised. For an address that has the form of one on an
         * open service (PeerTube, Loops), the host is asked whether it has the video; that
         * is the only case in which a request is made.
         *
         * @param  mixed $address
         * @return bool Whether the address was recognised
         */
        function setVideoAddress($address): bool
        {
            $this->refusal = '';
            $this->titleFromHost = '';

            // The address this post already has: nothing to work out, and nothing to ask
            if (is_string($address) && trim($address) !== '' && trim($address) === $this->video_url && $this->getVideo()) {
                return true;
            }

            $video = VideoUrl::parse($address);

            if (!$video && self::openServicesAllowed() && ($candidate = VideoUrl::candidate($address))) {
                // The address has the form of one on PeerTube or Loops: ask the host
                if (!self::isBlockedHost($candidate['host']) && ($found = OpenVideo::resolve($candidate))) {
                    $video = $found['video'];
                    $this->titleFromHost = $found['title'];
                } else {
                    $this->refusal = \Idno\Core\Idno::site()->language()->_(
                        'We couldn\'t find that video on %s. Check the address, or try again in a moment.',
                        [$candidate['host']]
                    );
                }
            }
            if (!$video) {
                return false;
            }

            $this->video_source   = 'link';
            $this->video_url      = trim($address);
            $this->video_provider = $video->getProvider();
            $this->video_id       = $video->getId();
            $this->video_start    = $video->getStart();
            $this->video_key      = $video->getKey();
            $this->video_portrait = $video->isPortrait();
            $this->video_host     = $video->getHost();

            return true;
        }

        /**
         * What other servers are sent as the content of the post (ActivityPub): the title,
         * the description and a link to the video. No player.
         *
         * @return string HTML
         */
        function getFormattedContent()
        {
            $t = \Idno\Core\Idno::site()->template();
            $content = '';

            if (!empty($this->title) && is_string($this->title)) {
                $content .= '<p><strong>' . htmlspecialchars(strip_tags($this->title)) . '</strong></p>';
            }
            if ($this->getDescription() !== '') {
                $content .= $t->autop($t->parseHashtags($t->parseURLs($t->purifier->purify($this->getDescription()), '', false)));
            }
            if ($address = $this->getVideoAddress()) {
                $content .= '<p><a href="' . htmlspecialchars($address) . '">' . htmlspecialchars($address) . '</a></p>';
            }

            return preg_replace('/\R+/', '', $content);
        }

        /**
         * The post as JSON, with the address of the video.
         */
        public function jsonSerialize(): mixed
        {
            $object = parent::jsonSerialize();
            if ($address = $this->getVideoAddress()) {
                $object['video'] = $address;
            }

            return $object;
        }

        /**
         * Return metadata for feeds.
         */
        function getMetadataForFeed()
        {
            $meta = ['type' => 'video'];
            if ($address = $this->getVideoAddress()) {
                $meta['video'] = $address;
            }

            return $meta;
        }

        /**
         * Saves changes to this object based on user input
         * @return true|false
         */
        function saveDataFromInput()
        {
            $page = \Idno\Core\Idno::site()->currentPage();
            $t = \Idno\Core\Idno::site()->template();

            if (!$this->setVideoAddress($page->getInput('video'))) {
                \Idno\Core\Idno::site()->session()->addErrorMessage(
                    $this->refusal ?: \Idno\Core\Idno::site()->language()->_(
                        'That doesn\'t look like the address of a video we can show. Paste the address of a video on one of: %s.',
                        [implode(', ', self::serviceNames())]
                    )
                );

                return false;
            }

            $this->title = trim(strip_tags((string) $page->getInput('title')));
            if ($this->title === '' && empty($this->_id)) {
                // A new post with no title takes the one its host has for the video
                $this->title = trim(strip_tags($this->titleFromHost));
            }
            $this->body  = $t->sanitize_html((string) $page->getInput('body'));
            $this->tags  = $page->getInput('tags');
            $this->setAccess($page->getInput('access'));

            if ($time = $page->getInput('created')) {
                if ($time = strtotime($time)) {
                    $this->created = $time;
                }
            }

            if ($page->getInput('publish_status') === 'draft') {
                return (bool) $this->saveAsDraft();
            }

            $this->setPublishStatus('published');
            if ($this->publish()) {
                if ($this->getAccess() == 'PUBLIC') {
                    \Idno\Core\Webmention::pingMentions($this->getURL(), $t->parseURLs($this->getDescription()));
                }

                return true;
            }

            return false;
        }

        function deleteData()
        {
            if ($this->getAccess() == 'PUBLIC') {
                \Idno\Core\Webmention::pingMentions($this->getURL(), \Idno\Core\Idno::site()->template()->parseURLs($this->getDescription()));
            }
        }

    }

}
