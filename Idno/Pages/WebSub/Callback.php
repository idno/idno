<?php

    /**
     * WebSub callback: where hubs verify a subscription and deliver what was published
     */

namespace Idno\Pages\WebSub {

    /**
     * Class to receive pings from WebSub hubs
     */
    class Callback extends \Idno\Common\Page
    {

        function getContent()
        {
            if (!empty($this->arguments[0])) {
                $subscriber = \Idno\Common\Entity::getByID($this->arguments[0]);
            }
            if (empty($subscriber)) {
                $this->goneContent();
            }
            if (!empty($this->arguments[1])) {
                $subscription = \Idno\Common\Entity::getByID($this->arguments[1]);
            }
            if (empty($subscription)) {
                $this->goneContent();
            }

            $hub_mode          = $this->getInput('hub.mode', $this->getInput('hub_mode'));
            $hub_topic         = $this->getInput('hub.topic', $this->getInput('hub_topic'));
            $hub_challenge     = $this->getInput('hub.challenge', $this->getInput('hub_challenge'));
            $hub_lease_seconds = $this->getInput('hub.lease_seconds', $this->getInput('hub_lease_seconds'));

            \Idno\Core\Idno::site()->logging()->debug("WebSub: $hub_mode verification ping ");

            switch ($hub_mode) {
                case 'subscribe':
                case 'unsubscribe':
                    $pending = unserialize($subscriber->pubsub_pending);

                    // Check whether the intent is valid
                    if (is_array($pending->$hub_mode) && in_array($subscription->getUUID(), $pending->$hub_mode)) {
                        $new = array();
                        foreach ($pending->$hub_mode as $value) {
                            $new[] = $value;
                        }

                        $subscriber->pubsub_pending = serialize($new);
                        $subscriber->save();

                        \Idno\Core\Idno::site()->logging()->debug("WebSub: $hub_challenge");

                        // The challenge is taken from the request: answer in plain text, and
                        // tell browsers not to guess another type, so it is never run as HTML
                        header('Content-Type: text/plain; charset=utf-8');
                        header('X-Content-Type-Options: nosniff');
                        echo $hub_challenge;
                        exit;
                    }
                    break;
            }

            $this->deniedContent();
        }

        function post()
        {
            \Idno\Core\Idno::site()->logging()->debug("WebSub: Ping received");

            // Since we've overloaded post, we need to parse the arguments
            $arguments = func_get_args();
            if (!empty($arguments)) {
                $this->arguments = $arguments;
            }

            // Find users
            if (!empty($this->arguments[0])) {
                $subscriber = \Idno\Common\Entity::getByID($this->arguments[0]);
            }
            if (empty($subscriber) || (!($subscriber instanceof \Idno\Entities\User))) {
                $this->goneContent();
            }
            if (!empty($this->arguments[1])) {
                $subscription = \Idno\Common\Entity::getByID($this->arguments[1]);
            }
            if (empty($subscription) || (!($subscription instanceof \Idno\Entities\User))) {
                $this->goneContent();
            }

            \Idno\Core\Idno::site()->logging()->debug("WebSub: Ping received, pinging out...");

            \Idno\Core\Idno::site()->events()->triggerEvent(
                \Idno\Core\WebSub::PING_EVENT, array(
                'subscriber'   => $subscriber,
                'subscription' => $subscription,
                'data'         => trim(file_get_contents("php://input"))
                )
            );

        }

    }

    // The class's name before the protocol was renamed. Deprecated: use \Idno\Pages\WebSub\Callback.
    class_alias('Idno\\Pages\\WebSub\\Callback', 'Idno\\Pages\\Pubsubhubbub\\Callback');

}
