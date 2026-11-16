<?php

namespace Idno\Entities\Reader {

    use Idno\Common\Entity;

    #[\Idno\Storage\Attribute\AsEntity('feed_subscription', collection: 'reader')]
    class Subscription extends Entity
    {

        public static $retrieve_collection = 'reader';
        public $collection = 'reader';

        /**
         * Get a user's subscriptions
         *
         * @param  $user
         * @return array
         */
        static function getByUser($user)
        {
            return Subscription::get(array('owner' => $user->getUUID()));
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
         * Returns the feed associated with this subscription
         *
         * @return bool|false|Entity|Feed
         */
        function getFeedObject()
        {
            if ($feed_url = $this->getFeedURL()) {
                return Feed::getOne(array('feed_url' => $feed_url));
            }

            return false;
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

    }

}

