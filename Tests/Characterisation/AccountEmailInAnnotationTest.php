<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Templating\RenderedSiteTestCase;

/** What an annotation a member makes on a post stores and publishes: their profile address and name, never their account email (a site of its own: the test writes). */
final class AccountEmailInAnnotationTest extends RenderedSiteTestCase
{
    /** @return array<string, string> */
    private function as(string $viewer): array
    {
        $handle = ['visitor' => null, 'member' => 'bob', 'owner' => 'ada', 'admin' => 'ada'][$viewer];

        return $handle === null ? [] : ['Cookie' => $this->loginCookie($handle, self::PASSWORD)];
    }

    /** A post a member answers on carries the member's profile address, not their account email. */
    public function testAnAnnotationAMemberMakesHoldsNoAccountEmail(): void
    {
        $paths = $this->seedSite();
        $id = (string) $paths['ids']['status'];
        $uuid = $this->runInSite('return \\Idno\\Common\\Entity::getByID(' . $this->literal($id) . ')->getUUID();');
        $cookie = $this->as('member');
        $token = trim($this->site()->request('GET', '/service/security/csrftoken/', $cookie + ['X-Requested-With' => 'XMLHttpRequest'])->body);
        $decoded = json_decode($token, true);
        if (is_array($decoded)) {
            $token = (string) ($decoded['token'] ?? $decoded['csrf'] ?? reset($decoded));
        }
        $made = $this->site()->request('POST', '/annotation/post/', $cookie + ['Referer' => $this->baseUrl()], ['__bTk' => $token, 'object' => $uuid, 'type' => 'like', 'body' => '']);
        $this->assertSame(302, $made->status);
        $stored = json_encode($this->runInSite('return \\Idno\\Core\\Idno::container()->get(\\Idno\\Storage\\RecordStore::class)->findById(\'entities\', ' . $this->literal($id) . ')[\'annotations\'] ?? [];'));
        $this->assertStringContainsString('"like"', (string) $stored, 'Bob\'s like was stored');
        $this->assertStringNotContainsString('bob@example.com', (string) $stored);
        foreach (['visitor', 'member', 'owner', 'admin'] as $viewer) {
            foreach (['', '?_t=json'] as $suffix) {
                $answer = $this->site()->request('GET', '/' . ltrim($paths['status'], '/') . $suffix, $this->as($viewer));
                $this->assertStringNotContainsString('bob@example.com', $answer->body);
            }
        }
    }
}
