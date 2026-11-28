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

        public static function addressesWithAPassword(): array
        {
            return [
                'a slash in the password' => ['mysql://someone:hid/den-30@db.example.com/app', 'mysql://', '@db.example.com/app'],
                'a question mark' => ['mongodb://someone:hid?den-31@db.example.com:27017', 'mongodb://', '@db.example.com:27017'],
                'a hash' => ['postgres://someone:hid#den-32@db.example.com/app', 'postgres://', '@db.example.com/app'],
                'an @ and a slash' => ['smtp://someone:hid@d/en-33@mail.example.com:587', 'smtp://', '@mail.example.com:587'],
                'an email address as the user name' => ['smtp://someone@example.org:hidden-34@mail.example.com', 'smtp://', '@mail.example.com'],
                'no user name' => ['redis://:hidden-35@cache.example.com:6379/0', 'redis://', '@cache.example.com:6379/0'],
                'a scheme inside a scheme' => ['jdbc:mysql://someone:hidden-36@db.example.com/app', 'jdbc:mysql://', '@db.example.com/app'],
                'words before the address' => ['see mysql://someone:hidden-37@db.example.com/app', 'see mysql://', '@db.example.com/app'],
            ];
        }

        /**
         * Catches: a database address whose password holds a "/" (or whose user name is an email
         * address) being printed whole in the report and in the export's config.json.
         *
         * @dataProvider addressesWithAPassword
         */
        public function testAPasswordInAnAddressIsRemovedWhateverItHolds(string $address, string $start, string $end)
        {
            $redacted = Config::redactSecrets(['some_url' => $address, 'list' => [$address]]);

            $this->assertSame($start . Config::REDACTED . $end, $redacted['some_url']);
            $this->assertSame($redacted['some_url'], $redacted['list'][0]);
        }

        // Catches: every address but the last of a value that holds several keeping its password
        public function testEveryAddressOfAValueLosesItsPassword()
        {
            $redacted = Config::redactSecrets(['servers' => 'redis://one:hidden-38@a.example.com redis://two:hidden-39@b.example.com']);

            $this->assertSame('redis://' . Config::REDACTED . '@a.example.com redis://' . Config::REDACTED . '@b.example.com', $redacted['servers']);
        }

        // Catches: a report that hides addresses that hold no password (a profile address with
        // an @ in its path, an email address), which whoever reads the report needs
        public function testAnAddressWithoutAPasswordIsShown()
        {
            $shown = [
                'profile' => 'https://social.example.com/@someone',
                'page' => 'https://example.com/path?next=/a/b#top',
                'with_port' => 'http://localhost:8080/admin/',
                'email' => 'someone@example.com',
                'mailto' => 'mailto:someone@example.com',
            ];

            $this->assertSame($shown, Config::redactSecrets($shown));
        }

        // Catches: the report hiding the folder the site runs in (the shell's PWD and OLDPWD
        // are no passwords), while a password named *_PWD stays hidden
        public function testTheWorkingFolderIsShownAndAPasswordNamedPwdIsNot()
        {
            $redacted = Config::redactServerVariables([
                'PWD' => '/var/www/idno', 'OLDPWD' => '/var/www', 'REDIRECT_PWD' => '/var/www/idno',
                'MYSQL_PWD' => 'hidden-40', 'REDIRECT_MYSQL_PWD' => 'hidden-41', 'DB_PW' => 'hidden-42',
            ]);

            $this->assertSame('/var/www/idno', $redacted['PWD']);
            $this->assertSame('/var/www', $redacted['OLDPWD']);
            $this->assertSame('/var/www/idno', $redacted['REDIRECT_PWD']);
            $this->assertStringNotContainsString('hidden-', json_encode($redacted));
        }

        // Catches: a report made while a member was connecting an account giving away the key
        // the session holds for that
        public function testTheReportHidesSecretsTheSessionHolds()
        {
            $session = ['user_uuid' => 'https://example.com/profile/someone', 'pending_auth' => ['state' => 'abc', 'dpop_key' => ['private' => 'hidden-50']], 'api_token' => 'hidden-51'];
            $report = Diagnostics::report(['status' => 'Ok', 'report' => []], $session, []);

            $this->assertStringNotContainsString('hidden-', $report);
            $this->assertStringContainsString('profile\/someone', $report, 'Who the session belongs to is still shown.');
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
