<?php

declare(strict_types=1);

namespace Idno\Http\Controller\WebSub;

use Idno\Common\Entity;
use Idno\Entities\User;

/**
 * What the WebSub callback asks the site: the members and entities a callback address names, the list of subscriptions a member is waiting for a hub to
 * verify, the events a delivery is handed to and the log. Implemented over the legacy application by `Idno\Legacy\LegacySubscriptions`.
 */
interface Subscriptions
{
    /** The member with this id, or null (no entity, or one that is no member). */
    public function user(string $id): ?User;

    /** The entity with this id, or null. */
    public function entity(string $id): ?Entity;

    /** The topic the site asked a hub to subscribe the subscription's followers to (the feed it is subscribed to), or '' when there is none. */
    public function topic(Entity $subscription): string;

    /** Whether the subscriber follows the subscription and the subscription has a hub: the only deliveries there is anyone to receive. */
    public function isSubscribed(User $subscriber, User $subscription): bool;

    /** The secret the site sent the hub for the subscriber's subscription to the subscription's feed, or '' when it sent none (it is never printed or logged). */
    public function secret(User $subscriber, User $subscription): string;

    /** The time now, as a Unix timestamp. */
    public function now(): int;

    /**
     * The subscriber's pending subscriptions, read by `PendingList::read()`: an object with the lists `subscribe` and `unsubscribe`, a new, empty one when
     * what is stored is none.
     */
    public function pending(User $subscriber): \stdClass;

    public function savePending(User $subscriber, \stdClass $pending): void;

    /** @param array<string, mixed> $data */
    public function trigger(string $event, array $data): void;

    /** The most a delivery may hold, in bytes: the limit of what the site downloads (`webservice_max_download`). */
    public function maxBody(): int;

    /** Writes a debug line. The message must hold nothing the request supplied that has not been made safe. */
    public function log(string $message): void;
}
