<?php

declare(strict_types=1);

namespace Tests\Templating;

use Tests\Characterisation\HttpSiteClient;

/**
 * How long the home page and the permalink of a note take to draw for a visitor: a tool for a change that may make pages slower,
 * not a gate. Skipped unless IDNO_TEST_PERFORMANCE is `record` or `compare`.
 *
 * The fixture site is seeded through the test site, then a server of the test serves it in the test environment with the debug flag
 * off and opcache on (checked through `/_test/server`; an outbound request fails at once), ten requests warm each page, and each
 * page is timed in blocks of BLOCK_REQUESTS sequential requests; the number for a page is the smallest block median of BLOCKS blocks
 * (the minimum, because the noise of a machine is all in one direction: it only slows a request).
 *
 * - `record`: the two numbers (milliseconds) are written to the baseline file in the system's temporary directory, named for this
 *   checkout. Record one on the commit before the change, in the same session.
 * - `compare`: the same measurement, and each page's number must be at most BUDGET times the baseline's. Both numbers and the ratio
 *   are printed. A baseline is a number from one moment of one machine: compare only against one recorded on the same commit's
 *   parent in the same session, and run it more than once before believing a failure. Never a gate in CI.
 */
final class PagePerformanceTest extends RenderedSiteTestCase
{
    /** What a number may be, in proportion to the one it is compared with. */
    public const BUDGET = 1.20;

    private const WARM_UP = 10;

    private const BLOCK_REQUESTS = 40;

    private const BLOCKS = 5;

    protected function setUp(): void
    {
        if (!in_array(self::mode(), ['record', 'compare'], true)) {
            $this->markTestSkipped('Set IDNO_TEST_PERFORMANCE to record or compare to run the performance test.');
        }
        parent::setUp();
    }

    public function testThePagesAreNoSlowerThanTheBaseline(): void
    {
        $paths = $this->seedSite();
        $pages = ['home' => '', 'permalink' => $paths['status']];
        $client = new HttpSiteClient($this->server());

        $numbers = [];
        foreach ($pages as $name => $path) {
            for ($i = 0; $i < self::WARM_UP; ++$i) {
                $this->assertSame(200, $client->request('GET', $path)->status, $name . ' answers while warming up' . $this->serverLog());
            }
            $blocks = [];
            for ($round = 0; $round < self::BLOCKS; ++$round) {
                $blocks[] = $this->blockMedian($client, $path);
            }
            $numbers[$name] = min($blocks);
        }

        if (self::mode() === 'record') {
            file_put_contents(self::baselineFile(), json_encode($numbers, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
            fwrite(STDOUT, sprintf("\nBaseline written to %s: home %.2f ms, permalink %.2f ms\n", self::baselineFile(), $numbers['home'], $numbers['permalink']));
            $this->assertFileExists(self::baselineFile());

            return;
        }

        $this->assertFileExists(self::baselineFile(), 'there is no baseline: record one first (IDNO_TEST_PERFORMANCE=record)');
        $baseline = json_decode((string) file_get_contents(self::baselineFile()), true, 512, JSON_THROW_ON_ERROR);
        // Every page's numbers are printed before the first assertion, so that a failing page does not hide the other
        $ratios = [];
        foreach ($pages as $name => $path) {
            $ratios[$name] = $numbers[$name] / $baseline[$name];
            fwrite(STDOUT, sprintf("\n%s: %.2f ms, baseline %.2f ms: ratio %.3f\n", $name, $numbers[$name], $baseline[$name], $ratios[$name]));
        }
        foreach ($pages as $name => $path) {
            $this->assertLessThanOrEqual(self::BUDGET, $ratios[$name], sprintf('%s: %.2f ms is over %.2f times the %.2f ms of the baseline', $name, $numbers[$name], self::BUDGET, $baseline[$name]));
        }
    }

    /**
     * The baseline file: in the system's temporary directory, one per checkout.
     */
    public static function baselineFile(): string
    {
        return sys_get_temp_dir() . '/idno-perf-baseline-' . substr(hash('sha256', (string) realpath(dirname(__DIR__, 2))), 0, 12) . '.json';
    }

    /**
     * Starts a server of the site (test environment, debug off, opcache on), and checks it is what was asked.
     */
    private function server(): string
    {
        $url = $this->startExtraServer(['APP_ENV' => 'test', 'APP_DEBUG' => '0', 'IDNO_PROXY_STRING' => '127.0.0.1:9'], ['opcache.enable_cli' => '1']);
        $probe = (new HttpSiteClient($url))->request('GET', '_test/server');
        $this->assertSame(200, $probe->status, 'the server answers' . $this->serverLog());
        $this->assertSame(['env' => 'test', 'debug' => '0', 'opcache' => true], array_intersect_key($probe->json(), array_flip(['env', 'debug', 'opcache'])), 'the server is set up as the measurement needs');

        return $url;
    }

    private function blockMedian(HttpSiteClient $client, string $path): float
    {
        $milliseconds = [];
        for ($i = 0; $i < self::BLOCK_REQUESTS; ++$i) {
            $start = hrtime(true);
            $response = $client->request('GET', $path);
            $milliseconds[] = (hrtime(true) - $start) / 1e6;
            $this->assertSame(200, $response->status, 'the page answers');
        }
        sort($milliseconds);

        return ($milliseconds[self::BLOCK_REQUESTS / 2 - 1] + $milliseconds[self::BLOCK_REQUESTS / 2]) / 2;
    }

    private static function mode(): string
    {
        return (string) getenv('IDNO_TEST_PERFORMANCE');
    }
}
