<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * Deleting an account on a site with the asynchronous queue: the account is closed at once, its content is removed by a worker, and the servers of its
 * followers are told once that the actor is gone (never one Delete a post, and never a delivery that needs the account, which is deleted by the time
 * the worker runs).
 */
final class AccountDeletionQueuedTest extends AsyncSiteTestCase
{
    private const PASSWORD = 'correct-horse-battery-9';

    /**
     * Catches the failure of the first version of this delete: a Delete queued for each post and each inbox, to be run as an account that is gone
     * when the worker runs, so that the failure transport fills up and the followers hear nothing. The followers get exactly one actor `Delete`, signed
     * with the member's key, the failure transport stays empty, the follower records and the content are gone, and the account is gone last.
     */
    public function testTheWorkerRemovesTheContentAndTheFollowersAreToldOnceThatTheActorIsGone(): void
    {
        $admin = $this->registerUser('alice');
        $this->addUser('mia', self::PASSWORD);
        $token = (string) $this->site()->request('GET', '/service/security/csrftoken/', ['Cookie' => $admin, 'X-Requested-With' => 'XMLHttpRequest'])->json()['token'];
        $publicKey = (string) $this->runInSite('$u = \Idno\Entities\User::getByHandle("mia"); $u->getPrivateKey(); return $u->getPublicKeyPem();');
        $this->runInSite(<<<'PHP'
        \Idno\Core\Idno::site()->db()->setIgnoreAccess(true);
        $mia = \Idno\Entities\User::getByHandle('mia');
        foreach (range(1, 4) as $n) {
            $post = new \IdnoPlugins\Status\Status();
            $post->body = 'post ' . $n;
            $post->setOwner($mia);
            $post->setAccess('PUBLIC');
            $post->save(true);
        }
        return true;
        PHP);
        $sinks = [$this->newSink('inbox'), $this->newSink('inbox')];
        $this->addFollower('mia', $this->baseUrl() . '_test/sink/' . $sinks[0]);
        $this->addFollower('mia', $this->baseUrl() . '_test/sink/' . $sinks[1], 'https://other.example/users/carol');
        $this->assertSame(0, $this->runWorkerOnce()['exit']);
        foreach ($sinks as $sink) {
            $this->sink($sink); // what the posts sent while they were made is not what is tested
        }
        $actor = $this->baseUrl() . 'actor/mia';

        $answer = $this->site()->request('POST', 'admin/users', ['Cookie' => $admin], ['__bTk' => $token, 'action' => 'delete', 'user' => $this->uuidOf('mia')]);

        $this->assertSame(302, $answer->status);
        $this->assertTrue($this->runInSite('return \Idno\Entities\User::getByHandle("mia")->isClosed();'), 'the account is closed in the request, before any worker runs');
        $this->assertSame([[], []], [$this->sink($sinks[0]), $this->sink($sinks[1])], 'nothing was sent from the request');
        $events = array_column($this->queuedEvents(), 'event');
        $this->assertContains('account/remove', $events);
        foreach ($this->queuedEvents() as $event) {
            if ($event['event'] === 'account/remove') {
                $this->assertNull($event['runAsContext'], 'the job runs as nobody: the administrator who asked may be gone when it runs');
            }
        }

        $worker = $this->runWorkerOnce();
        $this->assertSame(0, $worker['exit'], $worker['output']);

        $this->assertSame([], $this->failedEvents(), 'nothing failed');
        $this->assertSame([], $this->queuedEvents(), 'nothing is left waiting');
        foreach ($sinks as $sink) {
            $requests = $this->sink($sink);
            $this->assertCount(1, $requests, 'one delivery to each follower\'s inbox, and nothing for the posts');
            $request = $requests[0];
            $body = (string) base64_decode($request['body'], true);
            $activity = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('Delete', $activity['type']);
            $this->assertSame($actor, $activity['actor']);
            $this->assertSame($actor, $activity['object'], 'the object of the Delete is the actor itself');
            $this->assertSame('SHA-256=' . base64_encode(hash('sha256', $body, true)), $request['headers']['digest']);
            $this->assertSame(1, preg_match('/keyId="([^"]+)",algorithm="rsa-sha256",headers="([^"]+)",signature="([A-Za-z0-9+\/=]+)"/', $request['headers']['signature'], $m), $request['headers']['signature']);
            $this->assertSame($actor . '#main-key', $m[1]);
            $signingString = '(request-target): post /_test/sink/' . $sink . "\nhost: " . $request['headers']['host'] . "\ndate: " . $request['headers']['date'] . "\ndigest: " . $request['headers']['digest'] . "\ncontent-type: " . $request['headers']['content-type'];
            $this->assertSame(1, openssl_verify($signingString, (string) base64_decode($m[3], true), $publicKey, OPENSSL_ALGO_SHA256), 'signed with the account\'s own key');
        }
        $this->assertSame(0, $this->runInSite('return \Idno\Common\Entity::countFromX(\IdnoPlugins\ActivityPub\Entities\ActivityPubFollower::class, []);'), 'the follower records went with the account');
        $this->assertFalse($this->runInSite('return (bool) \Idno\Entities\User::getByHandle("mia");'), 'the account is gone');
        $this->assertSame(0, (int) $this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return \Idno\Common\Entity::countFromX(\IdnoPlugins\Status\Status::class, []);'), 'and so are its posts');
    }

    private function uuidOf(string $handle): string
    {
        return (string) $this->runInSite('return \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ')->getUUID();');
    }
}
