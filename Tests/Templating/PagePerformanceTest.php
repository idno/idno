<?php

declare(strict_types=1);

namespace Tests\Templating;

use Tests\Characterisation\HttpSiteClient;
use Tests\Fixtures\BundledTemplates;

/**
 * How long the home page and the permalink of a note take to draw for a visitor, against the engine they were drawn by before the
 * port to Twig: a budget, so that a port that makes pages slower fails.
 *
 * Skipped unless IDNO_TEST_PERFORMANCE is `record`, `compare` or `ratio`. The fixture site is seeded through the test site, then
 * servers of the test serve it in the test environment with the debug flag off and opcache on (checked through `/_test/server`; an
 * outbound request fails at once), ten requests warm each page, and each page is timed in blocks of BLOCK_REQUESTS sequential
 * requests; a server's number for a page is the smallest block median of BLOCKS blocks (the minimum, because the noise of a
 * machine is all in one direction: it only slows a request).
 *
 * - `compare`, while a Twig file has its `.tpl.php` beside it: two servers identical but for IDNO_TEST_TWIG_BYPASS (which draws the
 *   originals in place of the Twig files; Tests\Fixtures\TwigPortCheck), their blocks interleaved in one run so that the machine's
 *   moods hit both, and the Twig server's number must be at most BUDGET times the bypass server's, for each page. Both numbers and
 *   the ratio are printed.
 * - `compare`, with no such file (nothing ported yet, or the originals deleted): the Twig server alone against the baseline
 *   `record` stored, by the same method. That is a number from another moment and not a reliable gate: before the originals are
 *   deleted run `ratio` and keep its ratio in the commit message of the deleting change; after, compare only with a baseline
 *   recorded on that same commit in the same session.
 * - `record`: the Twig server alone, its two numbers (milliseconds) written to the baseline file in the system's temporary directory,
 *   named for this checkout, for use after the originals are deleted.
 * - `ratio`: the two servers as in the first `compare`, whatever is ported (with nothing ported both draw the same files: the ratio
 *   is the method's own noise).
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
        if (!in_array(self::mode(), ['record', 'compare', 'ratio'], true)) {
            $this->markTestSkipped('Set IDNO_TEST_PERFORMANCE to record, compare or ratio to run the performance test.');
        }
        parent::setUp();
    }

    public function testThePagesAreNoSlowerThanTheOldEngine(): void
    {
        $paths = $this->seedSite();
        $pages = ['home' => '', 'permalink' => $paths['status']];
        $twoServers = self::mode() === 'ratio' || (self::mode() === 'compare' && self::originalsBesideTwigFiles());

        $twig = new HttpSiteClient($this->server(false));
        $bypass = $twoServers ? new HttpSiteClient($this->server(true)) : null;

        $numbers = ['twig' => [], 'bypass' => []];
        foreach ($pages as $name => $path) {
            foreach ([$twig, $bypass] as $client) {
                for ($i = 0; $i < self::WARM_UP && $client !== null; ++$i) {
                    $this->assertSame(200, $client->request('GET', $path)->status, $name . ' answers while warming up' . $this->serverLog());
                }
            }
            $blocks = ['twig' => [], 'bypass' => []];
            for ($round = 0; $round < self::BLOCKS; ++$round) {
                $blocks['twig'][] = $this->blockMedian($twig, $path);
                if ($bypass !== null) {
                    $blocks['bypass'][] = $this->blockMedian($bypass, $path);
                }
            }
            $numbers['twig'][$name] = min($blocks['twig']);
            $numbers['bypass'][$name] = $bypass === null ? null : min($blocks['bypass']);
        }

        if (self::mode() === 'record') {
            file_put_contents(self::baselineFile(), json_encode($numbers['twig'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
            fwrite(STDOUT, sprintf("\nBaseline written to %s: home %.2f ms, permalink %.2f ms\n", self::baselineFile(), $numbers['twig']['home'], $numbers['twig']['permalink']));
            $this->assertFileExists(self::baselineFile());

            return;
        }

        if (!$twoServers) {
            $this->assertFileExists(self::baselineFile(), 'there is no baseline: record one first (IDNO_TEST_PERFORMANCE=record)');
            $baseline = json_decode((string) file_get_contents(self::baselineFile()), true, 512, JSON_THROW_ON_ERROR);
        } else {
            $baseline = $numbers['bypass'];
        }
        // Every page's numbers are printed before the first assertion, so that a failing page does not hide the other
        $ratios = [];
        foreach ($pages as $name => $path) {
            $ratios[$name] = $numbers['twig'][$name] / $baseline[$name];
            fwrite(STDOUT, sprintf("\n%s: twig %.2f ms, %s %.2f ms: ratio %.3f\n", $name, $numbers['twig'][$name], $twoServers ? 'bypass' : 'baseline', $baseline[$name], $ratios[$name]));
        }
        if (self::mode() === 'compare') {
            foreach ($pages as $name => $path) {
                $this->assertLessThanOrEqual(self::BUDGET, $ratios[$name], sprintf('%s: %.2f ms is over %.2f times the %.2f ms of the old engine', $name, $numbers['twig'][$name], self::BUDGET, $baseline[$name]));
            }
        }
        $this->addToAssertionCount(1);
    }

    /**
     * The baseline file: in the system's temporary directory, one per checkout.
     */
    public static function baselineFile(): string
    {
        return sys_get_temp_dir() . '/idno-perf-baseline-' . substr(hash('sha256', (string) realpath(dirname(__DIR__, 2))), 0, 12) . '.json';
    }

    /**
     * Starts a server of the site (test environment, debug off, opcache on), with the bypass or without, and checks it is what was asked.
     */
    private function server(bool $bypass): string
    {
        $environment = ['APP_ENV' => 'test', 'APP_DEBUG' => '0', 'IDNO_PROXY_STRING' => '127.0.0.1:9'] + ($bypass ? ['IDNO_TEST_TWIG_BYPASS' => '1'] : []);
        $url = $this->startExtraServer($environment, ['opcache.enable_cli' => '1']);
        $probe = (new HttpSiteClient($url))->request('GET', '_test/server');
        $this->assertSame(200, $probe->status, 'the server answers' . $this->serverLog());
        $this->assertSame(['env' => 'test', 'debug' => '0', 'opcache' => true, 'twig_bypass' => $bypass ? '1' : ''], $probe->json(), 'the server is set up as the measurement needs');

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

    /**
     * Whether a Twig file of core or a bundled plugin has its `.tpl.php` beside it.
     */
    private static function originalsBesideTwigFiles(): bool
    {
        $root = dirname(__DIR__, 2);

        return array_intersect_key(BundledTemplates::twigTemplates($root), BundledTemplates::files($root, '.tpl.php')) !== [];
    }

    private static function mode(): string
    {
        return (string) getenv('IDNO_TEST_PERFORMANCE');
    }
}
