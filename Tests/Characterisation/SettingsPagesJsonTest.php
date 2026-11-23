<?php

namespace Tests\Characterisation {

    /**
     * The pages under /admin/ and /account/ are drawn in the settings shell, a web page look. That override is for web pages only:
     * a page asked for as JSON is drawn with the JSON shell, as every other page is, over HTTP on the test site.
     */
    class SettingsPagesJsonTest extends CharacterisationTestCase
    {
        public function testJsonOnAnAdminPageIsDrawnWithTheJsonShell(): void
        {
            $cookie = $this->logIn($this->admin());

            $page = $this->site()->request('GET', 'admin/?_t=json', ['Cookie' => $cookie]);

            $this->assertSame(200, $page->status, substr($page->body, 0, 1000));
            $this->assertStringContainsString('application/json', (string) $page->header('Content-Type'));
            $this->assertIsArray(json_decode($page->body, true), substr($page->body, 0, 1000));
        }

        public function testJsonOnAnAccountPageIsDrawnWithTheJsonShell(): void
        {
            $cookie = $this->logIn($this->user());

            $page = $this->site()->request('GET', 'account/settings/?_t=json', ['Cookie' => $cookie]);

            $this->assertSame(200, $page->status, substr($page->body, 0, 1000));
            $this->assertStringContainsString('application/json', (string) $page->header('Content-Type'));
            $this->assertIsArray(json_decode($page->body, true), substr($page->body, 0, 1000));
        }

        public function testAnAdminPageAsAWebPageStillUsesTheSettingsShell(): void
        {
            $cookie = $this->logIn($this->admin());

            $page = $this->site()->request('GET', 'admin/', ['Cookie' => $cookie]);

            $this->assertSame(200, $page->status);
            $this->assertStringContainsString('text/html', (string) $page->header('Content-Type'));
            $this->assertStringNotContainsString('application/json', (string) $page->header('Content-Type'));
        }

        /**
         * Logs the user in through the login form, with a new password set for this test (the test users' passwords are
         * random), and returns the session cookie (`name=value`).
         */
        private function logIn(\Idno\Entities\User $user): string
        {
            $password = bin2hex(random_bytes(12));
            $user->setPassword($password);
            $user->save(true);

            return (new \Tests\Acceptance\Browser($this->site()))->login($user->handle, $password);
        }
    }

}
