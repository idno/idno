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
 *  - the handles of accounts that were deleted are RECORDS of their own (Idno\Entities\ReservedHandle), looked up only when an account is created or renamed and
 *    on the Users page, never loaded with every request. A reserved handle is "taken" for a registration, an administrator creating a user, the installer's administrator step and a change of handle, until an administrator releases it on the
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

    /// The most accounts that can be closed at once: each adds a value to the exclusion every visitor's listing carries, and a statement can hold only so many
    public const MAX_CLOSED = 500;

    /**
     * Whether no more accounts can be closed. A full set is cleaned first (pruneDead()), so entries of accounts that are gone can never keep it full.
     */
    public static function closedIsFull(): bool
    {
        if (count(self::closed()) < self::MAX_CLOSED) {
            return false;
        }
        try {
            self::pruneDead();
        } catch (\Throwable $e) {
            Idno::site()->logging()->warning('The stored set of closed accounts could not be cleaned: ' . get_class($e));
        }

        return count(self::closed()) >= self::MAX_CLOSED;
    }

    /**
     * Drops from the stored set the uuids that have no account record (an account that was deleted whose uuid could not be taken out at the time), so a stale
     * entry heals itself.
     */
    public static function pruneDead(): void
    {
        self::change(static function (Config $config): void {
            $config->closed_accounts = self::alive(self::listOf($config->closed_accounts));
        });
    }

    /**
     * @param  list<string> $uuids
     * @return list<string> those that have a record (read from the store, whoever is asking: at most MAX_CLOSED lookups, made only when the set is written)
     */
    private static function alive(array $uuids): array
    {
        $store = Idno::container()->get(\Idno\Storage\RecordStore::class);

        return array_values(array_filter($uuids, static fn (string $uuid): bool => $store->findByUuid('entities', $uuid) !== null));
    }

    /**
     * Adds the uuid to the stored set, against a fresh read of the settings. Throws when the settings could not be saved: the set is the guarantee that a closed
     * account's posts are in no listing, so a failure to write it is a failure, never ignored.
     */
    public static function markClosed(string $uuid): void
    {
        self::change(static function (Config $config) use ($uuid): void {
            $closed = self::listOf($config->closed_accounts);
            if (!in_array($uuid, $closed, true)) {
                $closed[] = $uuid;
            }
            $config->closed_accounts = self::alive($closed);
        });
    }

    public static function unmarkClosed(string $uuid): void
    {
        self::change(static function (Config $config) use ($uuid): void {
            $config->closed_accounts = self::alive(array_values(array_diff(self::listOf($config->closed_accounts), [$uuid])));
        });
    }

    /**
     * The handle as handles are compared: trimmed, lower case, accents and the case rules of the database's text comparison folded.
     */
    public static function normaliseHandle(string $handle): string
    {
        $handle = mb_strtolower(trim($handle), 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $handle = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($handle, \Normalizer::FORM_KD));
        }

        return str_replace('ß', 'ss', $handle);
    }

    public static function handleIsReserved(mixed $handle): bool
    {
        return is_string($handle) && $handle !== '' && \Idno\Entities\ReservedHandle::forKey(self::normaliseHandle($handle)) !== false;
    }

    /**
     * Whether an account of this uuid was deleted (its handle is reserved): a message queued to run as it is dropped, not failed.
     */
    public static function isDeletedAccount(string $uuid): bool
    {
        return \Idno\Entities\ReservedHandle::hasDeletedAccount($uuid);
    }

    public static function reserveHandle(string $handle, string $uuid): void
    {
        $key = self::normaliseHandle($handle);
        if ($key === '' || \Idno\Entities\ReservedHandle::forKey($key) !== false) {
            return;
        }
        $reserved = new \Idno\Entities\ReservedHandle();
        $reserved->handle = $key;
        $reserved->display = $handle;
        $reserved->account_uuid = $uuid;
        $reserved->owner = '';
        // A slug of its own: the handle as a slug would take the slug of the next article with that title ("news" deleted, the next "News" is "news-1")
        $reserved->slug = 'reserved-handle-' . bin2hex(random_bytes(8));
        if ($reserved->save(true) === false) {
            throw new \RuntimeException('The handle could not be reserved.');
        }
    }

    /**
     * Gives a reserved handle back. Whether it was reserved.
     */
    public static function releaseHandle(string $handle): bool
    {
        $reserved = \Idno\Entities\ReservedHandle::forKey(self::normaliseHandle($handle));
        if ($reserved === false) {
            return false;
        }
        \Idno\Entities\ReservedHandle::unrestricted(static fn () => $reserved->delete());

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
        if (!$config->save()) {
            throw new \RuntimeException('The settings could not be saved.');
        }
        $timezone = $config->config['timezone'] ?? null;
        if (is_string($timezone) && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($timezone);
        }
    }
}
