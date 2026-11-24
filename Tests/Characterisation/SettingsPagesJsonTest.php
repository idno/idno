<?php

namespace Tests\Characterisation {

    /**
     * The pages under /admin/ and /account/ are drawn in the settings shell, a web page look. That override is for web pages only:
     * a page asked for as JSON is drawn with the JSON shell, as every other page is, over HTTP on the test site.
     */
    class SettingsPagesJsonTest extends CharacterisationTestCase
    {
        use \Tests\Support\LogsIn;

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

        /**
         * @return array<string, array{string}>
         */
        public static function webTypes(): array
        {
            return ['no type' => [''], 'default' => ['?_t=default'], 'modern' => ['?_t=modern']];
        }

        /**
         * @dataProvider webTypes
         */
        public function testAnAdminPageAsAWebPageUsesTheAdminShell(string $query): void
        {
            $cookie = $this->logIn($this->admin());

            $page = $this->site()->request('GET', 'admin/' . $query, ['Cookie' => $cookie]);

            $this->assertSame(200, $page->status);
            $this->assertStringContainsString('text/html', (string) $page->header('Content-Type'));
            $this->assertMatchesRegularExpression('/<div class="idno-admin-shell">/', $page->body);
        }

        /**
         * @dataProvider webTypes
         */
        public function testAnAccountPageAsAWebPageUsesTheAccountShell(string $query): void
        {
            $cookie = $this->logIn($this->user());

            $page = $this->site()->request('GET', 'account/settings/' . $query, ['Cookie' => $cookie]);

            $this->assertSame(200, $page->status);
            $this->assertStringContainsString('text/html', (string) $page->header('Content-Type'));
            $this->assertMatchesRegularExpression('/<div class="idno-account-shell">/', $page->body);
        }

        /**
         * A JSONP page is a script any site can load, with the visitor's cookie: the settings pages are never drawn as one, so the
         * URL shell override still applies to that type, in any letter case.
         *
         * @return array<string, array{string}>
         */
        public static function jsonpTypes(): array
        {
            return ['jsonp' => ['jsonp'], 'JSONP' => ['JSONP'], 'Jsonp' => ['Jsonp']];
        }

        /**
         * @dataProvider jsonpTypes
         */
        public function testAnAdminPageAsJsonpIsTheHtmlSettingsShell(string $type): void
        {
            $cookie = $this->logIn($this->admin());

            $page = $this->site()->request('GET', 'admin/?_t=' . $type . '&callback=steal', ['Cookie' => $cookie]);

            $this->assertSame(200, $page->status);
            $this->assertStringNotContainsString('javascript', (string) $page->header('Content-Type'));
            $this->assertStringNotContainsString('steal(', $page->body);
            $this->assertMatchesRegularExpression('/<div class="idno-admin-shell">/', $page->body);
        }

        /**
         * @dataProvider jsonpTypes
         */
        public function testAnAccountPageAsJsonpIsTheHtmlSettingsShell(string $type): void
        {
            $cookie = $this->logIn($this->admin());

            $page = $this->site()->request('GET', 'account/settings/?_t=' . $type . '&callback=steal', ['Cookie' => $cookie]);

            $this->assertSame(200, $page->status);
            $this->assertStringNotContainsString('javascript', (string) $page->header('Content-Type'));
            $this->assertStringNotContainsString('steal(', $page->body);
            $this->assertMatchesRegularExpression('/<div class="idno-account-shell">/', $page->body);
        }

    }

}
