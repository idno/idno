<?php

declare(strict_types=1);

namespace Tests\Storage;

use Idno\Storage\RecordStore;
use Tests\Storage\Contract\RecordStoreContract;

/**
 * Runs the record storage contract against today's backend, Idno\Data\MySQL,
 * reached through Idno::site()->db(). It needs the booted legacy application
 * (the normal rig suite); the contract itself does not.
 */
final class LegacyMySqlRecordStoreTest extends RecordStoreContract
{
    private string|false $previousErrorLog = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Several contract tests make the legacy backend fail on purpose (unknown
        // tables, an invalid query) and it logs each failure; keep the output clean.
        $this->previousErrorLog = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }
        parent::tearDown();
    }

    protected function store(): RecordStore
    {
        return new LegacyBackendBinding();
    }

    protected function newId(): string
    {
        return \Idno\Core\Idno::site()->db()->generateID();
    }

    protected function currentSiteId(): ?string
    {
        $site = \Idno\Core\Idno::site()->site_details();

        return $site ? $site->uuid() : null;
    }

    // deleteAll() stays skipped here (ownsPrivateDatabase() is false): this
    // binding shares the rig's database, where emptying a collection would
    // destroy other tests' fixtures and the site's own configuration.
}
