<?php

namespace Idno\Entities\Reader {

    use Idno\Common\Entity;
    use Idno\Core\Webservice;

    #[\Idno\Storage\Attribute\AsEntity('feed', collection: 'reader')]
    #[\Idno\Storage\Attribute\IndexedField('feed_url')]
    class Feed extends Entity
    {

        public $collection = 'reader';
        public static $retrieve_collection = 'reader';

        /**
         * Sets the URL of this feed
         *
         * @param $url
         */
        function setURL($url)
        {
            $this->url = $url;
        }

        /**
         * Sets the URL of the feed this subscription belongs to
         *
         * @param $url
         */
        function setFeedURL($url)
        {
            $this->feed_url = $url;
        }

        /**
         * Retrieves the URL of the feed this subscription belongs to
         *
         * @param  $url
         * @return mixed
         */
        function getFeedURL()
        {
            return $this->feed_url;
        }

        /**
         * Set the type of this feed
         *
         * @param $type
         */
        function setType($type)
        {
            $this->feed_type = $type;
        }

        /**
         * Get the type of this feed
         *
         * @return mixed
         */
        function getType()
        {
            return $this->feed_type;
        }

        /**
         * Retrieves and parses this feed
         *
         * @return array|bool
         */
        function fetchAndParse()
        {
            return \Idno\Core\Idno::site()->reader()->fetchAndParseFeed($this->getFeedURL());
        }

        /**
         * Get parsed items from this feed
         *
         * @return array|bool
         */
        function retrieveItems()
        {
            if ($content = Webservice::get($this->getFeedURL())) {
                return \Idno\Core\Idno::site()->reader()->parseFeed($content['content'], $this->getFeedURL());
            }

            return false;
        }

        /**
         * Sets the time that this item was last updated
         *
         * @param $time
         */
        function setLastUpdated($time)
        {
            $this->updated = (int)$time;
        }

    }

}

