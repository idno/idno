<?php

declare(strict_types=1);

namespace Idno\Upgrade;

/**
 * A message about the upgrade that may be shown to anyone: the upgrade page and the step endpoint's
 * JSON are served to anonymous visitors while the site is closed. It can only be made from the fixed
 * templates below, whose parameters are safe by construction: table names (kept only when they are
 * names of LegacyDataCopier::LEGACY_TABLES, under their 1.x name or `legacy_`, or the fixed `idno_`
 * names of LegacyDataCopier::MUST_BE_EMPTY), counts and version numbers. Anything else (a record's uuid, document data, a driver or exception message, a file path,
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
     * The message a schema status carries, the one mapping every entry point uses: the refused states
     * (SchemaStatus::refusesTheUpgrade()) and Unreachable have one; the others none.
     *
     * @param self|null $serverRefusal for UnsupportedServer: what is required and what was found
     *                                 (SchemaState::refusal()); without it, what is required
     */
    public static function forStatus(SchemaStatus $status, ?self $serverRefusal = null): ?self
    {
        return match ($status) {
            SchemaStatus::UnsupportedServer => $serverRefusal ?? self::serverRequirement(),
            SchemaStatus::LegacyTooOld => self::tooOld(),
            SchemaStatus::RenamedWithoutRecord => self::renamedWithoutRecord(),
            SchemaStatus::OneXBesideCompletedUpgrade => self::oneXBesideCompletedUpgrade(),
            SchemaStatus::Unreachable => self::unreachable(),
            SchemaStatus::Empty, SchemaStatus::PendingMigrations, SchemaStatus::Legacy, SchemaStatus::Current, SchemaStatus::NotApplicable => null,
        };
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

    /**
     * SchemaStatus::OneXBesideCompletedUpgrade: a completed upgrade, and the 1.x tables again.
     */
    public static function oneXBesideCompletedUpgrade(): self
    {
        return new self('This database holds a site already upgraded to this version of Idno and, again, the tables of an Idno 1.x site (entities, entities_metadata, entities_search), for example after the upgrade was undone or a backup restored. Nothing was changed. To upgrade the 1.x site again, remove the tables whose names begin with idno_ first. To keep the upgraded site, remove or rename the 1.x tables.');
    }

    /**
     * In the verify pass (the rename has happened), tables under 1.x names exist again: an old Idno
     * process created them. The legacy_ tables hold the site's original data.
     *
     * @param list<string> $recreated
     */
    public static function recreatedAfterRename(array $recreated): self
    {
        $one = count($recreated) === 1;

        return new self(sprintf(
            'The upgrade renamed this site\'s 1.x tables legacy_<name> and was checking its copy against them, but %s %s under the old %s again: created by an old Idno process after the rename. The legacy_ tables are this site\'s original data and must be kept. Nothing was changed. Stop every process of the old Idno version, then remove the newly created %s under the old %s (not the legacy_ ones); the upgrade then goes on.',
            self::tables($recreated),
            $one ? 'exists' : 'exist',
            $one ? 'name' : 'names',
            $one ? 'table' : 'tables',
            $one ? 'name' : 'names',
        ));
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
            'The 1.x tables cannot be renamed: %s both exist. Nothing was changed. The tables without the prefix hold this site and are what the upgrade is copying; this upgrade did not create the legacy_ %s. Someone must examine both before anything is moved: find out what made the legacy_ %s and what %s, and move %s out of this database (or rename %s) only once it is clear %s not this site\'s data. The upgrade then goes on.',
            $pairs,
            $one ? 'table' : 'tables',
            $one ? 'table' : 'tables',
            $one ? 'it holds' : 'they hold',
            $one ? 'it' : 'them',
            $one ? 'it' : 'them',
            $one ? 'it is' : 'they are',
        ));
    }

    /**
     * A copy is about to start (a 1.x database, no upgrade on record) and tables of the new schema
     * already hold rows: copying would merge the 1.x site into whatever they hold.
     *
     * @param list<string> $tables names of LegacyDataCopier::MUST_BE_EMPTY (any other name is left out)
     */
    public static function newTablesNotEmpty(array $tables): self
    {
        $names = array_values(array_intersect($tables, LegacyDataCopier::MUST_BE_EMPTY));
        $one = count($names) === 1;

        return new self(sprintf(
            'The 1.x tables cannot be copied: %s already %s data, but no upgrade is on record. Nothing was changed. If that data is left over from an earlier upgrade attempt you do not want, back up the database and remove ALL the tables whose names begin with idno_ (not only %s); the upgrade then starts again. If it is a site you use, the 1.x tables (entities, entities_metadata, entities_search, ...) must not be in this database: move them to a database of their own.',
            $names === [] ? 'tables of the new schema' : implode(', ', $names),
            $one ? 'holds' : 'hold',
            $one ? 'this one' : 'these',
        ));
    }

    /**
     * The rename of the 1.x tables waited for them and gave up: something else is using them. Not an
     * error: the next step tries again.
     */
    public static function tablesInUse(): self
    {
        return new self('The old tables are in use by something else, such as a database backup or an old Idno process, so they cannot be renamed yet. The upgrade goes on by itself when that has finished.');
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
