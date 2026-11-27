<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * Deleting an account by a DELETE of its `/view/<id>` address (a deprecated shell writes its answer and ends before the kernel terminates) on a site with the
 * synchronous queue. Catches the work for after the response being lost on that route: the rest of the content stays, and the actor Delete, whose follower
 * records are gone, is never sent. The job runs for no time inside the request here, so everything past the first entity is after the response.
 */
final class AccountDeletionShellRouteTest extends PrivateSiteTestCase
{
    protected function siteEnvironment(): array
    {
        return parent::siteEnvironment() + ['IDNO_ACCOUNT_REMOVAL_SECONDS' => '0'];
    }

    public function testAnAdministratorsDeleteOfAMembersAddressEndsWithTheContentAndTheAccountGoneAndOneDeleteAtAFollower(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $admin = $this->loginCookie('ada', 'correct-horse-battery-9');
        $token = (string) $this->site()->request('GET', '/service/security/csrftoken/', ['Cookie' => $admin, 'X-Requested-With' => 'XMLHttpRequest'])->json()['token'];
        $this->addUser('mia');
        $sink = 'inbox-' . bin2hex(random_bytes(4));
        $seed = <<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->db()->setIgnoreAccess(true);
        $mia = \Idno\Entities\User::getByHandle('mia');
        $mia->getPrivateKey();
        foreach (range(1, 5) as $n) {
            $post = new \IdnoPlugins\Status\Status();
            $post->body = 'post ' . $n;
            $post->setOwner($mia);
            $post->setAccess('PUBLIC');
            $post->save(true);
        }
        $follower = new \IdnoPlugins\ActivityPub\Entities\ActivityPubFollower();
        $follower->user_uuid = $mia->getUUID();
        $follower->actor_uri = 'https://remote.example/users/bob';
        $follower->actor_inbox = $site->config()->getURL() . '_test/sink/%SINK%';
        $follower->save(true);
        return [$mia->getID(), $mia->getUUID()];
        PHP;
        [$id, $uuid] = $this->runInSite(str_replace('%SINK%', $sink, $seed));
        $this->site()->request('GET', '_test/sink/' . $sink . '/log'); // empty it: the posts' own deliveries are not what is tested

        $this->site()->request('DELETE', 'view/' . $id . '?__bTk=' . $token, ['Cookie' => $admin, 'Referer' => $this->baseUrl()]);

        $deliveries = [];
        $gone = false;
        for ($i = 0; $i < 60 && !$gone; $i++) {
            usleep(250000);
            $gone = !$this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return (bool) \Idno\Entities\User::getByHandle("mia") || \Idno\Core\Idno::site()->db()->countRecords(["owner" => ' . $this->literal($uuid) . ']) > 0;');
        }
        $this->assertTrue($gone, 'the posts and the account are gone without a second request');
        for ($i = 0; $i < 40 && $deliveries === []; $i++) {
            $deliveries = $this->site()->request('GET', '_test/sink/' . $sink . '/log')->json();
            usleep(250000);
        }
        $this->assertCount(1, $deliveries, 'exactly one delivery, of the Delete of the actor');
        $activity = json_decode((string) base64_decode($deliveries[0]['body'], true), true);
        $this->assertSame(['Delete', $this->baseUrl() . 'actor/mia'], [$activity['type'], $activity['object']]);
        $this->assertSame(0, (int) $this->runInSite('return \Idno\Common\Entity::countFromX(\IdnoPlugins\ActivityPub\Entities\ActivityPubFollower::class, []);'), 'and the follower records went once it was handed over');
    }
}
