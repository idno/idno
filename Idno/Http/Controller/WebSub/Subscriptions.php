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

    /**
     * The subscriber's pending subscriptions: an object with a list `subscribe` of the uuids of the users they asked a hub to subscribe them to, and a list
     * `unsubscribe` of the ids of those they asked it to unsubscribe them from. A new, empty object when none is stored or what is stored is not an
     * object of that class (no other class is ever unserialised).
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
