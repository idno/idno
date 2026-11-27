<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * The worker's job queues itself again while it removes rows. Catches a chain that stops after the first message (the account left closed with posts stored)
 * or that never stops: with no time for more than one entity in a message, one run of the worker still ends with the account gone and nothing failed.
 */
final class AccountDeletionWorkerChainTest extends AsyncSiteTestCase
{
    protected function siteEnvironment(): array
    {
        return parent::siteEnvironment() + ['IDNO_ACCOUNT_REMOVAL_SECONDS' => '0'];
    }

    public function testAMessageThatRemovesOneEntityQueuesTheNextUntilTheAccountIsGone(): void
    {
        $admin = $this->registerUser('alice');
        $this->addUser('mia');
        $token = (string) $this->site()->request('GET', '/service/security/csrftoken/', ['Cookie' => $admin, 'X-Requested-With' => 'XMLHttpRequest'])->json()['token'];
        $uuid = (string) $this->runInSite(<<<'PHP'
        \Idno\Core\Idno::site()->db()->setIgnoreAccess(true);
        $mia = \Idno\Entities\User::getByHandle('mia');
        foreach (range(1, 4) as $n) {
            $post = new \IdnoPlugins\Status\Status();
            $post->body = 'post ' . $n;
            $post->setOwner($mia);
            $post->setAccess('PUBLIC');
            $post->save(true);
        }
        return $mia->getUUID();
        PHP);
        $this->assertSame(0, $this->runWorkerOnce()['exit']);
        $this->site()->request('POST', 'admin/users', ['Cookie' => $admin], ['__bTk' => $token, 'action' => 'delete', 'user' => $uuid]);

        $worker = $this->runWorkerOnce();

        $this->assertSame(0, $worker['exit'], $worker['output']);
        $this->assertSame([], $this->failedEvents());
        $this->assertSame([], $this->queuedEvents());
        $this->assertFalse($this->runInSite('return (bool) \Idno\Entities\User::getByHandle("mia");'), 'the account is gone');
        $this->assertSame(0, (int) $this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return \Idno\Core\Idno::site()->db()->countRecords(["owner" => ' . $this->literal($uuid) . ']);'));
    }
}
