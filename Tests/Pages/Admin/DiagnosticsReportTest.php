<?php

namespace Tests\Pages\Admin {

    use Idno\Core\Config;
    use Idno\Pages\Admin\Diagnostics;

    /**
     * The detailed report of the Diagnostics page is made to be passed on to whoever helps
     * with a problem, so it must not hold the site's secrets. A site can be configured with
     * environment variables (IDNO_DBPASS, IDNO_SITE_SECRET ...), which PHP lists among the
     * server variables the report prints: the report names them, and never shows the value of
     * one that is a secret. A setting that is none (the site's name) is shown.
     *
     * The last test makes its requests over HTTP, so it needs the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class DiagnosticsReportTest extends \Tests\BrowserTestCase
    {

        /**
         * Server variables as PHP has them on a site configured through its environment
         */
        private function serverVariables()
        {
            return [
                'IDNO_DBPASS' => 'hidden-01',
                'IDNO_SMTP_PASSWORD' => 'hidden-02',
                'IDNO_SITE_SECRET' => 'hidden-03',
                'IDNO_DATABASE_URL' => 'mysql://someone:hidden-04@db.example.com:3306/idno',
                'IDNO_DBSTRING' => 'hidden-05',
                'KNOWN_DBPASS' => 'hidden-06',
                'KNOWN_PROXY_STRING' => 'someone:hidden-07@proxy.example.com:3128',
                // Settings that are no secrets
                'IDNO_TITLE' => 'A site',
                'KNOWN_URL' => 'https://example.com/',
                'IDNO_FORM_TOKEN_EXPIRY' => '3600',
                // Apache gives a variable this name after an internal redirect
                'REDIRECT_IDNO_DBPASS' => 'hidden-08',
                'REDIRECT_REDIRECT_KNOWN_DBPASS' => 'hidden-09',
                'IDNO_EMPTY' => '',
                // What the request itself carried
                'HTTP_COOKIE' => 'idno=hidden-10',
                'HTTP_AUTHORIZATION' => 'Bearer hidden-11',
                'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer hidden-12',
                'PHP_AUTH_USER' => 'someone',
                'PHP_AUTH_PW' => 'hidden-13',
                'PHP_AUTH_DIGEST' => 'username="someone", response="hidden-14"',
                'HTTP_X_IDNO_SIGNATURE' => 'hidden-15',
                'HTTP_X_KNOWN_SIGNATURE' => 'hidden-16',
                // Secrets of other software in the same environment
                'DATABASE_URL' => 'postgres://someone:hidden-17@db.example.com/app',
                'CLOUDCUBE_SECRET_ACCESS_KEY' => 'hidden-18',
                'MYSQL_PWD' => 'hidden-19',
                'MAIL_URL' => 'smtp://someone:hid@den-20@mail.example.com:587',
                'argv' => ['index.php', 'https://someone:hidden-21@example.com/'],
                // And what a reader of the report needs
                'SERVER_SOFTWARE' => 'nginx/1.27.0',
                'REQUEST_URI' => '/admin/diagnostics/',
                'HTTP_HOST' => 'example.com',
            ];
        }

        // Catches: a report shared for help giving away the database password, the site secret
        // or any other secret that the site takes from its environment
        public function testTheReportNamesSecretEnvironmentSettingsWithoutTheirValues()
        {
            $server = $this->serverVariables();
            $report = Diagnostics::report(['status' => 'Ok', 'report' => []], [], $server);

            $this->assertStringNotContainsString('hidden-', $report);
            $this->assertStringNotContainsString('den-20', $report, 'A password with an @ in it is removed whole.');
            foreach (array_keys($server) as $name) {
                $this->assertStringContainsString('"' . $name . '"', $report, 'The report should still say that ' . $name . ' is set.');
            }
        }

        // Catches: a report that no longer helps, because what is not a secret was removed too
        public function testTheReportKeepsWhatIsNotASecret()
        {
            $redacted = Config::redactServerVariables($this->serverVariables());

            $this->assertSame('nginx/1.27.0', $redacted['SERVER_SOFTWARE']);
            $this->assertSame('/admin/diagnostics/', $redacted['REQUEST_URI']);
            $this->assertSame('example.com', $redacted['HTTP_HOST']);
            $this->assertSame('someone', $redacted['PHP_AUTH_USER']);
            $this->assertSame('', $redacted['IDNO_EMPTY'], 'A variable that is set to nothing is shown as empty.');
            $this->assertSame('A site', $redacted['IDNO_TITLE']);
            $this->assertSame('https://example.com/', $redacted['KNOWN_URL']);
            $this->assertSame('3600', $redacted['IDNO_FORM_TOKEN_EXPIRY'], 'How long a token lasts is not a secret.');
            $this->assertSame('mysql://' . Config::REDACTED . '@db.example.com:3306/idno', $redacted['IDNO_DATABASE_URL']);
            $this->assertSame('postgres://' . Config::REDACTED . '@db.example.com/app', $redacted['DATABASE_URL']);
            $this->assertSame('index.php', $redacted['argv'][0]);
        }

        // Catches: the page printing the server variables of its own request as they are, with
        // the administrator's session cookie and credentials in them
        public function testTheReportThePageGivesHidesWhatTheRequestCarried()
        {
            $admin = static::member('diagadmin', true);
            $page = $this->get('/admin/diagnostics/', $admin, ['X-Requested-With: XMLHttpRequest', 'Authorization: Bearer report-credential-1']);

            $this->assertSame(200, $page['status']);
            $this->assertStringContainsString('$_SERVER', $page['body']);
            $this->assertStringContainsString('HTTP_AUTHORIZATION', $page['body']);
            $this->assertStringNotContainsString('report-credential-1', $page['body']);
            $this->assertMatchesRegularExpression('/"HTTP_COOKIE": "' . preg_quote(Config::REDACTED, '/') . '"/', $page['body']);
        }

    }

}
