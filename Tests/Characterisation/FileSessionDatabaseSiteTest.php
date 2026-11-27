<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Storage\ConnectionParameters;
use Tests\Support\PrivateSiteTestCase;
use Tests\Support\WebServer;

/**
 * A site whose sessions are in the database (MySQL or MariaDB) has no session files, so it never makes a session directory
 * whatever `IDNO_SESSION_DIR` says.
 */
final class FileSessionDatabaseSiteTest extends PrivateSiteTestCase
{
    private string $ownDirectory = '';

    protected function siteEnvironment(): array
    {
        $this->ownDirectory = $this->ownDirectory !== '' ? $this->ownDirectory : sys_get_temp_dir() . '/idno-own-sessions-' . bin2hex(random_bytes(6));

        return ['IDNO_SESSION_DIR' => $this->ownDirectory];
    }

    protected function siteIni(): array
    {
        return ['session.save_path' => ''];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all()) === 'sqlite') {
            $this->markTestSkipped('An SQLite site keeps its sessions in files: it does make a session directory.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->ownDirectory !== '') {
            WebServer::removeTree($this->ownDirectory);
        }
        parent::tearDown();
    }

    // Catches a directory made (and files kept) for a site whose sessions a database handler stores.
    public function testASiteWhoseSessionsAreInTheDatabaseMakesNoSessionDirectory(): void
    {
        $answer = json_decode($this->site()->request('GET', '_test/session')->body, true);

        $this->assertSame('user', $answer['module']);
        $this->assertDirectoryDoesNotExist($this->ownDirectory);
    }
}
