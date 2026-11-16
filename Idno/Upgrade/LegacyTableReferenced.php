<?php

declare(strict_types=1);

namespace Idno\Upgrade;

/**
 * LegacyTables::drop() could not drop a legacy table because a table outside the fourteen has a
 * foreign key that references it (a 1.x plugin's table that referenced `entities` followed the
 * rename). Trying again cannot succeed until that table or its constraint is dealt with. The
 * message names the referencing tables and constraints: admin-only detail (the log, the console).
 *
 * An exception, not a container service.
 */
final class LegacyTableReferenced extends \RuntimeException
{
    /**
     * @param list<string> $references `<table>.<constraint>` of each foreign key into $table
     */
    public function __construct(public readonly string $table, public readonly array $references, \Throwable $previous)
    {
        parent::__construct(sprintf(
            'The legacy table %s cannot be deleted: %s (another table\'s foreign key refers to it). Deal with that table or its constraint first. %s',
            $table,
            $references === [] ? 'a table outside the 1.x tables refers to it' : 'referenced by ' . implode(', ', $references),
            LegacyDataCopier::clean($previous->getMessage()),
        ), 0, $previous);
    }
}
