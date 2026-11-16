<?php

namespace Tests\Characterisation {

    /**
     * The admin page of what an upgrade from Idno 1.x left behind (/admin/legacytables), over HTTP on
     * the test site. The test site was never a 1.x site: it has no legacy tables and no rejects on
     * any rig (MySQL, MariaDB, SQLite), so this pins who may see the page, its empty state, and that
     * nothing but a confirmed, CSRF-signed POST of an admin acts. The page with legacy tables, rejects
     * and the delete is tested on an upgraded 1.x site in Tests/Upgrade/UpgradeEndToEndTest.
     *
     * Requests carry no cookies of their own: a logged-in user is logged in over HTTP here (the login
     * form, its CSRF token and the session cookie), with a password this test sets.
     */
    class AdminLegacyTablesTest extends CharacterisationTestCase
    {

        public function testAnonymousVisitorsGetWhatOtherAdminPagesGive(): void
        {
            $other = $this->site()->request('GET', 'admin/plugins/');
            $page  = $this->site()->request('GET', 'admin/legacytables/');

            $this->assertSame(403, $other->status);
            $this->assertSame($other->status, $page->status);
            $this->assertNull($page->header('Location'));
            $this->assertStringNotContainsString('legacytables-empty', $page->body);
        }

        public function testAnAnonymousPostChangesNothing(): void
        {
            $other = $this->site()->request('POST', 'admin/plugins/', [], ['plugin' => 'Status', 'plugin_action' => 'uninstall']);
            $post  = $this->site()->request('POST', 'admin/legacytables/', [], ['confirm' => 'delete']);

            $this->assertSame($other->status, $post->status);
            $this->assertSame($other->header('Location'), $post->header('Location'));
        }

        public function testNonAdminsGetWhatOtherAdminPagesGive(): void
        {
            $cookie = $this->logIn($this->user());

            $other = $this->site()->request('GET', 'admin/plugins/', ['Cookie' => $cookie]);
            $page  = $this->site()->request('GET', 'admin/legacytables/', ['Cookie' => $cookie]);

            $this->assertSame(403, $other->status);
            $this->assertSame($other->status, $page->status);
            $this->assertStringNotContainsString('legacytables-empty', $page->body);
        }

        public function testAnAdminSeesTheEmptyStateAndNoMenuEntry(): void
        {
            $cookie = $this->logIn($this->admin());

            $page = $this->site()->request('GET', 'admin/legacytables/', ['Cookie' => $cookie]);

            $this->assertSame(200, $page->status, substr($page->body, 0, 2000));
            $this->assertStringContainsString('id="legacytables-empty"', $page->body);
            $this->assertStringNotContainsString('id="legacytables-tables"', $page->body);
            $this->assertStringNotContainsString('name="confirm"', $page->body, 'nothing to delete, no form');

            $admin = $this->site()->request('GET', 'admin/', ['Cookie' => $cookie]);
            $this->assertSame(200, $admin->status);
            $this->assertStringNotContainsString('admin/legacytables', $admin->body, 'the menu shows the entry only while there is something to see');
        }

        public function testAnAdminsPostWithoutACsrfTokenChangesNothingAndSaysSo(): void
        {
            $cookie = $this->logIn($this->admin());

            $post = $this->site()->request('POST', 'admin/legacytables/', ['Cookie' => $cookie], ['confirm' => 'delete']);
            $this->assertContains($post->status, [200, 302, 303], substr($post->body, 0, 2000));

            $this->assertStringNotContainsString('There were no legacy tables to delete.', $post->body, 'postContent() was not reached');

            $page = $this->site()->request('GET', 'admin/legacytables/', ['Cookie' => $cookie]);
            $this->assertSame(200, $page->status);
            $this->assertStringContainsString('Invalid token.', $page->body);
            $this->assertStringNotContainsString('There were no legacy tables to delete.', $page->body, 'postContent() was not reached');
            $this->assertStringContainsString('id="legacytables-empty"', $page->body);
        }

        public function testAnAdminsSignedPostWithoutTheConfirmationDeletesNothingAndSaysSo(): void
        {
            $cookie = $this->logIn($this->admin());
            // The empty page has no form; a CSRF token is the session's, valid for any form's action
            // (Page::post() accepts the one the form names), so the plugins page's is used.
            $token  = $this->formToken($this->site()->request('GET', 'admin/plugins/', ['Cookie' => $cookie])->body, 'admin/plugins/');

            $post = $this->site()->request('POST', 'admin/legacytables/', ['Cookie' => $cookie], $token + ['confirm' => 'yes']);
            $this->assertSame(302, $post->status, substr($post->body, 0, 2000));
            $this->assertSame($this->site()->baseUrl() . 'admin/legacytables/', $post->header('Location'));

            $page = $this->site()->request('GET', 'admin/legacytables/', ['Cookie' => $cookie]);
            $this->assertStringContainsString('Nothing was deleted: type delete in the box to confirm.', $page->body);

            $post = $this->site()->request('POST', 'admin/legacytables/', ['Cookie' => $cookie], $token + ['confirm' => 'delete']);
            $this->assertSame(302, $post->status);
            $page = $this->site()->request('GET', 'admin/legacytables/', ['Cookie' => $cookie]);
            $this->assertStringContainsString('There were no legacy tables to delete.', $page->body);
        }

        /**
         * Logs the user in through the login form, with a new password set for this test (the test
         * users' passwords are random), and returns the session cookie (`name=value`).
         */
        private function logIn(\Idno\Entities\User $user): string
        {
            $password = bin2hex(random_bytes(12));
            $user->setPassword($password);
            $user->save(true);

            $form   = $this->site()->request('GET', 'session/login');
            $cookie = $this->sessionCookie($form) ?? '';
            $this->assertNotSame('', $cookie, 'the login form starts a session');
            $login = $this->site()->request(
                'POST',
                'session/login',
                ['Cookie' => $cookie],
                $this->formToken($form->body, 'session/login') + ['email' => $user->handle, 'password' => $password],
            );
            $this->assertSame(302, $login->status, substr($login->body, 0, 2000));
            $cookie = $this->sessionCookie($login) ?? $cookie;

            $check = $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie]);
            $this->assertSame(200, $check->status, 'logged in');

            return $cookie;
        }

        /**
         * The session cookie a response sets, as `name=value`, or null.
         */
        private function sessionCookie(SiteResponse $response): ?string
        {
            $name = (string) \Idno\Core\Idno::site()->config()->sessionname;
            if (preg_match_all('/(?:^|[,;]\s*)' . preg_quote($name, '/') . '=([^;,\s]+)/', (string) $response->header('Set-Cookie'), $m) > 0) {
                return $name . '=' . end($m[1]);
            }

            return null;
        }

        /**
         * The CSRF token fields of the form in $html whose action ends with $action.
         *
         * @return array<string, string>
         */
        private function formToken(string $html, string $action): array
        {
            $forms = preg_split('/<form\b/i', $html) ?: [];
            foreach ($forms as $form) {
                if (preg_match('/^[^>]*action="[^"]*' . preg_quote($action, '/') . '"/', $form) !== 1) {
                    continue;
                }
                $fields = [];
                foreach (['__bTs', '__bTk', '__bTa'] as $field) {
                    if (preg_match('/name="' . $field . '" value="([^"]*)"/', $form, $m) === 1) {
                        $fields[$field] = html_entity_decode($m[1], ENT_QUOTES);
                    }
                }
                if (count($fields) === 3) {
                    return $fields;
                }
            }
            $this->fail('No signed form posting to ' . $action . ' in the page.');
        }

    }

}
