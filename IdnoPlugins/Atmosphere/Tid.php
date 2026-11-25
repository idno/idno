<?php

namespace IdnoPlugins\Atmosphere;

/**
 * AT Protocol timestamp identifiers (TIDs), the record keys of app.bsky.feed.post
 * records (https://atproto.com/specs/tid): a 64-bit number written as 13 characters
 * of "base32-sortable". The top bit is 0, the next 53 bits are microseconds since
 * the UNIX epoch and the last 10 bits are a clock identifier.
 *
 * A post's key is worked out from the post itself (when it was made and its id), so
 * that writing the same post twice writes the same record and never a second one.
 */
class Tid
{
    const ALPHABET = '234567abcdefghijklmnopqrstuvwxyz';

    /// The syntax the specification gives
    const PATTERN = '/\A[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}\z/';

    /**
     * @param  int $microseconds Microseconds since the UNIX epoch
     * @param  int $clockId      0 to 1023
     * @return string
     */
    public static function fromMicroseconds(int $microseconds, int $clockId): string
    {
        $value = (($microseconds & ((1 << 53) - 1)) << 10) | ($clockId & 1023);

        $tid = '';
        for ($shift = 60; $shift >= 0; $shift -= 5) {
            $tid .= self::ALPHABET[($value >> $shift) & 31];
        }

        return $tid;
    }

    /**
     * The key for the record of an entity: always the same for the same entity, and
     * different for two entities made in the same second.
     *
     * @param  int    $created  When the entity was made (seconds since the UNIX epoch)
     * @param  string $entityId The entity's id
     * @return string
     */
    public static function forEntity(int $created, string $entityId): string
    {
        $hash = hexdec(substr(hash('sha256', $entityId), 0, 12));

        return self::fromMicroseconds(max(0, $created) * 1000000 + ($hash % 1000000), intdiv($hash, 1000000) % 1024);
    }

    /**
     * @param  mixed $tid
     * @return bool
     */
    public static function isValid($tid): bool
    {
        return is_string($tid) && preg_match(self::PATTERN, $tid) === 1;
    }

    /**
     * The microseconds a TID holds.
     *
     * @param  string $tid A valid TID
     * @return int
     */
    public static function microseconds(string $tid): int
    {
        $value = 0;
        for ($i = 0; $i < 13; $i++) {
            $value = ($value << 5) | strpos(self::ALPHABET, $tid[$i]);
        }

        return $value >> 10;
    }
}
