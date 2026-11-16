<?php

declare(strict_types=1);

namespace Idno\Upgrade;

/**
 * A message about the upgrade that may be shown to anyone: the upgrade page and the step endpoint's
 * JSON are served to anonymous visitors while the site is closed. It can only be made from the fixed
 * templates below, whose parameters are safe by construction: table names (kept only when they are
 * names of LegacyDataCopier::LEGACY_TABLES, under their 1.x name or `legacy_`), counts and version
 * numbers. Anything else (a record's uuid, document data, a driver or exception message, a file path,
 * a connection detail) is never in one: it is private detail, logged on the `upgrade` channel, stored
 * beside the public message (`error_detail`) and shown by `idno:upgrade` only; the public side of such
 * a failure is stopped().
 *
 * A value object, not a container service.
 */
final class PublicMessage
{
    private function __construct(public readonly string $text)
    {
    }

    /**
     * The one sentence for every failure whose detail is private.
     */
    public static function stopped(): self
    {
        return new self('The upgrade stopped. The details are in the server\'s error log, and php bin/console idno:upgrade --status shows them.');
    }

    public static function tooOld(): self
    {
        return new self('This site\'s database is from a version of Idno older than 1.6.4, which this version cannot upgrade directly. Upgrade the site to Idno 1.6.4 first and load it once in a browser; then install this version again. Nothing was changed.');
    }

    /**
     * The three 1.x `entities` tables exist under their renamed names and no upgrade is on record.
     */
    public static function renamedWithoutRecord(): self
    {
        return new self('The tables of an Idno 1.x site exist under their renamed names (legacy_entities, legacy_entities_metadata, legacy_entities_search, ...) but no upgrade is on record. Nothing was changed. To upgrade that site, rename them back to their 1.x names (without the legacy_ prefix); to set up a new site instead, remove them.');
    }

    public static function unreachable(): self
    {
        return new self('The database cannot be reached right now. The upgrade carries on once it can be.');
    }

    /**
     * The database server is below Idno's floor: what is required (ServerVersion::REQUIREMENT) and
     * what was found, from its parts only.
     *
     * @param 'MySQL'|'MariaDB' $product
     * @param string            $version the parsed version numbers (`5.7.44`)
     *
     * @throws \InvalidArgumentException for any other product or a version that is not numbers
     */
    public static function unsupportedServer(string $product, string $version): self
    {
        if (!in_array($product, ['MySQL', 'MariaDB'], true) || preg_match('/^\d{1,4}\.\d{1,4}(\.\d{1,6})?$/', $version) !== 1) {
            throw new \InvalidArgumentException('Not a database server product and version.');
        }

        return new self(ServerVersion::REQUIREMENT . ' This database server is ' . $product . ' ' . $version . '.');
    }

    /**
     * What is required, when what was found is not known.
     */
    public static function serverRequirement(): self
    {
        return new self(ServerVersion::REQUIREMENT);
    }

    public static function noProgress(int $steps): self
    {
        return new self(sprintf('The upgrade made no progress in %d steps in a row. Nothing more was changed.', $steps));
    }

    public static function lockHeld(int $seconds): self
    {
        return new self(sprintf('Another process has been upgrading the database for %d seconds (the upgrade page open in a browser, or another idno:upgrade). Let it finish, or close the page, then run idno:upgrade again.', $seconds));
    }

    public static function inconsistentProgress(): self
    {
        return new self('The upgrade\'s progress rows are inconsistent: some collections are marked ready for the rename and some are not. Nothing was changed.');
    }

    /**
     * @param list<string> $renamed the renamed tables (`legacy_<name>`)
     * @param list<string> $old     the tables still under their 1.x names
     */
    public static function partlyRenamed(array $renamed, array $old): self
    {
        return new self(sprintf(
            'Some 1.x tables are renamed (%s) and some are not (%s): the upgrade renames all of them at once, so this database was changed by something else. Nothing was changed.',
            self::tables($renamed),
            self::tables($old),
        ));
    }

    /**
     * @param list<string> $renamed
     */
    public static function renamedBeforeTheCopy(array $renamed): self
    {
        return new self(sprintf('The 1.x tables are renamed (%s) but the copy had not finished: they were renamed by something else. Nothing was changed.', self::tables($renamed)));
    }

    /**
     * @param list<string> $missing
     */
    public static function vanished(array $missing): self
    {
        return new self(sprintf('The upgrade began with 1.x tables that are no longer there: %s. They must be restored before the upgrade can go on. Nothing was changed.', self::tables($missing)));
    }

    /**
     * @param list<string> $appeared
     */
    public static function appeared(array $appeared): self
    {
        $one = count($appeared) === 1;

        return new self(sprintf(
            'The upgrade began without the 1.x %s, but %s exists now: a process of the old Idno version created it during the upgrade, and its rows were not copied. Stop every process of the old version, then check %s and move or remove %s; the upgrade then goes on. Nothing was changed.',
            $one ? 'table' : 'tables',
            self::tables($appeared),
            $one ? 'it' : 'them',
            $one ? 'it' : 'them',
        ));
    }

    /**
     * @param list<string> $tables the 1.x tables whose `legacy_<name>` exists beside them
     */
    public static function renameBlocked(array $tables): self
    {
        $one = count($tables) === 1;
        $pairs = implode(', ', array_map(static fn (string $table): string => $table . ' and legacy_' . $table, self::names($tables)));

        return new self(sprintf(
            'The 1.x tables cannot be renamed: %s both exist. Nothing was changed. Someone who knows what the legacy_ %s must check %s and move or remove %s; the upgrade then goes on.',
            $pairs,
            $one ? 'table holds' : 'tables hold',
            $one ? 'it' : 'them',
            $one ? 'it' : 'them',
        ));
    }

    /**
     * Table names of a 1.x database (LEGACY_TABLES, under their names or `legacy_`), comma-separated:
     * any other name is left out.
     *
     * @param list<string> $tables
     */
    private static function tables(array $tables): string
    {
        $names = self::names($tables);

        return $names === [] ? 'unknown tables' : implode(', ', $names);
    }

    /**
     * @param list<string> $tables
     *
     * @return list<string>
     */
    private static function names(array $tables): array
    {
        return array_values(array_filter($tables, static function (string $table): bool {
            return in_array($table, LegacyDataCopier::LEGACY_TABLES, true) || in_array(substr($table, 7), LegacyDataCopier::LEGACY_TABLES, true) && str_starts_with($table, 'legacy_');
        }));
    }
}
