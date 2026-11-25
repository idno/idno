<?php

declare(strict_types=1);

namespace Tests\Characterisation\fixtures;

use Tests\Support\PrivateSiteTestCase;

/**
 * A class that shares its site and whose environment differs from one test to the next: only SharedSiteTest runs
 * it, in a process of its own, and expects the second test to fail.
 */
final class SharedSiteEnvironmentFixture extends PrivateSiteTestCase
{
    protected const SHARED_SITE = true;

    private static int $round = 0;

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_ROUND' => (string) self::$round];
    }

    protected function tearDown(): void
    {
        ++self::$round;
        parent::tearDown();
    }

    public function testTheFirstRoundStartsTheSite(): void
    {
        $this->assertSame(200, $this->site()->request('GET', '_test/server')->status);
    }

    public function testTheSecondRoundAsksForAnotherEnvironment(): void
    {
        $this->assertSame(200, $this->site()->request('GET', '_test/server')->status);
    }
}
