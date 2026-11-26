<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * What a test site's servers share with the rest of the machine: nothing. The site's sessions are files in a directory of its own
 * (removed with the site), not the system's shared one, and its processes use the temporary directory of the test's process, so what
 * they write there (the mail log, the sink) is where the test reads it.
 */
final class TestServerStateTest extends PrivateSiteTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function probe(string $baseUrl): array
    {
        $answer = (new HttpSiteClient($baseUrl))->request('GET', '_test/server');
        $this->assertSame(200, $answer->status, $this->serverLog());

        return json_decode($answer->body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheSitesSessionsAreInADirectoryOfItsOwn(): void
    {
        $directory = $this->sessionDirectory();
        $this->assertSame($directory, $this->probe($this->baseUrl())['session_save_path']);
        $this->assertNotSame(sys_get_temp_dir(), dirname($directory), 'not a shared system directory');
        $this->assertStringStartsWith(sys_get_temp_dir() . '/idno-site-', $directory);

        if (($this->siteConnection()->getParams()['driver'] ?? '') === 'pdo_sqlite') {
            // (on MySQL and MariaDB the sessions are the rows of `idno_sessions`, not files)
            $this->site()->request('GET', '');
            $this->assertNotSame([], glob($directory . '/sess_*'), 'a request to the site wrote its session there');
        }
    }

    public function testAnExtraServerSharesTheSitesSessionDirectory(): void
    {
        $url = $this->startExtraServer(['APP_ENV' => 'test']);

        $this->assertSame($this->sessionDirectory(), $this->probe($url)['session_save_path']);
    }

    public function testAnExtraServerThatNamesAnotherSessionDirectoryGetsIt(): void
    {
        $other = $this->sessionDirectory() . '/other';
        mkdir($other, 0700);
        $url = $this->startExtraServer(['APP_ENV' => 'test'], ['session.save_path' => $other]);

        $this->assertSame($other, $this->probe($url)['session_save_path']);
    }

    public function testTheSiteAndItsProcessesUseTheTemporaryDirectoryOfTheTest(): void
    {
        $this->assertSame(sys_get_temp_dir(), $this->probe($this->baseUrl())['temp_dir']);
        $this->assertSame(sys_get_temp_dir(), $this->runInSite('return sys_get_temp_dir();'));
        $this->assertSame($this->sessionDirectory(), $this->runInSite('return ini_get("session.save_path");'), 'and a process that boots the site keeps its sessions in the site\'s directory too');
    }
}
