<?php

declare(strict_types=1);

namespace Tests\Install;

/**
 * The server of a site that is not installed keeps its sessions in a directory of the test's own and has the test's temporary
 * directory (the install gate answers every address but `/`, so the server's settings are read from its command line).
 */
final class UninstalledServerStateTest extends UninstalledSiteTestCase
{
    public function testTheServerHasADirectoryOfItsOwnForSessions(): void
    {
        $this->startServer();

        $port = (string) parse_url($this->base, PHP_URL_PORT);
        $commands = (string) shell_exec('ps -ax -o command= | grep -F -- ' . escapeshellarg('-S 127.0.0.1:' . $port . ' ') . ' | grep -v grep');
        $this->assertStringContainsString('-d session.save_path=' . $this->root . '/sessions ', $commands);
        $this->assertDirectoryExists($this->root . '/sessions');
        $this->assertSame(200, $this->request('/', null, [], ['Accept: text/html'])['status'], $this->serverLog());
    }

    public function testTheEnvironmentOfTheSiteHasTheTemporaryDirectoryOfTheTest(): void
    {
        $this->assertSame(sys_get_temp_dir(), $this->environment()['TMPDIR']);
    }
}
