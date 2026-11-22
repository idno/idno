<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * What the queue worker (a console process: no request) builds from the site's explicit boot `url`:
 * with an https url the display url, `Page::isSSL()` and what ActivityPub builds for the actor and the
 * key are https, also when IDNO_DOMAIN is set as well. (The url reaches the site through the boot
 * configuration whatever its source, config.ini, config.json or IDNO_URL; Config::init() takes it from
 * there. The tests of the loader pin the sources.)
 */
final class QueueWorkerSiteUrlTest extends AsyncSiteTestCase
{
    protected function configuredScheme(): string
    {
        return 'https';
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_DOMAIN' => 'worker-domain.example'];
    }

    public function testAnHttpsBootUrlGivesHttpsEverywhereInTheWorkerEvenWithADomainSet(): void
    {
        $this->registerUser('alice');
        $https = 'https://' . substr($this->baseUrl(), strlen('http://'));

        $seen = $this->runInSite(
            '$user = \Idno\Entities\User::getByHandle("alice");'
            . ' return ["display" => \Idno\Core\Idno::site()->config()->getDisplayURL(), "ssl" => \Idno\Common\Page::isSSL(), "url" => \Idno\Core\Idno::site()->config()->getURL(),'
            . ' "keyId" => $user->getPublicKey()["id"], "actor" => $user->getActivityPubActorID()];',
            asConsole: true,
        );

        $this->assertSame([
            'display' => $https,
            'ssl' => true,
            'url' => $https,
            'keyId' => $https . 'actor/alice#main-key',
            'actor' => $https . 'actor/alice',
        ], $seen);
    }
}
