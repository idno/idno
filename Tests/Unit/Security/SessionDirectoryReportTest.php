<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Security\SessionDirectoryReport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * Whether the "this session directory cannot be used" line is logged: once an hour for a setting and problem (remembered in the site's own cache,
 * never in a file of the shared temporary directory), and never an exception or a blocked sign-in whatever the cache does.
 */
final class SessionDirectoryReportTest extends TestCase
{
    // A web request is a process of its own, so without a memory that outlives it the line would be logged on every request.
    public function testTheSameProblemIsSaidOnceAnHour(): void
    {
        $clock  = new MockClock('2026-01-01 10:00:00');
        $pool   = new ArrayAdapter(0, false, 0, 0, $clock);
        $report = static fn (): SessionDirectoryReport => new SessionDirectoryReport(static fn () => $pool);

        $this->assertTrue($report()->shouldSay('IDNO_SESSION_DIR', 'it is a link'));
        $this->assertFalse($report()->shouldSay('IDNO_SESSION_DIR', 'it is a link'), 'the next request: remembered');

        $clock->sleep(3599);
        $this->assertFalse($report()->shouldSay('IDNO_SESSION_DIR', 'it is a link'));
        $clock->sleep(2);
        $this->assertTrue($report()->shouldSay('IDNO_SESSION_DIR', 'it is a link'), 'an hour later it is said again');
    }

    // Two different problems (or settings) must not hide each other.
    public function testAnotherProblemOrSettingIsSaid(): void
    {
        $pool   = new ArrayAdapter();
        $report = new SessionDirectoryReport(static fn () => $pool);

        $this->assertTrue($report->shouldSay('IDNO_SESSION_DIR', 'it is a link'));
        // Each is said, and then remembered: a call that only failed to reach the cache would answer true twice.
        foreach ([['var/sessions', 'it is a link'], ['IDNO_SESSION_DIR', 'it is not writable']] as [$setting, $problem]) {
            $this->assertTrue((new SessionDirectoryReport(static fn () => $pool))->shouldSay($setting, $problem), $setting . ': ' . $problem);
            $this->assertFalse((new SessionDirectoryReport(static fn () => $pool))->shouldSay($setting, $problem), $setting . ': ' . $problem . ' is remembered');
        }
    }

    // Nothing about the setting or problem may reach the cache's key as a path or an unsafe character (a PSR-6 key refuses `/` and `:`).
    public function testTheKeyIsSafeForAnyProblemText(): void
    {
        $pool = new ArrayAdapter();

        // A key the cache refuses is swallowed and answers true again, so the second call must answer false (remembered).
        $this->assertTrue((new SessionDirectoryReport(static fn () => $pool))->shouldSay('var/sessions', "a/b:c{d}\n(e)@f\\g"));
        $this->assertFalse((new SessionDirectoryReport(static fn () => $pool))->shouldSay('var/sessions', "a/b:c{d}\n(e)@f\\g"));
    }

    // With no usable cache (a disabled pool, a pool that fails, none at all) the line is said once for the request, never dropped and never thrown.
    public function testWithoutAWorkingCacheItIsSaidOncePerRequestAndNeverThrows(): void
    {
        $providers = [
            'none'     => static fn () => null,
            'throws'   => static function (): never {
                throw new \RuntimeException('no pool');
            },
        ];
        foreach ($providers as $name => $provider) {
            $report = new SessionDirectoryReport($provider);

            $this->assertTrue($report->shouldSay('IDNO_SESSION_DIR', 'it is a link'), $name);
            $this->assertFalse($report->shouldSay('IDNO_SESSION_DIR', 'it is a link'), $name . ': once for the request');
        }

        $failing = new \Symfony\Component\Cache\Adapter\NullAdapter();
        $report  = new SessionDirectoryReport(static fn () => $failing);
        $this->assertTrue($report->shouldSay('IDNO_SESSION_DIR', 'it is a link'), 'a disabled pool remembers nothing');
        $this->assertTrue((new SessionDirectoryReport(static fn () => $failing))->shouldSay('IDNO_SESSION_DIR', 'it is a link'), 'so the next request says it too');
    }
}
