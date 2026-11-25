<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * A class of tests that only read shares one private site: it is started by the first test, kept for the class,
 * compared with what it was after every test (a test that changed it fails, and the next test gets a site of its
 * own again), served with one environment, and removed after the class.
 */
final class SharedSiteTest extends PrivateSiteTestCase
{
    protected const SHARED_SITE = true;

    protected function seedSharedSite(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
    }

    public function testTheFirstTestStartsTheSite(): array
    {
        // The first request a site with a user answers makes its Site record: it is made before the fingerprint is taken, or this test would fail.
        $this->assertSame(200, $this->site()->request('GET', '')->status);
        $this->assertSame(200, $this->site()->request('GET', '_test/server')->status);
        $marker = $this->sessionDirectory() . '/marker';
        file_put_contents($marker, 'one');

        return [$this->baseUrl(), $this->site()->request('GET', '_test/server')->body, $marker];
    }

    /**
     * @depends testTheFirstTestStartsTheSite
     *
     * @param array{0: string, 1: string, 2: string} $first
     */
    public function testTheSecondTestUsesTheSameSite(array $first): void
    {
        $this->assertSame($first[0], $this->baseUrl());
        $this->assertSame($first[1], $this->site()->request('GET', '_test/server')->body);
        $this->assertFileExists($first[2], 'the site\'s directory is the first test\'s');
    }

    public function testTheFirstRequestComesBeforeTheFingerprint(): void
    {
        // Pages a visitor reads write nothing the fingerprint holds, so a test that only asks passes the guard (its tearDown compares).
        foreach (['', 'content/all', 'feed.xml', 'robots.txt'] as $path) {
            $this->assertSame(200, $this->site()->request('GET', $path)->status, $path);
        }
    }

    public function testAFileInTheCachePoolIsWritten(): void
    {
        $directory = $this->cachePoolDirectory();
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/probe', 'x');
        $this->assertFileExists($directory . '/probe');
    }

    /**
     * @depends testAFileInTheCachePoolIsWritten
     */
    public function testTheCachePoolIsEmptiedAfterEachTest(): void
    {
        $this->assertFileDoesNotExist($this->cachePoolDirectory() . '/probe');
    }

    public function testATestThatChangesTheSiteFailsAndTheSiteIsRebuilt(): void
    {
        [$exit, $output] = $this->runFixture('SharedSiteMutatingFixture');
        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('changed the shared site', $output);
        $this->assertMatchesRegularExpression('/Tests: 2, Assertions: \d+, Failures: 1/', $output, 'only the first test failed: the second found the site as it was seeded');
    }

    public function testADifferentEnvironmentFails(): void
    {
        [$exit, $output] = $this->runFixture('SharedSiteEnvironmentFixture');
        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('one environment', $output);
        $this->assertMatchesRegularExpression('/Tests: 2, Assertions: \d+, Failures: 1/', $output);
    }

    /**
     * Runs a fixture class in a PHPUnit process of its own (no configuration: no memory guard, no suites).
     *
     * @return array{0: int, 1: string} exit code and output
     */
    private function runFixture(string $class): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=22527', $root . '/vendor/bin/phpunit', '--no-configuration', '--bootstrap', $root . '/Tests/_bootstrap.php', $root . '/Tests/Characterisation/fixtures/' . $class . '.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            // the nested run is not the site under test: it takes the rig's own settings and a database name of its own (on MySQL the site's is named by its purpose, and a leftover of that name is dropped first), and nothing else of this process's environment
            array_filter(['PATH' => getenv('PATH'), 'IDNO_DOMAIN' => getenv('IDNO_DOMAIN'), 'IDNO_PORT' => getenv('IDNO_PORT'), 'HOME' => getenv('HOME'), 'IDNO_TEST_SITE_PURPOSE' => 'nested'], static fn ($value): bool => $value !== false),
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
