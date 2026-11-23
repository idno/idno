<?php

declare(strict_types=1);

namespace Tests\Templating;

use Tests\Characterisation\HttpSiteClient;

/**
 * How long the home page and the permalink of a note take to draw for a visitor: a budget for the port to Twig, so that a
 * port that makes pages slower fails.
 *
 * Skipped unless IDNO_TEST_PERFORMANCE is `record` or `compare`. The fixture site is seeded through the test site, then a
 * second server serves it the way production does (APP_ENV=prod, APP_DEBUG=0, opcache on, an outbound request fails at once),
 * ten requests warm each page (the container is compiled, the opcache and the Twig cache filled), and one hundred sequential
 * requests are timed; the median and the 90th percentile of each page are printed.
 *
 * `record` writes the two medians (milliseconds) to the baseline file in the system's temporary directory, named for this
 * checkout; `compare` asserts that each median is at most BUDGET times the baseline. Record with nothing else running on the
 * machine, once, before the port begins.
 */
final class PagePerformanceTest extends RenderedSiteTestCase
{
    /** What a median may be, in proportion to the baseline's. */
    public const BUDGET = 1.15;

    private const WARM_UP = 10;

    private const REQUESTS = 100;

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
        $url = $this->startExtraServer(['APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'IDNO_PROXY_STRING' => '127.0.0.1:9'], ['opcache.enable_cli' => '1']);
        $client = new HttpSiteClient($url);

        $medians = [];
        foreach (['home' => '', 'permalink' => $paths['status']] as $name => $path) {
            for ($i = 0; $i < self::WARM_UP; ++$i) {
                $this->assertSame(200, $client->request('GET', $path)->status, $name . ' answers while warming up' . $this->serverLog());
            }
            $milliseconds = [];
            for ($i = 0; $i < self::REQUESTS; ++$i) {
                $start = hrtime(true);
                $response = $client->request('GET', $path);
                $milliseconds[] = (hrtime(true) - $start) / 1e6;
                $this->assertSame(200, $response->status, $name . ' answers');
            }
            sort($milliseconds);
            $medians[$name] = ($milliseconds[self::REQUESTS / 2 - 1] + $milliseconds[self::REQUESTS / 2]) / 2;
            fwrite(STDOUT, sprintf("\n%s: median %.2f ms, p90 %.2f ms (%d requests)\n", $name, $medians[$name], $milliseconds[(int) ceil(0.9 * self::REQUESTS) - 1], self::REQUESTS));
        }

        $file = self::baselineFile();
        if (self::mode() === 'record') {
            file_put_contents($file, json_encode($medians, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
            fwrite(STDOUT, 'Baseline written to ' . $file . "\n");
            $this->assertFileExists($file);

            return;
        }

        $this->assertFileExists($file, 'there is no baseline: record one first (IDNO_TEST_PERFORMANCE=record)');
        $baseline = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        foreach ($medians as $name => $median) {
            fwrite(STDOUT, sprintf("%s: %.2f ms against a baseline of %.2f ms: %.3f times\n", $name, $median, $baseline[$name], $median / $baseline[$name]));
            $this->assertLessThanOrEqual(self::BUDGET * $baseline[$name], $median, sprintf('%s: the median %.2f ms is over %.2f times the baseline of %.2f ms', $name, $median, self::BUDGET, $baseline[$name]));
        }
    }

    /**
     * The baseline file: in the system's temporary directory, one per checkout.
     */
    public static function baselineFile(): string
    {
        return sys_get_temp_dir() . '/idno-perf-baseline-' . substr(hash('sha256', (string) realpath(dirname(__DIR__, 2))), 0, 12) . '.json';
    }

    private static function mode(): string
    {
        return (string) getenv('IDNO_TEST_PERFORMANCE');
    }
}
