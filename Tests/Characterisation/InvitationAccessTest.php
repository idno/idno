<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * An invitation's code is a credential: it lets whoever holds it create an account. An invitation is therefore not a post anyone may read: its address
 * (`/view/<id>`) is a missing page for a visitor and for a member, its JSON holds nothing, and a lookup finds it only for the registration page, which
 * compares the code with every invitation for the address.
 */
final class InvitationAccessTest extends PrivateSiteTestCase
{
    private const ID = '00000000000040008000000000000101';

    private const CODE = '0123456789abcdef0123456789abcd01';

    private const OTHER_CODE = '0123456789abcdef0123456789abcd02';

    private function seed(): void
    {
        $this->registerUser('admin');
        $this->addUser('member');
        $this->assertTrue($this->runInSite(str_replace(
            ['%CODE%', '%OTHER%', '%ID%'],
            [$this->literal(self::CODE), $this->literal(self::OTHER_CODE), $this->literal(self::ID)],
            <<<'PHP'
            $site = \Idno\Core\Idno::site();
            $site->db()->setIgnoreAccess(true);
            foreach ([[%ID%, %CODE%], ['00000000000040008000000000000102', %OTHER%]] as [$id, $code]) {
                $invitation = new \Idno\Entities\Invitation();
                $invitation->_id = $id;
                $invitation->email = 'dana@example.test';
                $invitation->code = $code;
                $invitation->created = (int) strtotime('2026-03-01 12:00:00 UTC');
                $invitation->save(true);
            }
            $site->config()->open_registration = false;
            $site->config()->save();

            return true;
            PHP
        )));
    }

    public function testAnInvitationIsAMissingPageForAVisitorAndAMember(): void
    {
        $this->seed();
        $missing = $this->site()->request('GET', 'view/00000000000040008000000000000999');
        $this->assertSame(410, $missing->status, 'a control: the page of an id nothing has');
        $viewers = ['a visitor' => [], 'a member' => ['Cookie' => $this->loginCookie('member', 'correct-horse-battery-9')]];
        foreach ($viewers as $who => $headers) {
            foreach (['view/' . self::ID, 'view/' . self::ID . '?_t=json', 'view/' . self::ID . '?_t=markdown', 'view/' . self::ID . '.md'] as $path) {
                $answer = $this->site()->request('GET', $path, $headers);
                $this->assertSame($missing->status, $answer->status, $who . ' asks for ' . $path);
                $this->assertStringNotContainsString(self::CODE, $answer->body, $who . ' asks for ' . $path);
                $this->assertStringNotContainsString('dana@example.test', $answer->body, $who . ' asks for ' . $path);
            }
        }
    }

    public function testAnAdministratorReadsAnInvitationAndItsJsonHoldsNothing(): void
    {
        $this->seed();
        $cookie = $this->loginCookie('admin', 'correct-horse-battery-9');
        $answer = $this->site()->request('GET', 'view/' . self::ID . '?_t=json', ['Cookie' => $cookie]);
        $this->assertLessThan(500, $answer->status);
        $this->assertStringNotContainsString(self::CODE, $answer->body);
        $this->assertStringNotContainsString(self::OTHER_CODE, $answer->body);
        $this->assertSame(
            ['admin' => true, 'member' => false, 'visitor' => false, 'json' => []],
            $this->runInSite(str_replace('%ID%', $this->literal(self::ID), <<<'PHP'
            \Idno\Core\Idno::site()->db()->setIgnoreAccess(true);
            $invitation = \Idno\Entities\Invitation::getByID(%ID%);

            return [
                'admin' => $invitation->canRead(\Idno\Entities\User::getByHandle('admin')->getUUID()),
                'member' => $invitation->canRead(\Idno\Entities\User::getByHandle('member')->getUUID()),
                'visitor' => $invitation->canRead(),
                'json' => $invitation->jsonSerialize(),
            ];
            PHP)),
        );
    }

    public function testTheCodeIsComparedWithEveryInvitationForTheAddress(): void
    {
        $this->seed();
        $found = $this->runInSite(<<<'PHP'
        $both = array_map(
            static fn ($code) => (bool) \Idno\Entities\Invitation::validate('dana@example.test', $code),
            ['0123456789abcdef0123456789abcd01', '0123456789abcdef0123456789abcd02', 'ffffffffffffffffffffffffffffffff', '', ['0123456789abcdef0123456789abcd01'], ['$lt' => 'g'], null]
        );
        $email = [
            (bool) \Idno\Entities\Invitation::validate(['dana@example.test'], '0123456789abcdef0123456789abcd01'),
            (bool) \Idno\Entities\Invitation::validate(['$gt' => 'a'], '0123456789abcdef0123456789abcd01'),
            (bool) \Idno\Entities\Invitation::validate('nobody@example.test', '0123456789abcdef0123456789abcd01'),
        ];

        return [$both, $email, \Idno\Entities\Invitation::getByEmailAndCode('dana@example.test', '0123456789abcdef0123456789abcd02')->code];
        PHP);
        $this->assertSame([[true, true, false, false, false, false, false], [false, false, false], self::OTHER_CODE], $found);
    }

    public function testRegistrationWithTheRightCodeStillMakesTheAccountAndUsesTheInvitationUp(): void
    {
        $this->seed();
        $form = $this->site()->request('GET', 'account/register?email=dana%40example.test&code=' . self::OTHER_CODE);
        $this->assertSame(200, $form->status, 'the form for an invitation that is right');
        $cookie = self::sessionCookie($form);
        $made = $this->site()->request('POST', 'account/register', ['Cookie' => $cookie], self::formToken($form->body, 'account/register') + [
            'name' => 'Dana', 'handle' => 'dana', 'email' => 'dana@example.test', 'password' => 'a-new-passphrase-77', 'password2' => 'a-new-passphrase-77', 'code' => self::OTHER_CODE,
        ]);
        $this->assertSame(302, $made->status);
        $this->assertSame(
            [false, true],
            $this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return [(bool) \Idno\Entities\Invitation::validate("dana@example.test", ' . $this->literal(self::OTHER_CODE) . '), (bool) \Idno\Entities\User::getByHandle("dana")];'),
        );
    }

    public function testAnInvitationIsStoredForAdministratorsAlone(): void
    {
        $this->seed();
        $this->assertSame(
            'INVITATION',
            $this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return (string) \Idno\Entities\Invitation::getByID(' . $this->literal(self::ID) . ')->access;')
        );
    }

    /**
     * Catches an invitation stored by an earlier Idno (access PUBLIC) being returned by a listing to a visitor, in the home stream asked
     * for the invitation's class by name and in a finder that does not call canRead(). Until something reads the invitation (the
     * administrators' list, the registration lookup) a finder still returns it: that is the one listing left open, in code that
     * calls the finder itself.
     */
    public function testAnInvitationStoredWithPublicAccessIsInNoListingForAVisitor(): void
    {
        $this->seed();
        $this->runInSite(str_replace('%ID%', $this->literal(self::ID), <<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->db()->setIgnoreAccess(true);
        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        $record = $store->findById('entities', %ID%);
        $record['access'] = 'PUBLIC';
        $record['publish_status'] = 'published';
        $store->save('entities', $record);

        return true;
        PHP));
        $listed = $this->site()->request('GET', '/?q=dana&_t=json&types[]=' . rawurlencode('Idno\\Entities\\Invitation'));
        $this->assertStringNotContainsString(self::CODE, $listed->body);
        $this->assertStringNotContainsString('dana@example.test', $listed->body);
        $this->assertSame(
            [1, 0],
            $this->runInSite('$before = count(\Idno\Entities\Invitation::get([], [], 10)); \Idno\Entities\Invitation::all(); return [$before, count(\Idno\Entities\Invitation::get([], [], 10))];')
        );
    }

    /**
     * Catches an administrator's `remove_invitation` deleting a post whose id was posted instead of an invitation's.
     */
    public function testRemoveInvitationRemovesAnInvitationAndNothingElse(): void
    {
        $this->seed();
        $admin = $this->loginCookie('admin', 'correct-horse-battery-9');
        $post = $this->publishStatus($admin, 'A post that is not an invitation');
        $postId = $this->runInSite('$s = \IdnoPlugins\Status\Status::getOne(); return $s->getID();');
        $token = self::formToken($this->site()->request('GET', 'admin/users', ['Cookie' => $admin])->body, 'admin/users')['__bTk'];
        $this->site()->request('POST', 'admin/users', ['Cookie' => $admin], ['__bTk' => $token, 'action' => 'remove_invitation', 'invitation_id' => $postId]);
        $this->assertSame(200, $this->site()->request('GET', str_starts_with($post, $this->baseUrl()) ? substr($post, strlen($this->baseUrl())) : $post)->status, 'the post is still there');
        $this->site()->request('POST', 'admin/users', ['Cookie' => $admin], ['__bTk' => $token, 'action' => 'remove_invitation', 'invitation_id' => self::ID]);
        $this->assertFalse($this->runInSite('\Idno\Core\Idno::site()->db()->setIgnoreAccess(true); return (bool) \Idno\Entities\Invitation::getByID(' . $this->literal(self::ID) . ');'));
    }
}
