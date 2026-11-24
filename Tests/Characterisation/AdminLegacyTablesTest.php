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
        use \Tests\Support\LogsIn;


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

    }

}
