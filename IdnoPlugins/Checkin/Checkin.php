<?php

namespace IdnoPlugins\Checkin {

    class Checkin extends \Idno\Common\Entity
        implements \Idno\Common\JSONLDSerialisable
    {

        // Cache lat/long so same random result appears between calls on the same page
        private $_lat;
        private $_lng;

        function getTitle()
        {
            return \Idno\Core\Idno::site()->language()->_('Checked into %s', [
                $this->canSeePreciseLocation() && $this->placename ? $this->placename : \Idno\Core\Idno::site()->language()->_('somewhere near...')
            ]);
        }

        function getDescription()
        {
            if (empty($this->body)) {
                return ' ';
            }
            return $this->body;
        }

        function getMetadataForFeed()
        {
            return array(
                'type' => 'checkin',
                'latitude' => $this->lat,
                'longitude' => $this->long,
                'placename' => $this->placename,
                'address' => $this->address
            );
        }

        /**
         * Status objects have type 'note'
         * @return 'note'
         */
        function getActivityStreamsObjectType()
        {
            return 'place';
        }

        function isAnonymous() : bool
        {
            return $this->anonymity == 'Yes';
        }

        /**
         * Reduce the precision of a lat/long dimension by rounding it off and adding some jitter.
         * @param float $location
         * @return float
         */
        protected function reducePrecision(float $location) : float
        {

            // Add some jitter
            $jitter = rand(-100, 100);
            $jitter = (float)($jitter /  10000);

            return $location + $jitter;
        }

        function canSeePreciseLocation() : bool
        {

            if (!$this->isAnonymous()) return true; // This isn't anonymous

            if ($this->created < time() - (60*60*24)) return true; // Or it's older than 24 hours

            if (\Idno\Core\Idno::site()->session()->currentUser()) return true; // Or we're logged in

            return false; // Otherwise we add some jitter.
        }

        function lat() : ?float
        {
            if (!empty($this->_lat)) return $this->_lat;

            if (!empty($this->lat)) {

                if ($this->canSeePreciseLocation()) {
                    $this->_lat = $this->lat;
                } else {
                    $this->_lat = $this->reducePrecision($this->lat);
                }

                return $this->lat();
            }

            return null;
        }


        function long() : ?float
        {

            if (!empty($this->_lng)) return $this->_lng;

            if (!empty($this->long)) {

                if ($this->canSeePreciseLocation()) {
                    $this->_lng = $this->long;
                } else {
                    $this->_lng = $this->reducePrecision($this->long);
                }

                return $this->long();
            }

            return null;
        }

        function jsonSerialize(): mixed
        {
            $object = parent::jsonSerialize();

            $object['latitude'] = (string)$this->lat();
            $object['longitude'] = (string)$this->long();

            return $object;
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
            $body         = \Idno\Core\Idno::site()->currentPage()->getInput('body');
            $tags         = \Idno\Core\Idno::site()->currentPage()->getInput('tags');
            $lat          = \Idno\Core\Idno::site()->currentPage()->getInput('lat');
            $long         = \Idno\Core\Idno::site()->currentPage()->getInput('long');
            $user_address = \Idno\Core\Idno::site()->currentPage()->getInput('user_address');
            $placename    = \Idno\Core\Idno::site()->currentPage()->getInput('placename');
            $tags         = \Idno\Core\Idno::site()->currentPage()->getInput('tags');
            $access       = \Idno\Core\Idno::site()->currentPage()->getInput('access');
            $anonymity       = \Idno\Core\Idno::site()->currentPage()->getInput('anonymity');

            if ($time = \Idno\Core\Idno::site()->currentPage()->getInput('created')) {
                if ($time = strtotime($time)) {
                    $this->created = $time;
                }
            }

            if (!empty($lat) && !empty($long)) {
                $this->lat       = $lat;
                $this->long      = $long;
                $this->placename = $placename;
                $this->title     = \Idno\Core\Idno::site()->language()->_('Checked into %s', [$placename]);
                $this->body      = \Idno\Core\Idno::site()->template()->sanitize_html($body);
                $this->address   = $user_address;
                $this->setAccess($access);
                $this->tags = $tags;
                $this->anonymity = ($anonymity == 'Yes' ? 'Yes' : false);

                $publish_status = \Idno\Core\Idno::site()->currentPage()->getInput('publish_status', 'published');

                if ($publish_status === 'draft') {
                    if ($this->saveAsDraft()) {
                        return true;
                    }
                } else {
                    $this->setPublishStatus('published');
                    if ($this->publish($new)) {
                        if ($new && $access == 'PUBLIC') {
                            \Idno\Core\Webmention::pingMentions($this->getURL(), \Idno\Core\Idno::site()->template()->parseURLs($this->getTitle() . ' ' . $this->getDescription()));
                        }

                        return true;
                    }
                }
            } else {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('You can\'t save an empty checkin.'));
            }

            return false;

        }

        function deleteData()
        {
            if ($this->getAccess() == 'PUBLIC') {
                \Idno\Core\Webmention::pingMentions($this->getURL(), \Idno\Core\Idno::site()->template()->parseURLs($this->getTitle() . ' ' . $this->getDescription()));
            }
        }

        public function jsonLDSerialise(array $params = array())
        {

            $json = [
                "@context" => "http://schema.org",
                "@type" => "CheckInAction",
                'agent' => [
                    "@type" => "Person",
                    "name" => $this->getOwner()->getName()
                ],
                'location' => [
                    '@type' => 'Place',
                    'address' => $this->address,
                    'name' => $this->placename,
                    "geo" => [
                        "@type" => "GeoCoordinates",
                        "latitude" => $this->lat(),
                        "longitude" => $this->long()
                    ],
                ],
            ];

            return $json;
        }

    }

}
