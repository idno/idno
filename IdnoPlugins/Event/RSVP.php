<?php

namespace IdnoPlugins\Event {

    #[\Idno\Storage\Attribute\AsEntity('rsvp')]
    class RSVP extends \Idno\Common\Entity
    {

        function getTitle()
        {
            if (!empty($this->body)) {
                return ucfirst($this->rsvp) . ': ' . $this->body;
            }
            return '';
        }

        function getDescription()
        {
            $body = $this->body;
            if (!empty($this->inreplyto)) {
                $body = '<a href="'.$this->inreplyto.'" class="u-in-reply-to"></a>' . $body;
            }
            if (!empty($this->rsvp)) {
                $body = '<data class="p-rsvp" value="'.$this->rsvp.'">' . $body . '</data>';
            }
            return $body;
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
                return \Idno\Core\Idno::site()->config()->url . 'rsvp/' . $this->getID() . '/';
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
            return 'rsvp';
        }

        /**
         * Event objects show up as h-event in a Microformats stream
         * @return string
         */
        function getMicroformats2ObjectType()
        {
            return 'h-entry';
        }

        function getMetadataForFeed()
        {
            return array(
                'type' => 'rsvp',
                'rsvp' => $this->rsvp,
                'in-reply-to' => $this->inreplyto
            );
        }

        function saveDataFromInput()
        {

            if (empty($this->_id)) {
                $new = true;
            } else {
                $new = false;
            }
            $body = \Idno\Core\Idno::site()->currentPage()->getInput('body');
            $rsvp = \Idno\Core\Idno::site()->currentPage()->getInput('rsvp');
            $access = \Idno\Core\Idno::site()->currentPage()->getInput('access');
            if (!empty($rsvp)) {
                $this->body = $body;
                $rsvp = strtolower($rsvp);
                if ($rsvp != 'yes' && $rsvp != 'maybe') {
                    $rsvp = 'no';
                }
                $this->rsvp = $rsvp;
                $this->inreplyto = \Idno\Core\Idno::site()->currentPage()->getInput('inreplyto');
                $this->setAccess($access);
                if ($this->publish($new)) {
                    if ($access == 'PUBLIC') {
                        \Idno\Core\Webmention::pingMentions($this->getURL(), \Idno\Core\Idno::site()->template()->parseURLs($this->getDescription()));
                    }
                    \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_('Your RSVP was successfully saved.'));
                    return true;
                }
            } else {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('You can\'t save an RSVP with no status.'));
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
