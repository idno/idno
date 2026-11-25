<?php

declare(strict_types=1);

namespace Tests\Characterisation\fixtures;

use Tests\Support\PrivateSiteTestCase;

/**
 * A class that shares its site and whose first test changes it: only SharedSiteTest runs it, in a process of its
 * own, and expects the first test to fail and the second to find a site without the change.
 */
final class SharedSiteMutatingFixture extends PrivateSiteTestCase
{
    protected const SHARED_SITE = true;

    public function testThisOneChangesTheSite(): void
    {
        $cookie = $this->registerUser('ada');
        $this->publishStatus($cookie, 'A note that must not be on the next test\'s site.');
        $this->assertSame(1, $this->statuses());
    }

    public function testThisOneFindsTheSiteAsItWasSeeded(): void
    {
        $this->assertSame(0, $this->statuses());
    }

    private function statuses(): int
    {
        return (int) $this->siteConnection()->fetchOne("select count(*) from idno_entities where type = 'status'");
    }
}
