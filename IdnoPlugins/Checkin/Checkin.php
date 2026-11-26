<?php

namespace IdnoPlugins\Checkin {

    #[\Idno\Storage\Attribute\AsEntity('checkin')]
    class Checkin extends \Idno\Common\Entity
        implements \Idno\Common\JSONLDSerialisable
    {

        /// How long the location of a check-in is protected for, in seconds
        const PROTECTED_FOR = 86400;

        /// The decimal places of a position that a viewer who may not see the exact one is given (about a kilometre)
        const VAGUE_DECIMALS = 2;

        /**
         * The title for whoever is signed in for this request
         */
        function getTitle()
        {
            return $this->titleFor($this->location());
        }

        /**
         * The title for a visitor who is not signed in: the one for the check-in's address, the one
         * that is stored (which search looks in) and for anything sent to another server.
         */
        function getPublicTitle()
        {
            return $this->titleFor($this->publicLocation());
        }

        /**
         * @param  array $location What locationFor() returned for the reader
         * @return string
         */
        protected function titleFor(array $location)
        {
            return \Idno\Core\Idno::site()->language()->_('Checked into %s', [
                $location['placename'] !== '' ? $location['placename'] : \Idno\Core\Idno::site()->language()->_('somewhere near...')
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
            $location = $this->location();

            return array(
                'type' => 'checkin',
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
                'placename' => $location['placename'],
                'address' => $location['address']
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
         * Is the location of this check-in being kept from visitors now? It is when its
         * author asked for that ("Protect my location for 24 hours") and the check-in is less
         * than 24 hours old. This says nothing about who is asking: see locationFor().
         */
        function isLocationProtected() : bool
        {
            if (!$this->isAnonymous()) {
                return false;
            }
            // A check-in that has not been saved yet is made now
            if (empty($this->created)) {
                return true;
            }
            $created = is_numeric($this->created) ? (int) $this->created : (int) strtotime((string) $this->created);

            return $created >= time() - self::PROTECTED_FOR;
        }

        /**
         * The page of a check-in whose location was protected changes when the protection ends, with no save: from then on a visitor is told the place.
         */
        function lastChangedAt(): int
        {
            $changed = parent::lastChangedAt();
            if ($this->isAnonymous() && !empty($this->created) && !$this->isLocationProtected()) {
                $created = is_numeric($this->created) ? (int) $this->created : (int) strtotime((string) $this->created);
                $changed = max($changed, $created + self::PROTECTED_FOR);
            }

            return $changed;
        }

        /**
         * What a viewer may be told about where this check-in was made. This is the one rule
         * for every form of a check-in: its title and address, its templates, feeds, JSON, KML,
         * Markdown and what other servers get. Nothing else reads lat, long, placename or
         * address, but the edit form.
         *
         * While the location is protected (isLocationProtected()), a viewer who is not signed
         * in, and so any other server, gets no place name, no address, and a position that is
         * off by up to about a kilometre and the same every time it is asked for. A member who
         * is signed in gets everything, as everybody does once the protection is over.
         *
         * @param  \Idno\Entities\User|null $viewer A member, or null for a visitor who is not signed in
         * @return array 'precise', 'latitude', 'longitude' (floats, or null), 'placename', 'address' (strings)
         */
        function locationFor($viewer = null): array
        {
            $latitude  = self::isCoordinate($this->lat) ? (float) $this->lat : null;
            $longitude = self::isCoordinate($this->long) ? (float) $this->long : null;

            if ($this->isLocationProtected() && !($viewer instanceof \Idno\Entities\User)) {
                return [
                    'precise'   => false,
                    'latitude'  => $latitude === null ? null : $this->reducePrecision($latitude),
                    'longitude' => $longitude === null ? null : $this->reducePrecision($longitude),
                    'placename' => '',
                    'address'   => '',
                ];
            }

            return [
                'precise'   => true,
                'latitude'  => $latitude,
                'longitude' => $longitude,
                'placename' => is_string($this->placename) ? $this->placename : '',
                'address'   => is_string($this->address) ? $this->address : '',
            ];
        }

        /**
         * Make a latitude or a longitude inexact. The result depends on nothing but the value:
         * it was once a random one for each request, and the average of enough requests was
         * the exact position.
         *
         * @param  float $location
         * @return float
         */
        protected function reducePrecision(float $location) : float
        {
            $vague = round($location, self::VAGUE_DECIMALS);
            if ($vague === $location) {
                // Never the exact position, not even for a place that lies on the grid
                $vague += 10 ** -self::VAGUE_DECIMALS / 2;
            }

            return $vague;
        }

        /**
         * May whoever is signed in for this request see where exactly this check-in was made?
         */
        function canSeePreciseLocation() : bool
        {
            return $this->location()['precise'];
        }

        /**
         * Whether a stored latitude or longitude is a number: one that is not (a value a member or another site sent with
         * the check-in) is no coordinate, so that the map script of the post can only ever hold a number.
         */
        private static function isCoordinate($value) : bool
        {
            return !empty($value) && is_numeric($value) && is_finite((float) $value);
        }

        function lat() : ?float
        {
            return $this->location()['latitude'];
        }

        function long() : ?float
        {
            return $this->location()['longitude'];
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
                $this->body      = \Idno\Core\Idno::site()->template()->sanitize_html($body);
                $this->address   = $user_address;
                $this->setAccess($access);
                $this->tags = $tags;
                $this->anonymity = ($anonymity == 'Yes' ? 'Yes' : false);
                // The stored title is what search looks in, so it names the place only when
                // a visitor may be told the place
                $this->title     = $this->getPublicTitle();

                $publish_status = \Idno\Core\Idno::site()->currentPage()->getInput('publish_status', 'published');

                if ($publish_status === 'draft') {
                    if ($this->saveAsDraft()) {
                        return true;
                    }
                } else {
                    $this->setPublishStatus('published');
                    if ($this->publish($new)) {
                        if ($new && $access == 'PUBLIC') {
                            \Idno\Core\Webmention::queueMentions($this->getURL(), \Idno\Core\Idno::site()->template()->parseURLs($this->getPublicTitle() . ' ' . $this->getDescription()));
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
            if ($this->announcesMentions()) {
                \Idno\Core\Webmention::queueMentions($this->getURL(), \Idno\Core\Idno::site()->template()->parseURLs($this->getPublicTitle() . ' ' . $this->getDescription()));
            }
        }

        public function jsonLDSerialise(array $params = array())
        {

            $location = $this->location();
            $place    = ['@type' => 'Place'];
            if ($location['address'] !== '') {
                $place['address'] = $location['address'];
            }
            if ($location['placename'] !== '') {
                $place['name'] = $location['placename'];
            }
            $place['geo'] = [
                "@type"     => "GeoCoordinates",
                "latitude"  => $location['latitude'],
                "longitude" => $location['longitude']
            ];

            $json = [
                "@context" => "http://schema.org",
                "@type" => "CheckInAction",
                'agent' => [
                    "@type" => "Person",
                    "name" => $this->getOwner()->getName()
                ],
                'location' => $place,
            ];

            return $json;
        }

    }

}
