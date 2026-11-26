<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Templating\RenderedSiteTestCase;

/**
 * A member's account email address is private: no page, feed, structured data or federation document the site publishes about a member or about
 * their posts holds it, for anyone who asks (a visitor, another member, the member, an administrator). A contact address the member lists on their
 * profile as a `mailto:` link is public and is still shown as that link.
 */
final class AccountEmailPrivateTest extends RenderedSiteTestCase
{
    protected const SHARED_SITE = true;

    private const PUBLIC_ADDRESS = 'bob.contact@example.org';

    /** The viewers: name => the handle that is logged in (null: a visitor). */
    private const VIEWERS = ['visitor' => null, 'member' => 'bob', 'owner' => 'ada', 'admin' => 'ada'];

    protected function seedSharedSite(): void
    {
        $this->seedSite();
        $this->runInSite(<<<'PHP'
        $bob = \Idno\Entities\User::getByHandle('bob');
        $bob->profile = ['description' => '', 'url' => ['mailto:bob.contact@example.org']];
        $bob->save(true);

        return true;
        PHP);
    }

    /** @var array<string, string> */
    private array $cookies = [];

    /** @return array<string, string> */
    private function as(string $viewer): array
    {
        $handle = self::VIEWERS[$viewer];
        if ($handle === null) {
            return [];
        }

        return ['Cookie' => $this->cookies[$handle] ??= $this->loginCookie($handle, self::PASSWORD)];
    }

    /** @return array<string, string> every form a page of a member or of a post is asked for: its name and the suffix of the address */
    private static function forms(): array
    {
        return [
            'html' => '',
            'json' => '?_t=json',
            'rss' => '?_t=rss',
            'jsonfeed' => '?_t=jsonfeed',
            'markdown' => '?_t=markdown',
            'kml' => '?_t=kml',
        ];
    }

    public function testNoPageOrFeedOfAMemberOrAPostHoldsTheAccountEmail(): void
    {
        $paths = $this->seedSite();
        $targets = ['/profile/ada', '/profile/bob', '/' . ltrim($paths['status'], '/'), '/' . ltrim($paths['bobs'], '/'), '/' . ltrim($paths['article'], '/'), '/'];
        $asked = 0;
        foreach (self::VIEWERS as $viewer => $_) {
            foreach ($targets as $target) {
                foreach (self::forms() as $form => $suffix) {
                    $answer = $this->site()->request('GET', $target . $suffix, $this->as($viewer));
                    $asked++;
                    foreach (['ada@example.com', 'bob@example.com', 'carol@example.com'] as $address) {
                        $this->assertStringNotContainsStringIgnoringCase($address, $answer->body, $viewer . ' asks ' . $target . ' as ' . $form);
                        $this->assertStringNotContainsStringIgnoringCase(str_replace('@', '%40', $address), $answer->body, $viewer . ' asks ' . $target . ' as ' . $form);
                    }
                }
            }
        }
        $this->assertGreaterThan(100, $asked);
    }

    public function testNoFederationOrDiscoveryDocumentHoldsTheAccountEmail(): void
    {
        $paths = $this->seedSite();
        $host = (string) parse_url($this->baseUrl(), PHP_URL_HOST);
        $asks = [
            ['/actor/ada', ['Accept' => 'application/activity+json']],
            ['/actor/bob', ['Accept' => 'application/activity+json']],
            ['/actor/ada/outbox', ['Accept' => 'application/activity+json']],
            ['/' . ltrim($paths['status'], '/'), ['Accept' => 'application/activity+json']],
            ['/' . ltrim($paths['article'], '/'), ['Accept' => 'application/activity+json']],
            ['/' . ltrim($paths['bobs'], '/'), ['Accept' => 'application/activity+json']],
            ['/.well-known/webfinger?resource=acct:ada@' . $host, []],
            ['/.well-known/webfinger?resource=acct:bob@' . $host, []],
            ['/search/mentions.json?q=a', ['X-Requested-With' => 'XMLHttpRequest']],
            ['/search/mentions.json?q=b', ['X-Requested-With' => 'XMLHttpRequest']],
            ['/feed.xml', []],
            ['/feed.xml?_t=jsonfeed', []],
            ['/nodeinfo/2.0', []],
        ];
        foreach (self::VIEWERS as $viewer => $_) {
            foreach ($asks as [$path, $headers]) {
                $answer = $this->site()->request('GET', $path, $this->as($viewer) + $headers);
                foreach (['ada@example.com', 'bob@example.com', 'carol@example.com'] as $address) {
                    $this->assertStringNotContainsStringIgnoringCase($address, $answer->body, $viewer . ' asks ' . $path);
                }
            }
        }
    }

    /** An address a member lists on their profile is public, and is still shown as a link. */
    public function testAPublicMailtoLinkOfTheProfileIsStillShown(): void
    {
        foreach (array_keys(self::VIEWERS) as $viewer) {
            $answer = $this->site()->request('GET', '/profile/bob', $this->as($viewer));
            $this->assertStringContainsString('mailto:' . self::PUBLIC_ADDRESS, $answer->body, $viewer);
            $this->assertStringContainsString('u-email', $answer->body, $viewer);
        }
    }
}
