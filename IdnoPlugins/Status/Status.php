<?php

namespace IdnoPlugins\Status {

    class Status extends \Idno\Common\Entity
    {

        /**
         * Create an appropriate status class.
         * Returns an appropriate new class, either a Status or a Reply, depending on whether it is in reply to something.
         * @return Status|Reply
         */
        public static function factory()
        {
            $inreplyto = \Idno\Core\Idno::site()->currentPage()->getInput('inreplyto');
            $body      = \Idno\Core\Idno::site()->currentPage()->getInput('body');

            if (!empty($inreplyto)) {
                return new Reply();
            }

            // An empty or missing body is an ordinary new status
            if (is_string($body) && substr($body, 0, 1) == '@') {
                return new Reply();
            }

            return new Status();
        }

        function getTitle()
        {
            $title = trim($this->getShortDescription(7));
            if (empty($title)) {
                $title = \Idno\Core\Idno::site()->language('Status update');
            } else if ($title != trim($this->getShortDescription())) $title .= ' ...';

            return $title;
        }

        function getDescription()
        {
            $body = $this->body;
            if (!empty($this->inreplyto)) {
                if (is_array($this->inreplyto)) {
                    foreach ($this->inreplyto as $inreplyto) {
                        $body = '<a href="' . $inreplyto . '" class="u-in-reply-to"></a>' . $body;
                    }
                } else {
                    $body = '<a href="' . $this->inreplyto . '" class="u-in-reply-to"></a>' . $body;
                }
            }
            if (!empty($this->syndicatedto)) {
                foreach ($this->syndicatedto as $syndicated) {
                    $body = '<a href="' . $syndicated . '" class="u-in-reply-to"></a>' . $body;
                }
            }

            return $body;
        }

        /**
         * Status objects have type 'note'
         * @return 'note'
         */
        function getActivityStreamsObjectType()
        {
            return 'note';
        }

        function getMetadataForFeed()
        {
            $meta = array('type' => 'status');
            if ($this->inreplyto) {
                $meta['in-reply-to'] = $this->inreplyto;
                $meta['type'] = 'reply';
            }
            return $meta;
        }

        /**
         * Saves changes to this object based on user input
         * @return true|false
         */
        function saveDataFromInput()
        {

            if (empty($this->_id)) {
                $new = true;
            } else {
                $new = false;
            }
            $body      = \Idno\Core\Idno::site()->currentPage()->getInput('body');
            $inreplyto = \Idno\Core\Idno::site()->currentPage()->getInput('inreplyto');
            if (is_array($inreplyto)) {
                $inreplyto = array_values(array_filter($inreplyto, function ($v) {
                    return !empty(trim($v));
                }));
            }
            $tags      = \Idno\Core\Tags::clean(\Idno\Core\Idno::site()->currentPage()->getInput('tags'));
            $access    = \Idno\Core\Idno::site()->currentPage()->getInput('access');

            if ($time = \Idno\Core\Idno::site()->currentPage()->getInput('created')) {
                if ($time = strtotime($time)) {
                    $this->created = $time;
                }
            }

            if (!empty($body) || ('0' === $body)) {
                $this->body      = $body;
                $this->tags      = $tags;

                // TODO fetch syndicated reply targets asynchronously (or maybe on-demand, when syndicating?)
                if (!empty($inreplyto)) {

                    $this->inreplyto = $inreplyto;

                    if (is_array($inreplyto)) {
                        foreach ($inreplyto as $inreplytourl) {
                            if (!empty($inreplytourl)) {
                                $this->syndicatedto = \Idno\Core\Webmention::addSyndicatedReplyTargets($inreplytourl, $this->syndicatedto);
                            }
                        }
                    } else {
                        $this->syndicatedto = \Idno\Core\Webmention::addSyndicatedReplyTargets($inreplyto);
                    }
                }
                $this->setAccess($access);

                $publish_status = \Idno\Core\Idno::site()->currentPage()->getInput('publish_status', 'published');

                if ($publish_status === 'draft') {
                    if ($this->saveAsDraft()) {
                        return true;
                    }
                } else {
                    $this->setPublishStatus('published');
                    if ($this->publish($new)) {

                        if ($this->getAccess() == 'PUBLIC') {
                            \Idno\Core\Idno::site()->queue()->enqueue('default', 'webmention/sendall', [
                                'source' => $this->getURL(),
                                'text' => \Idno\Core\Idno::site()->template()->parseURLs($this->getDescription()),
                            ]);
                        }

                        return true;
                    }
                }
            } else {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language('You can\'t save an empty status update.'));
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
