<?php

declare(strict_types=1);

namespace IdnoPlugins\StandardSiteSync\Tests;

use Tests\Queue\AsyncSiteTestCase;

/**
 * A post saved on a private site (`walled_garden`) by a member who has connected a PDS is not put forward for it: no `standardsitesync/*` event is
 * queued, so nothing reaches the AT Protocol server. The same post on a public site is queued, so the first test cannot pass because the plugin was
 * off or the member was not connected. A site of the test's own with the asynchronous queue, and no live PDS.
 */
final class PrivateSitePostTest extends AsyncSiteTestCase
{
    protected function siteEnvironment(): array
    {
        // (read when the site starts, before the test body runs: the control test names itself)
        return str_contains($this->getName(false), 'PublicSite') ? [] : ['IDNO_WALLED_GARDEN' => '1'];
    }

    /** @return list<string> the names of the queued events that belong to the plugin */
    private function pluginEventsAfterAPost(): array
    {
        $cookie = $this->registerUser('alice');
        $this->runInSite(
            '$site = \Idno\Core\Idno::site();' . "\n"
            . '$site->plugins()->enable("StandardSiteSync");' . "\n"
            . '$site->config()->standardsitesync_enabled = true;' . "\n"
            . '$site->config()->save();' . "\n"
            . '$user = \Idno\Entities\User::getByHandle("alice");' . "\n"
            . '\IdnoPlugins\StandardSiteSync\Main::saveATProtoSessionForUser($user, ["access_token" => "t", "did" => "did:plc:alice123"]);'
        );

        $this->publishStatus($cookie, 'A post that a connected member might send to a PDS');

        return array_values(array_filter(array_column($this->queuedEvents(), 'event'), static fn (string $name): bool => str_starts_with($name, 'standardsitesync/')));
    }

    public function testAPostSavedOnAPrivateSiteIsNotPutForwardForAPds(): void
    {
        $this->assertSame([], $this->pluginEventsAfterAPost());
    }

    public function testAPostSavedOnAPublicSiteIsPutForwardForAPds(): void
    {
        $this->assertSame(['standardsitesync/sync'], $this->pluginEventsAfterAPost());
    }
}
