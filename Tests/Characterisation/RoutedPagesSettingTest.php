<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * The address of a page routed by the legacy table follows a stored setting: the place of a permalink's pattern is decided at every boot, so a compiled route
 * could not hold it. Not shared: it changes the site's settings.
 */
final class RoutedPagesSettingTest extends PrivateSiteTestCase
{
    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_ROUTED' => '1'];
    }

    public function testTheRoutedAddressFollowsAStoredSetting(): void
    {
        $this->runInSite('Idno\Core\Idno::site()->config()->test_routed_word = "other"; Idno\Core\Idno::site()->config()->save();');

        $other = $this->site()->request('GET', '/_test/routed/other/abc');
        $this->assertSame(200, $other->status, $other->body);
        $this->assertStringContainsString('arguments=', html_entity_decode($other->body));
        $this->assertStringContainsString('[&quot;abc&quot;]', $other->body);
        $old = $this->site()->request('GET', '/_test/routed/word/abc');
        $this->assertSame(404, $old->status, 'the old address is the 404 page');
        $this->assertStringNotContainsString('arguments=', html_entity_decode($old->body), 'no controller drew a page for the old address');
    }
}
