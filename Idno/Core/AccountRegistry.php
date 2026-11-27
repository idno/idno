<?php

declare(strict_types=1);

namespace Idno\Core;

/**
 * What the site keeps about accounts that are being deleted or are gone, in its stored settings (so that reading it costs no statement: the
 * settings are loaded with every request):
 *
 *  - `closed_accounts`: the uuids of the accounts that are closed (User::close()) and not yet deleted. EntityRepository leaves the records these own out of every
 *    listing and count for everyone but an administrator (AccessContext::closedOwners()), and Entity::canRead() asks it. The set is small, and an uuid is in it
 *    from the moment the account is closed until its record is deleted.
 *  - `reserved_handles`: the handles of accounts that were deleted, each with the account's uuid and the time. A reserved handle is "taken" for a
 *    registration, an administrator creating a user, the installer's administrator step and a change of handle, until an administrator releases it on the
 *    Users page. So a newcomer can neither inherit the old member's annotations (an annotation is tied to its author by the profile address, which is made
 *    of the handle) nor be taken for the old member's ActivityPub actor.
 *
 * Every change reads the stored settings again first and sets only its own key, as the export's state does: a queue worker's configuration object is as old as
 * the worker, and must never write back a setting an administrator has changed since.
 */
final class AccountRegistry
{
    /**
     * @return list<string>
     */
    public static function closed(): array
    {
        $closed = Idno::site()->config()->closed_accounts;

        return is_array($closed) ? array_values(array_filter($closed, 'is_string')) : [];
    }

    public static function isClosed(mixed $uuid): bool
    {
        return is_string($uuid) && $uuid !== '' && in_array($uuid, self::closed(), true);
    }

    public static function markClosed(string $uuid): void
    {
        self::change(static function (Config $config) use ($uuid): void {
            $closed = self::listOf($config->closed_accounts);
            if (!in_array($uuid, $closed, true)) {
                $closed[] = $uuid;
            }
            $config->closed_accounts = $closed;
        });
    }

    public static function unmarkClosed(string $uuid): void
    {
        self::change(static function (Config $config) use ($uuid): void {
            $config->closed_accounts = array_values(array_diff(self::listOf($config->closed_accounts), [$uuid]));
        });
    }

    /**
     * The handle as handles are compared: trimmed, lower case, accents and the case rules of the database's text comparison folded.
     */
    public static function normaliseHandle(string $handle): string
    {
        $handle = mb_strtolower(trim($handle), 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $handle = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($handle, \Normalizer::FORM_D));
        }

        return str_replace('ß', 'ss', $handle);
    }

    /**
     * @return array<string, array{handle: string, uuid: string, at: int}> by normalised handle
     */
    public static function reservedHandles(): array
    {
        $reserved = [];
        $stored = Idno::site()->config()->reserved_handles;
        foreach (is_array($stored) ? $stored : [] as $key => $entry) {
            if (is_array($entry) && is_string($entry['handle'] ?? null)) {
                $reserved[(string) $key] = ['handle' => $entry['handle'], 'uuid' => (string) ($entry['uuid'] ?? ''), 'at' => (int) ($entry['at'] ?? 0)];
            }
        }

        return $reserved;
    }

    public static function handleIsReserved(mixed $handle): bool
    {
        return is_string($handle) && $handle !== '' && isset(self::reservedHandles()[self::normaliseHandle($handle)]);
    }

    /**
     * Whether an account of this uuid was deleted (its handle is reserved): a message queued to run as it is dropped, not failed.
     */
    public static function isDeletedAccount(string $uuid): bool
    {
        foreach (self::reservedHandles() as $entry) {
            if ($entry['uuid'] === $uuid && $uuid !== '') {
                return true;
            }
        }

        return false;
    }

    public static function reserveHandle(string $handle, string $uuid): void
    {
        if (self::normaliseHandle($handle) === '') {
            return;
        }
        self::change(static function (Config $config) use ($handle, $uuid): void {
            $reserved = is_array($config->reserved_handles) ? $config->reserved_handles : [];
            $reserved[self::normaliseHandle($handle)] = ['handle' => $handle, 'uuid' => $uuid, 'at' => time()];
            $config->reserved_handles = $reserved;
        });
    }

    /**
     * Gives a reserved handle back. Whether it was reserved.
     */
    public static function releaseHandle(string $handle): bool
    {
        $key = self::normaliseHandle($handle);
        if (!isset(self::reservedHandles()[$key])) {
            return false;
        }
        self::change(static function (Config $config) use ($key): void {
            $reserved = is_array($config->reserved_handles) ? $config->reserved_handles : [];
            unset($reserved[$key]);
            $config->reserved_handles = $reserved;
        });

        return true;
    }

    /**
     * @return list<string>
     */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * Reads the stored settings again, applies the change to them and saves (the defaults and then the stored settings over them, which is what
     * Config::save() leaves too, so the site's time zone is put back).
     */
    private static function change(callable $change): void
    {
        $config = Idno::site()->config();
        $config->init();
        $config->load();
        $change($config);
        $config->save();
        $timezone = $config->config['timezone'] ?? null;
        if (is_string($timezone) && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($timezone);
        }
    }
}
