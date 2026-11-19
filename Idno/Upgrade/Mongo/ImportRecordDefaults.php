<?php

declare(strict_types=1);

namespace Idno\Upgrade\Mongo;

use Idno\Storage\RecordDefaults;

/**
 * The RecordDefaults of the import's record store: it fills in nothing. The importer saves only
 * documents with an `_id` and a `uuid` of their own (any other is a reject), so no id or uuid is ever
 * asked for, and inventing one would be a record the site never had; asking is a \LogicException. A
 * document without a `siteid` is saved with the key `siteid` set to null, as the stores save any
 * record when there is no site.
 *
 * Built with new by the importer: not a container service.
 */
final class ImportRecordDefaults implements RecordDefaults
{
    public function generateId(): string
    {
        throw new \LogicException('The import never invents a record id: a document without one is a reject.');
    }

    public function siteUrl(): string
    {
        throw new \LogicException('The import never invents a uuid: a document without one is a reject.');
    }

    public function currentSiteId(): ?string
    {
        return null;
    }
}
