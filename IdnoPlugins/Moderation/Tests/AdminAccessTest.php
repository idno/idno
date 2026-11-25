<?php

namespace IdnoPlugins\Moderation\Tests {

    use IdnoPlugins\Moderation\Entities\ModerationCase;

    /**
     * The moderation pages answer administrators in a browser and nobody else: not visitors,
     * not members, and not a request signed with an administrator's API key. That holds for
     * every address and every template type.
     */
    class AdminAccessTest extends ModerationTestCase
    {

        private static $author;
        private static $reporter;
        private static $admin;
        private static $case;
        private static $entry;
        private static $marker;

        public function setUp(): void
        {
            parent::setUp();
            if (!static::$author) {
                static::$author = static::member('accauthor');
                static::$reporter = static::member('accreporter');
                static::$admin = static::member('accadmin', true);
                static::$marker = static::marker();
                static::$entry = $this->post(static::$author);
                static::$case = $this->report(static::$reporter, 'post', static::$entry, 'spam', 'reporterwords ' . static::$marker)['case'];
            }
        }

        public static function tearDownAfterClass(): void
        {
            parent::tearDownAfterClass();
            static::$author = static::$reporter = static::$admin = static::$case = static::$entry = static::$marker = null;
        }

        private function addresses()
        {
            return [
                '/admin/moderation/',
                '/admin/moderation/?tab=resolved',
                '/admin/moderation/?tab=banned',
                '/admin/moderation/?tab=log',
                '/admin/moderation/case/' . static::$case->getID(),
                '/admin/moderation/case/' . static::$case->getID() . '/action',
                '/admin/moderation/member/' . static::$author->getID(),
                '/admin/moderation/member/' . static::$reporter->getID(),
                '/admin/moderation/settings',
            ];
        }

        public function testControlAnAdministratorInABrowserGetsThePages()
        {
            foreach ($this->addresses() as $address) {
                $this->assertContains($this->get($address, static::$admin)['status'], [200, 302], "$address should answer an administrator.");
            }
        }

        public function testEveryoneElseIsRefusedForEveryTemplateType()
        {
            $viewers = [
                'a visitor' => [null, []],
                'the reported member' => [static::$author, []],
                'the reporter' => [static::$reporter, []],
            ];
            foreach ($this->addresses() as $address) {
                foreach (['', '_t=json', '_t=jsonp', '_t=rss', '_t=modern', '_t=default'] as $type) {
                    $url = $type === '' ? $address : $address . (strpos($address, '?') === false ? '?' : '&') . $type;
                    foreach ($viewers as $who => [$as, $headers]) {
                        $this->assertRefused($this->get($url, $as, $headers), "$url as $who");
                    }
                    // An administrator's API key is not an administrator
                    $this->assertRefused($this->get($url, null, $this->signature(static::$admin, $url)), "$url signed with an administrator's API key");
                }
            }
        }

        private function assertRefused($result, $what)
        {
            $this->assertEquals(403, $result['status'], "$what should be refused.");
            // (The 403 page repeats the address that was asked for, so the id of the case is not a secret here)
            foreach ([static::$marker, 'reporterwords', 'mod-case', 'mod-item"'] as $secret) {
                $this->assertStringNotContainsString($secret, $result['body'], "$what must give nothing out.");
            }
        }

        public function testMembersCannotAct()
        {
            $path = '/admin/moderation/case/' . static::$case->getID() . '/action';
            // A form token of their own, from a page they can open
            foreach ([static::$author, static::$reporter] as $member) {
                $tokens = $this->formFields($this->get('/account/settings/', $member)['body']);
                $this->assertCount(3, $tokens);
                foreach (['dismiss', 'remove', 'ban', 'note'] as $action) {
                    $result = $this->request('POST', $path, $tokens + ['action' => $action, 'revision' => static::freshCase(static::$case)->revision, 'note' => 'hello'], $member);
                    $this->assertEquals(403, $result['status']);
                }
                $ban = $this->request('POST', '/admin/moderation/member/' . static::$reporter->getID(), $tokens + ['action' => 'ban'], $member);
                $this->assertEquals(403, $ban['status']);
                $settings = $this->request('POST', '/admin/moderation/settings', $tokens + ['moderation_contact_email' => 'evil@example.com'], $member);
                $this->assertEquals(403, $settings['status']);
            }
            // And with an administrator's API key, which needs no form token
            foreach (['dismiss', 'remove', 'ban'] as $action) {
                $this->request('POST', $path, ['action' => $action, 'revision' => static::freshCase(static::$case)->revision], null, $this->signature(static::$admin, $path));
            }

            $case = static::freshCase(static::$case);
            $this->assertEquals(ModerationCase::OPEN, $case->status);
            $this->assertEmpty($case->notes);
            $this->assertFalse(static::fresh(static::$reporter)->isBanned());
            $this->assertFalse(static::fresh(static::$author)->isBanned());
            $this->assertNotEquals('evil@example.com', \Idno\Core\Idno::site()->config()->moderation_contact_email);
            $this->assertEquals(200, $this->get(static::$entry->getDisplayURL())['status']);
        }
    }

}
