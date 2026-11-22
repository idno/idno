<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * The queue's worker is a console process: it has no request to take the site's host from. The host is the one
 * of the boot configuration's `url` (which the worker requires), so that what the worker stores in the file store,
 * which names its directory after the host, is where the site's pages look.
 */
final class QueueWorkerHostTest extends AsyncSiteTestCase
{
    public function testTheWorkersHostAndFileDirectoryAreThoseOfTheSite(): void
    {
        $inWorker = $this->runInSite('$c = \Idno\Core\Idno::site()->config(); return ["host" => $c->host, "directory" => $c->getFileBaseDirName(), "feed" => $c->getURL()];', true);
        $host = (string) parse_url($this->baseUrl(), PHP_URL_HOST);

        $this->assertSame($host, $inWorker['host']);
        $this->assertSame($host, $inWorker['directory']);
        $this->assertSame($this->baseUrl(), $inWorker['feed']);
    }

    private function sites(): array
    {
        return $this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); $sites = \Idno\Core\Site::get([], [], 20); return array_map(static fn ($s) => $s->domain, $sites);');
    }

    private function addSite(string $domain): void
    {
        $this->runInSite('\\Idno\\Core\\Idno::site()->db()->setIgnoreAccess(true); $s = new \\Idno\\Core\\Site(); $s->domain = ' . $this->literal($domain) . '; $s->save(true); return true;');
    }

    public function testAConsoleProcessNeverCreatesASiteRecordAndUsesTheOnlyOneThereIs(): void
    {
        // The site's one record names another host than the boot url's (visitors use another name for the site).
        $this->addSite('www.other.example');
        $this->assertSame(['www.other.example'], $this->sites());

        $inConsole = $this->runInSite('$d = \\Idno\\Core\\Idno::site()->site_details(); return ["domain" => $d?->domain, "directory" => \\Idno\\Core\\Idno::site()->config()->getFileBaseDirName()];', true);

        $this->assertSame(['www.other.example'], $this->sites(), 'no record was added');
        $this->assertSame('www.other.example', $inConsole['domain'], 'the one there is, is the site');
        $this->assertSame((string) parse_url($this->baseUrl(), PHP_URL_HOST), $inConsole['directory'], 'the file directory is the web\'s');
    }

    public function testWithSeveralSiteRecordsAConsoleProcessHasNoneAndStillCreatesNothing(): void
    {
        $this->addSite('one.example');
        $this->addSite('two.example');
        $before = $this->sites();
        sort($before);
        $this->assertSame(['one.example', 'two.example'], $before);

        $inConsole = $this->runInSite('$d = \\Idno\\Core\\Idno::site()->site_details(); return ["domain" => $d?->domain];', true);

        $after = $this->sites();
        sort($after);
        $this->assertSame($before, $after);
        $this->assertNull($inConsole['domain']);
    }

    public function testAConsoleProcessUsesTheRecordOfItsOwnHostWhenThereIsOne(): void
    {
        $host = (string) parse_url($this->baseUrl(), PHP_URL_HOST);
        $this->addSite($host);
        $this->addSite('another.example');

        $inConsole = $this->runInSite('return \\Idno\\Core\\Idno::site()->site_details()?->domain;', true);

        $this->assertSame($host, $inConsole);
        $this->assertCount(2, $this->sites());
    }
}
