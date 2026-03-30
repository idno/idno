<?php

namespace IdnoPlugins\Event {

    class Event extends \Idno\Common\Entity
    {

        function getTitle()
        {
            if (empty($this->title)) return \Idno\Core\Idno::site()->language()->_('Untitled');
            return $this->title;
        }

        function getDescription()
        {
            if (!empty($this->body)) return $this->body;
            return '';
        }

        function getURL()
        {
            // If we have a URL override, use it
            if (!empty($this->url)) {
                return $this->url;
            }

            if (!empty($this->canonical)) {
                return $this->canonical;
            }
            if (!($this->getSlug()) && ($this->getID())) {
                return \Idno\Core\Idno::site()->config()->url . 'event/' . $this->getID() . '/' . $this->getPrettyURLTitle();
            } else {
                return parent::getURL();
            }
        }

        /**
         * Event objects have type 'article'
         * @return 'article'
         */
        function getActivityStreamsObjectType()
        {
            return 'event';
        }

        /**
         * Event objects show up as h-event in a Microformats stream
         * @return string
         */
        function getMicroformats2ObjectType()
        {
            return 'h-event';
        }

        function saveDataFromInput()
        {

            if (empty($this->_id)) {
                $new = true;
            } else {
                $new = false;
            }
            $body = \Idno\Core\Idno::site()->currentPage()->getInput('body');
            if (!empty($body)) {
                $this->body = \Idno\Core\Idno::site()->template()->sanitize_html($body);
                $this->title = \Idno\Core\Idno::site()->currentPage()->getInput('title');
                $this->summary = \Idno\Core\Idno::site()->currentPage()->getInput('summary');
                $this->location = \Idno\Core\Idno::site()->currentPage()->getInput('location');
                $this->starttime = \Idno\Core\Idno::site()->currentPage()->getInput('starttime');
                $this->endtime = \Idno\Core\Idno::site()->currentPage()->getInput('endtime');
                $this->timezone = \Idno\Core\Idno::site()->currentPage()->getInput('timezone');
                $access = \Idno\Core\Idno::site()->currentPage()->getInput('access');

                if ($time = \Idno\Core\Idno::site()->currentPage()->getInput('created')) {
                    if ($time = strtotime($time)) {
                        $this->created = $time;
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
                            \Idno\Core\Webmention::pingMentions($this->getURL(), \Idno\Core\Idno::site()->template()->parseURLs($this->getDescription()));
                        }
                        return true;
                    }
                }
            } else {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('You can\'t save an event with no description.'));
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

