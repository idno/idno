<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Storage\ConnectionParameters;
use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;
use Tests\Support\WebServer;

/**
 * A site whose `IDNO_SESSION_DIR` cannot be used (here a link): the choice is said in the log, once an hour and never with a
 * server path, sessions go on in the next directory, and signing in still works.
 */
final class FileSessionUnusableDirectoryTest extends PrivateSiteTestCase
{
    use LogsIn;

    private string $workDirectory = '';

    protected function siteEnvironment(): array
    {
        if ($this->workDirectory === '') {
            $this->workDirectory = sys_get_temp_dir() . '/idno-unusable-sessions-' . bin2hex(random_bytes(6));
            mkdir($this->workDirectory . '/target', 0700, true);
            symlink($this->workDirectory . '/target', $this->workDirectory . '/link');
        }

        return ['IDNO_SESSION_DIR' => $this->workDirectory . '/link'];
    }

    protected function siteIni(): array
    {
        return ['session.save_path' => ''];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all()) !== 'sqlite') {
            $this->markTestSkipped('A MySQL or MariaDB site keeps its sessions in the database: there are no session files.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->workDirectory !== '') {
            WebServer::removeTree($this->workDirectory);
        }
        parent::tearDown();
    }

    // Catches an unusable override replaced in silence (the operator believes sessions are there), a warning on every request, a server path in the log, or a sign-in that fails with it.
    public function testAnUnusableDirectoryIsSaidOnceAndSigningInStillWorks(): void
    {
        $cookie = $this->registerUser('ada');
        for ($i = 0; $i < 6; ++$i) {
            $this->assertSame(200, $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status);
        }

        $log = $this->serverLogContents();
        $said = substr_count($log, 'IDNO_SESSION_DIR cannot be used');
        $this->assertGreaterThanOrEqual(1, $said, $log);
        $this->assertLessThanOrEqual(2, $said, 'once, not for each request (a race may let a second process say it too)');
        $this->assertStringNotContainsString($this->workDirectory, $log);
    }
}
