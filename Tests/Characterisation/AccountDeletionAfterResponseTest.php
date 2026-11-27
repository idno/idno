<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * On a site with the synchronous queue the administrator's request deletes an account for a bounded time (here none: one entity), and the rest of the
 * job continues after the response, with no button to press. Catches an account left closed, with its posts stored, until somebody presses "Continue deleting".
 */
final class AccountDeletionAfterResponseTest extends PrivateSiteTestCase
{
    protected function siteEnvironment(): array
    {
        return parent::siteEnvironment() + ['IDNO_ACCOUNT_REMOVAL_SECONDS' => '0'];
    }

    public function testTheJobContinuesAfterTheResponseUntilTheAccountIsGone(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $admin = $this->loginCookie('ada', 'correct-horse-battery-9');
        $token = (string) $this->site()->request('GET', '/service/security/csrftoken/', ['Cookie' => $admin, 'X-Requested-With' => 'XMLHttpRequest'])->json()['token'];
        $uuid = $this->addUser('mia');
        $this->runInSite(<<<'PHP'
        \Idno\Core\Idno::site()->db()->setIgnoreAccess(true);
        foreach (range(1, 6) as $n) {
            $post = new \IdnoPlugins\Status\Status();
            $post->body = 'post ' . $n;
            $post->setOwner(\Idno\Entities\User::getByHandle('mia'));
            $post->setAccess('PUBLIC');
            $post->save(true);
        }
        return true;
        PHP);

        $this->site()->request('POST', 'admin/users', ['Cookie' => $admin], ['__bTk' => $token, 'action' => 'delete', 'user' => $uuid]);

        $gone = false;
        for ($i = 0; $i < 60 && !$gone; $i++) {
            usleep(250000);
            $gone = !$this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return (bool) \Idno\Entities\User::getByHandle("mia") || \Idno\Core\Idno::site()->db()->countRecords(["owner" => ' . $this->literal($uuid) . ']) > 0;');
        }
        $this->assertTrue($gone, 'the posts and the account are gone without a second request');
    }
}
