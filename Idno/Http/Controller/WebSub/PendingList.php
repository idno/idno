<?php

declare(strict_types=1);

namespace Idno\Http\Controller\WebSub;

/**
 * The subscriptions a member is waiting for a hub to verify, stored on the member (`pubsub_pending`): an object with the lists `subscribe` (the uuids of the
 * users they asked a hub to subscribe them to) and `unsubscribe` (their ids), and `at`, the time each entry was added, by `<mode>:<entry>`. An entry is
 * pending for `LEASE_SECONDS`: a hub that verifies later is refused, so an entry that nobody confirmed cannot be confirmed for ever. An entry with no time
 * (one an earlier release stored) is pending until it is verified or the member follows or unfollows again.
 *
 * The one place that reads the stored value, whichever code asks: only an object of the one class is accepted, and what is not one (nothing, text, the plain
 * array an earlier release stored after a verification, an object of another class) is a new, empty object. Static and pure; the clock is an argument.
 */
final class PendingList
{
    /** How long an entry is pending: a day. */
    public const LEASE_SECONDS = 86400;

    public static function read(mixed $stored): \stdClass
    {
        if (!is_string($stored) || $stored === '') {
            return new \stdClass();
        }
        $pending = @unserialize($stored, ['allowed_classes' => [\stdClass::class]]);

        return $pending instanceof \stdClass ? $pending : new \stdClass();
    }

    /**
     * Adds an entry (once), starts the list when it is missing, and drops the entries of both lists that have expired.
     */
    public static function add(\stdClass $pending, string $mode, string $entry, int $now): void
    {
        self::dropExpired($pending, $now);
        if (!isset($pending->$mode) || !is_array($pending->$mode)) {
            $pending->$mode = [];
        }
        if (!in_array($entry, $pending->$mode, true)) {
            $pending->{$mode}[] = $entry;
        }
        $at = self::times($pending);
        $at[$mode . ':' . $entry] = $now;
        $pending->at = $at;
    }

    /**
     * Whether one of the entries is in the mode's list and has not expired.
     *
     * @param list<string> $entries
     */
    public static function isPending(\stdClass $pending, string $mode, array $entries, int $now): bool
    {
        if (!isset($pending->$mode) || !is_array($pending->$mode)) {
            return false;
        }
        $at = self::times($pending);
        foreach ($pending->$mode as $entry) {
            if (is_string($entry) && in_array($entry, $entries, true) && !self::expired($at[$mode . ':' . $entry] ?? null, $now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes the entries from the mode's list, and their times.
     *
     * @param list<string> $entries
     */
    public static function remove(\stdClass $pending, string $mode, array $entries): void
    {
        $list = isset($pending->$mode) && is_array($pending->$mode) ? $pending->$mode : [];
        $pending->$mode = array_values(array_filter($list, static fn (mixed $entry): bool => !in_array($entry, $entries, true)));
        $at = self::times($pending);
        foreach ($entries as $entry) {
            unset($at[$mode . ':' . $entry]);
        }
        if (isset($pending->at) || $at !== []) {
            $pending->at = $at;
        }
    }

    private static function dropExpired(\stdClass $pending, int $now): void
    {
        $at = self::times($pending);
        foreach (['subscribe', 'unsubscribe'] as $mode) {
            if (!isset($pending->$mode) || !is_array($pending->$mode)) {
                continue;
            }
            $pending->$mode = array_values(array_filter($pending->$mode, static fn (mixed $entry): bool => !is_string($entry) || !self::expired($at[$mode . ':' . $entry] ?? null, $now)));
            foreach ($at as $key => $time) {
                if (str_starts_with($key, $mode . ':') && self::expired($time, $now)) {
                    unset($at[$key]);
                }
            }
        }
        if ($at !== [] || isset($pending->at)) {
            $pending->at = $at;
        }
    }

    /** @return array<string, int> */
    private static function times(\stdClass $pending): array
    {
        $at = $pending->at ?? null;

        return is_array($at) ? array_filter($at, 'is_int') : [];
    }

    private static function expired(mixed $time, int $now): bool
    {
        return is_int($time) && $now - $time > self::LEASE_SECONDS;
    }
}
