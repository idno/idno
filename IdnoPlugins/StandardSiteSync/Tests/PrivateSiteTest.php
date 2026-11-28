<?php

declare(strict_types=1);

namespace IdnoPlugins\StandardSiteSync\Tests;

use Tests\Support\PrivateSiteTestCase;

/**
 * A private site (`walled_garden`) tells no AT Protocol server who its members are: `/.well-known/site.standard.publication` names no member's DID
 * (it answers as a site with no publication does), and a post is never put forward for a PDS. The same site made public names the DID, so the
 * first test cannot pass because nobody was connected. A site of the test's own.
 */
final class PrivateSiteTest extends PrivateSiteTestCase
{
    protected function siteEnvironment(): array
    {
        // (read when the site starts, before the test body runs: the control test names itself)
        return str_contains($this->getName(false), 'PublicSite') ? [] : ['IDNO_WALLED_GARDEN' => '1'];
    }

    private function connectedMember(): void
    {
        $this->registerUser('alice');
        $this->runInSite(
            '\Idno\Core\Idno::site()->plugins()->enable("StandardSiteSync");' . "\n"
            . '$user = \Idno\Entities\User::getByHandle("alice");' . "\n"
            . '\IdnoPlugins\StandardSiteSync\Main::saveATProtoSessionForUser($user, ["access_token" => "t", "did" => "did:plc:alice123"]);'
        );
    }

    /** A stranger or another server is not told a private site's member has a publication, or which DID it is. */
    public function testAPrivateSiteNamesNoMembersDidToAnyone(): void
    {
        $this->connectedMember();

        $answer = $this->site()->request('GET', '.well-known/site.standard.publication');

        $this->assertSame(404, $answer->status);
        $this->assertStringNotContainsString('did:plc:alice123', $answer->body);
    }

    public function testAPublicSiteNamesTheMembersDid(): void
    {
        $this->connectedMember();

        $answer = $this->site()->request('GET', '.well-known/site.standard.publication');

        $this->assertSame(200, $answer->status);
        $this->assertStringContainsString('did:plc:alice123', $answer->body);
    }
}
