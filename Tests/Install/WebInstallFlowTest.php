<?php

declare(strict_types=1);

namespace Tests\Install;

use Idno\Install\SetupToken;
use Idno\Install\WebInstaller;

/**
 * The web installation over HTTP, from a site with no configuration to a signed-in administrator
 * on the "Your site is ready" page: what the person installing sees, what anyone else sees at each
 * stage, and that nothing of the installer is left once the site has its administrator.
 */
final class WebInstallFlowTest extends UninstalledSiteTestCase
{
    private const ACCOUNT = ['name' => 'Ada Lovelace', 'handle' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct horse battery', 'password2' => 'correct horse battery'];

    /**
     * Runs the installer's three steps and returns the cookies of the browser that did.
     *
     * @return array<string, string>
     */
    private function installTheSite(): array
    {
        $first = $this->request('/');
        self::assertSame(200, $first['status'], $first['body'] . $this->serverLog());
        self::assertStringContainsString('<h1>Checking your server</h1>', $first['body']);
        $cookies = $first['cookies'];
        self::assertArrayHasKey(WebInstaller::COOKIE, $cookies);
        $token = self::token($first['body']);

        $site = $this->request('/', ['step' => 'check', 'token' => $token, 'rewrite' => 'ok'], $cookies);
        self::assertStringContainsString('<h1>Your site</h1>', $site['body']);
        self::assertStringContainsString('name="domain" value="' . substr($this->base, strlen('http://')) . '"', $site['body']);

        $database = $this->request('/', ['step' => 'site', 'token' => $token, 'title' => 'Ada\'s notes', 'domain' => substr($this->base, strlen('http://'))], $cookies);
        self::assertStringContainsString('<h1>Where should Idno keep your site?</h1>', $database['body']);
        self::assertFileDoesNotExist($this->root . '/configuration/config.ini');

        $saved = $this->request('/', ['step' => 'database', 'token' => $token, 'title' => 'Ada\'s notes', 'domain' => substr($this->base, strlen('http://')), 'database' => 'sqlite'], $cookies);
        self::assertSame(303, $saved['status'], $saved['body'] . $this->serverLog());
        self::assertSame('/', $saved['location']);
        self::assertArrayHasKey(SetupToken::COOKIE, $saved['cookies']);

        return [SetupToken::COOKIE => $saved['cookies'][SetupToken::COOKIE]];
    }

    public function testEveryAddressOfASiteWithNoConfigurationLeadsToTheInstaller(): void
    {
        $this->startServer();

        foreach (['/begin/register', '/account/register', '/admin/', '/warmup/', '/_setup/', '/profile/ada'] as $path) {
            $response = $this->request($path);
            self::assertSame(302, $response['status'], $path);
            self::assertSame('/', $response['location'], $path);
        }
        $feed = $this->request('/content/all?_t=rss', null, [], ['Accept: application/rss+xml']);
        self::assertSame(503, $feed['status']);
        self::assertSame("Idno is not set up yet.\n", $feed['body']);
        self::assertSame(503, $this->request('/webmention/', ['source' => 'x', 'target' => 'y'])['status']);
        self::assertSame(503, $this->request('/', [], [], ['Accept: text/html'], 'DELETE')['status']);
        self::assertSame(['router.php', 'server.log', 'sessions'], array_values(array_diff(scandir($this->root) ?: [], ['.', '..', 'configuration', 'data', 'Uploads', 'warmup'])));
        self::assertSame([], array_values(array_diff(scandir($this->root . '/configuration') ?: [], ['.', '..'])), 'nothing was written');
    }

    public function testTheWholeFlowFromNoConfigurationToASignedInAdministrator(): void
    {
        $this->startServer();
        $browser = $this->installTheSite();

        $config = parse_ini_file($this->root . '/configuration/config.ini');
        self::assertIsArray($config);
        self::assertSame($this->base . '/', $config['url']);
        self::assertSame('sqlite', $config['database']);
        self::assertSame($this->root . '/data/idno.sqlite', $config['dbname']);
        $written = (string) file_get_contents($this->root . '/configuration/config.ini');

        // The installer is gone for everyone, the person who ran it included: its forms change nothing.
        $again = $this->request('/', ['step' => 'database', 'token' => 'x', 'title' => 'Other', 'domain' => 'other.example', 'database' => 'sqlite']);
        self::assertStringNotContainsString('Checking your server', $again['body']);
        self::assertSame($written, file_get_contents($this->root . '/configuration/config.ini'));

        // The tables do not exist yet: the upgrade page, for anyone.
        $upgrading = $this->request('/', null, $browser);
        self::assertSame(503, $upgrading['status']);
        $this->createTheTables();

        // The person who installed the site is sent to the account step, from any page.
        foreach (['/', '/admin/', '/account/register'] as $path) {
            $redirect = $this->request($path, null, $browser);
            self::assertSame(302, $redirect['status'], $path . $this->serverLog());
            self::assertSame('/_setup/', $redirect['location'], $path);
        }
        $form = $this->request('/_setup/', null, $browser);
        self::assertSame(200, $form['status']);
        self::assertStringContainsString('<h1>Create your account</h1>', $form['body']);
        self::assertSame($form['body'] === '' ? '' : self::token($form['body']), self::token($this->request('/_setup/', null, $browser)['body']), 'a reload shows the same form');

        // Anyone else sees that the site is being set up, and can create nothing.
        $stranger = [SetupToken::COOKIE => str_repeat('a', 64)];
        foreach ([[], $stranger] as $cookies) {
            foreach (['/', '/_setup/', '/account/register', '/admin/welcome/', '/session/login'] as $path) {
                $page = $this->request($path, null, $cookies);
                self::assertSame(503, $page['status'], $path);
                self::assertStringContainsString('This site is being set up', $page['body'], $path);
                self::assertStringNotContainsString('Create your account', $page['body'], $path);
            }
            self::assertSame(503, $this->request('/_setup/', self::ACCOUNT + ['token' => self::token($form['body'])], $cookies)['status']);
            self::assertSame(503, $this->request('/_setup/' . str_repeat('b', 64), null, $cookies)['status']);
            self::assertSame(503, $this->request('/content/all?_t=rss', null, $cookies, ['Accept: application/rss+xml'])['status']);
        }

        // What is wrong is said beside its field, what was typed is kept, and a password is never printed.
        $token = self::token($form['body']);
        $refused = $this->request('/_setup/', ['password2' => 'correct horse stapler'] + self::ACCOUNT + ['token' => $token], $browser);
        self::assertSame(422, $refused['status']);
        self::assertStringContainsString('id="field-password2-error"', $refused['body']);
        self::assertStringContainsString('value="Ada Lovelace"', $refused['body']);
        self::assertStringNotContainsString('correct horse', $refused['body']);
        $short = $this->request('/_setup/', ['password' => 'short', 'password2' => 'short', 'handle' => 'a b', 'email' => 'nope'] + self::ACCOUNT + ['token' => $token], $browser);
        foreach (['handle', 'email', 'password'] as $field) {
            self::assertStringContainsString('id="field-' . $field . '-error"', $short['body']);
        }
        self::assertSame(422, $this->request('/_setup/', self::ACCOUNT + ['token' => 'wrong'], $browser)['status'], 'the form\'s own token is needed');
        self::assertFileExists($this->root . '/configuration/setup-pending');

        $created = $this->request('/_setup/', self::ACCOUNT + ['token' => $token], $browser);
        self::assertSame(303, $created['status'], $created['body'] . $this->serverLog());
        self::assertSame('/admin/welcome/', $created['location']);
        self::assertFileDoesNotExist($this->root . '/configuration/setup-pending');
        $session = array_diff_key($created['cookies'], [SetupToken::COOKIE => '']);
        self::assertNotSame([], $session, 'a session cookie');

        // Signed in, on the page that says what to do next; every link on it works.
        $welcome = $this->request('/admin/welcome/', null, $session);
        self::assertSame(200, $welcome['status'], $this->serverLog());
        self::assertStringContainsString('Your site is ready', $welcome['body']);
        self::assertStringContainsString('ada', $welcome['body']);
        self::assertSame(1, preg_match('#<h1[^>]*>.*?</h1>(.*?)Go to your site</a>#s', $welcome['body'], $main));
        preg_match_all('#href="(' . preg_quote($this->base, '#') . '[^"]*)"#', $main[0], $links);
        $paths = array_map(fn (string $url): string => substr($url, strlen($this->base)), array_values(array_unique($links[1])));
        self::assertContains('/admin/', $paths);
        self::assertContains('/admin/email/', $paths);
        self::assertContains('/', $paths);
        self::assertCount(4, $paths, 'a first post, the settings, the mail settings and the site: ' . implode(' ', $paths));
        foreach ($paths as $path) {
            self::assertSame(200, $this->request($path, null, $session)['status'], $path . ' as the administrator');
        }
        self::assertStringContainsString('Ada&#039;s notes', $this->request('/', null, $session)['body'], 'the site has the name the installer was given');

        // The step no longer exists: for the browser that did it, for a second submission, for anyone.
        foreach ([$browser, $session, []] as $cookies) {
            self::assertSame(404, $this->request('/_setup/', null, $cookies)['status']);
            self::assertSame(404, $this->request('/_setup/', self::ACCOUNT + ['token' => $token, 'handle' => 'eve', 'email' => 'eve@example.com'], $cookies)['status']);
        }
        self::assertSame(200, $this->request('/')['status'], 'the site is open to visitors');
        self::assertSame(403, $this->request('/admin/welcome/')['status']);
        // Registration is closed (the page says it takes invitations only), and never makes an administrator.
        $register = $this->request('/account/register');
        self::assertSame(403, $register['status']);
        self::assertStringContainsString('You need an invitation to join', $register['body']);
        self::assertSame($written, file_get_contents($this->root . '/configuration/config.ini'));
    }

    public function testADoubleClickOnTheLastStepDoesNotLockTheInstallerOut(): void
    {
        $this->startServer();
        $first = $this->request('/');
        $install = [WebInstaller::COOKIE => $first['cookies'][WebInstaller::COOKIE]];
        $fields = ['step' => 'database', 'token' => self::token($first['body']), 'title' => 'Twice', 'domain' => substr($this->base, strlen('http://')), 'database' => 'sqlite'];

        // Two submissions; the browser only ever sees the answer to the second, which sets no setup cookie.
        self::assertSame(303, $this->request('/', $fields, $install)['status']);
        $second = $this->request('/', $fields, $install);
        self::assertArrayNotHasKey(SetupToken::COOKIE, $second['cookies']);
        $this->createTheTables();

        // The cookie the installer gave the browser at the start opens the account step, and the setup cookie follows.
        $home = $this->request('/', null, $install);
        self::assertSame(302, $home['status'], $home['body']);
        self::assertSame('/_setup/', $home['location']);
        self::assertSame($install[WebInstaller::COOKIE], $home['cookies'][SetupToken::COOKIE]);
        $form = $this->request('/_setup/', null, $install);
        self::assertStringContainsString('<h1>Create your account</h1>', $form['body']);

        // Two submissions of the account form make one administrator: the second is sent to sign in.
        $created = $this->request('/_setup/', self::ACCOUNT + ['token' => self::token($form['body'])], $install);
        self::assertSame('/admin/welcome/', $created['location']);
        $again = $this->request('/_setup/', self::ACCOUNT + ['token' => self::token($form['body'])], $install);
        self::assertSame(404, $again['status'], 'the step is gone');
    }

    public function testNoMethodHeaderOrAddressGetsAStrangerPastTheSetupStep(): void
    {
        $this->startServer();
        $this->installTheSite();
        $this->createTheTables();

        foreach (['PUT', 'DELETE', 'OPTIONS', 'PATCH', 'HEAD'] as $method) {
            self::assertSame(503, $this->request('/_setup/', null, [], ['Accept: text/html'], $method)['status'], $method);
        }
        foreach ([['X-HTTP-Method-Override: GET'], ['X-HTTP-Method-Override: nonsense'], ['X-IDNO-USERNAME: ada', 'X-IDNO-SIGNATURE: x'], ['Authorization: Bearer x']] as $headers) {
            self::assertSame(503, $this->request('/_setup/', self::ACCOUNT + ['token' => 'x'], [], array_merge(['Accept: text/html'], $headers))['status'], implode(' ', $headers));
            self::assertSame(503, $this->request('/micropub/endpoint', ['h' => 'entry', 'content' => 'x'], [], $headers)['status'], implode(' ', $headers));
        }
        foreach (['/index.php/_setup/', '/_setup//', '/_SETUP/', '/_setup/?x=1', '/account/register', '/session/login', '/begin/register'] as $path) {
            self::assertSame(503, $this->request($path)['status'], $path);
            self::assertSame(503, $this->request($path, self::ACCOUNT)['status'], 'POST ' . $path);
        }
        $step = $this->request('/_upgrade/step', [], [], ['Accept: application/json'], 'POST');
        self::assertSame(503, $step['status'], 'the upgrade step is not a way around it');
        self::assertFileExists($this->root . '/configuration/setup-pending');
        self::assertStringContainsString('This site is being set up', $this->request('/')['body'], 'and nobody was created');
    }

    public function testALostCookieIsRecoveredWithTheLinkTheConsolePrints(): void
    {
        $this->startServer();
        $browser = $this->installTheSite();
        $this->createTheTables();

        [$exit, $out, $err] = $this->console(['idno:setup:link']);
        self::assertSame(0, $exit, $out . $err);
        self::assertSame(1, preg_match('#' . preg_quote($this->base, '#') . '(/_setup/[a-f0-9]{64})#', $out, $match), $out);

        // The link made a new token: the first browser's no longer works.
        self::assertSame(503, $this->request('/_setup/', null, $browser)['status']);
        self::assertSame(503, $this->request(substr($match[1], 0, -1) . 'x')['status']);

        $opened = $this->request($match[1]);
        self::assertSame(303, $opened['status']);
        self::assertSame('/_setup/', $opened['location']);
        $other = [SetupToken::COOKIE => $opened['cookies'][SetupToken::COOKIE]];
        // The link worked once: the token it carried (which a server's log may keep) is no longer the site's.
        self::assertNotSame(substr($match[1], strlen('/_setup/')), $other[SetupToken::COOKIE]);
        self::assertSame(503, $this->request($match[1])['status']);
        self::assertSame(503, $this->request('/_setup/', null, [SetupToken::COOKIE => substr($match[1], strlen('/_setup/'))])['status']);
        $form = $this->request('/_setup/', null, $other);
        self::assertStringContainsString('<h1>Create your account</h1>', $form['body']);
        self::assertSame(303, $this->request('/_setup/', self::ACCOUNT + ['token' => self::token($form['body'])], $other)['status']);

        [$exit, , $err] = $this->console(['idno:setup:link']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('already has an administrator', $err);
        self::assertSame(404, $this->request($match[1])['status']);
    }

    public function testAConfigurationFileThatCannotBeReadIsNotOfferedTheInstaller(): void
    {
        file_put_contents($this->root . '/configuration/config.ini', '');
        $this->startServer();

        foreach (['/', '/admin/'] as $path) {
            $page = $this->request($path);
            self::assertSame(503, $page['status']);
            self::assertStringContainsString('Idno cannot read its settings', $page['body']);
            self::assertStringNotContainsString('Checking your server', $page['body']);
        }
        self::assertSame(503, $this->request('/', ['step' => 'database', 'domain' => '127.0.0.1', 'database' => 'sqlite'])['status']);
        self::assertSame('', file_get_contents($this->root . '/configuration/config.ini'));
        self::assertFileDoesNotExist($this->root . '/data/idno.sqlite');
    }

    public function testAHandWrittenConfigurationEndsInTheConsolesAdministratorCommand(): void
    {
        file_put_contents($this->root . '/configuration/config.ini', "database = 'sqlite'\ndbname = '" . $this->root . "/data/idno.sqlite'\nuploadpath = '" . $this->root . "/Uploads/'\nfilesystem = 'local'\n");
        [$exit, $out, $err] = $this->console(['idno:admin:create', '--no-interaction', '--handle=ada', '--email=ada@example.com', '--password-stdin'], "correct horse battery\n");
        self::assertSame(1, $exit, 'the tables come first');
        self::assertStringContainsString('idno:upgrade', $out . $err);
        self::assertSame(0, $this->console(['idno:upgrade'])[0]);
        $this->startServer();

        // No administrator, and nobody was given a setup token: the site says it is being set up, and registration makes nobody.
        $home = $this->request('/');
        self::assertSame(503, $home['status']);
        self::assertStringContainsString('This site is being set up', $home['body']);
        $register = $this->request('/account/register');
        self::assertSame(302, $register['status']);
        $post = $this->request('/account/register', ['handle' => 'eve', 'email' => 'eve@example.com', 'password' => 'correct horse battery', 'password2' => 'correct horse battery']);
        self::assertContains($post['status'], [302, 403]);
        self::assertSame(503, $this->request('/')['status'], 'still no account');

        $file = $this->root . '/password.txt';
        file_put_contents($file, "correct horse battery\n");
        foreach ([['--handle=a b'], ['--email=nope'], ['--password-file=' . $this->root . '/none.txt']] as $bad) {
            [$exit] = $this->console(array_merge(['idno:admin:create', '--no-interaction', '--handle=ada', '--email=ada@example.com', '--password-file=' . $file], $bad));
            self::assertSame(1, $exit, implode(' ', $bad));
        }
        [$exit, , $err] = $this->console(['idno:admin:create', '--no-interaction', '--handle=ada', '--email=ada@example.com']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('never taken as an argument', $err);

        [$exit, $out, $err] = $this->console(['idno:admin:create', '--no-interaction', '--handle=ada', '--email=ada@example.com', '--site-title=Ada\'s notes', '--password-file=' . $file]);
        self::assertSame(0, $exit, $out . $err);
        self::assertSame(200, $this->request('/')['status']);
        [$exit, , $err] = $this->console(['idno:admin:create', '--no-interaction', '--handle=eve', '--email=eve@example.com', '--password-file=' . $file]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('already has an administrator', $err);
    }

    public function testTheConsoleInstallsASiteWithItsAdministratorAndNeverTwice(): void
    {
        [$exit, , $err] = $this->console(['idno:install', '--no-interaction']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('--url', $err);
        [$exit, , $err] = $this->console(['idno:install', '--no-interaction', '--url=https://example.com/blog/']);
        self::assertSame(1, $exit);
        [$exit, , $err] = $this->console(['idno:install', '--no-interaction', '--url=https://example.com/', '--database=mongodb']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('MongoDB is no longer supported', $err);
        [$exit, , $err] = $this->console(['idno:install', '--no-interaction', '--url=https://example.com/', '--sqlite-path=shell.php']);
        self::assertSame(1, $exit);
        self::assertSame([], array_values(array_diff(scandir($this->root . '/configuration') ?: [], ['.', '..'])), 'a refused install writes nothing');
        self::assertSame([], array_values(array_diff(scandir($this->root . '/data') ?: [], ['.', '..'])));

        $manifest = $this->root . '/manifest.ini';
        file_put_contents($manifest, "site_title = \"Ada's notes\"\nurl = \"https://example.com\"\ndatabase = sqlite\nadmin_handle = ada\nadmin_email = \"ada@example.com\"\nadmin_name = \"Ada Lovelace\"\n");
        [$exit, $out, $err] = $this->console(['idno:install', '--no-interaction', '--manifest=' . $manifest, '--admin-password-stdin'], "correct horse battery\n");
        self::assertSame(0, $exit, $out . $err);
        self::assertStringContainsString('Created the administrator ada', $out);
        self::assertStringContainsString('chown -R www-data ' . $this->root . '/data', $out);
        self::assertStringNotContainsString('correct horse', $out . $err);
        $config = parse_ini_file($this->root . '/configuration/config.ini');
        self::assertIsArray($config);
        self::assertSame('https://example.com/', $config['url']);
        self::assertSame($this->root . '/data/idno.sqlite', $config['dbname']);
        self::assertFileDoesNotExist($this->root . '/configuration/setup-pending');
        self::assertSame("Require all denied\n", file_get_contents($this->root . '/Uploads/.htaccess'), 'the upload folder inside the project refuses everything itself');
        self::assertStringContainsString('the web server must refuse it', $out);
        $written = file_get_contents($this->root . '/configuration/config.ini');

        [$exit, , $err] = $this->console(['idno:install', '--no-interaction', '--url=https://other.example/']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('already installed', $err);
        self::assertSame($written, file_get_contents($this->root . '/configuration/config.ini'));

        $this->startServer();
        $home = $this->request('/');
        self::assertSame(200, $home['status'], $this->serverLog());
        self::assertStringContainsString('Ada&#039;s notes', $home['body']);
        self::assertSame(404, $this->request('/_setup/')['status']);
    }

    public function testTheConsoleNeverTakesOverADatabaseThatHoldsASiteUnlessTold(): void
    {
        self::assertSame(0, $this->console(['idno:install', '--no-interaction', '--url=https://example.com/', '--no-admin'])[0]);
        unlink($this->root . '/configuration/config.ini');

        [$exit, , $err] = $this->console(['idno:install', '--no-interaction', '--url=https://example.com/', '--no-admin']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('already holds an Idno site', $err);
        self::assertStringContainsString('--use-existing', $err);
        self::assertFileDoesNotExist($this->root . '/configuration/config.ini');

        [$exit, $out, $err] = $this->console(['idno:install', '--no-interaction', '--url=https://example.com/', '--use-existing']);
        self::assertSame(0, $exit, $out . $err);
        self::assertStringContainsString('Using the Idno site the database already holds', $out);
        self::assertFileExists($this->root . '/configuration/config.ini');
    }

    /**
     * A site whose data is there but whose configuration file is lost is an uninstalled site to anyone who reaches it. Taking
     * its database over through the web installer (which writes the site's address) needs the code the installer writes to a
     * file on the server: a stranger cannot, and nothing is written but that file; the person at the server can.
     */
    public function testOnlySomeoneWhoCanReadTheServersFilesAdoptsAnExistingDatabase(): void
    {
        self::assertSame(0, $this->console(['idno:install', '--no-interaction', '--url=https://owner.example/', '--no-admin'])[0]);
        unlink($this->root . '/configuration/config.ini');
        @unlink($this->root . '/Uploads/.htaccess');
        $database = $this->root . '/data/idno.sqlite';
        $before = sha1_file($database);
        $this->startServer();
        $domain = substr($this->base, strlen('http://'));

        // A stranger's browser runs the installer to the end and ticks "use the site that is there".
        $stranger = $this->request('/');
        $strangerCookies = $stranger['cookies'];
        $strangerToken = self::token($stranger['body']);
        $form = ['step' => 'database', 'token' => $strangerToken, 'title' => 'Mine now', 'domain' => 'evil.example', 'database' => 'sqlite', 'use_existing' => '1'];
        $refused = $this->request('/', $form, $strangerCookies);
        self::assertSame(200, $refused['status'], $refused['body'] . $this->serverLog());
        self::assertStringContainsString('earlier Idno site', $refused['body']);
        self::assertStringContainsString('configuration/install-code', $refused['body']);
        foreach ([str_repeat('a', 32), 'install-code', ''] as $guess) {
            self::assertStringContainsString('earlier Idno site', $this->request('/', $form + ['install_code' => $guess], $strangerCookies)['body']);
        }
        self::assertFileDoesNotExist($this->root . '/configuration/config.ini');
        self::assertFileDoesNotExist($this->root . '/Uploads/.htaccess');
        self::assertSame(['install-code'], array_values(array_diff(scandir($this->root . '/configuration') ?: [], ['.', '..'])), 'nothing but the code is written');
        self::assertSame($before, sha1_file($database), 'the database is untouched');
        $code = trim((string) file_get_contents($this->root . '/configuration/install-code'));
        self::assertStringNotContainsString($code, $refused['body'] . $refused['headers']);
        $served = $this->request('/configuration/install-code', null, [], ['Accept: */*']);
        self::assertSame(403, $served['status'], 'the file is never served');
        self::assertStringNotContainsString($code, $served['body']);

        // An expired code is refused, and replaced.
        touch($this->root . '/configuration/install-code', time() - 7200);
        self::assertStringContainsString('earlier Idno site', $this->request('/', $form + ['install_code' => $code], $strangerCookies)['body']);
        self::assertFileDoesNotExist($this->root . '/configuration/config.ini');
        $code = trim((string) file_get_contents($this->root . '/configuration/install-code'));

        // The owner's browser, with the code from the server, adopts the site at the address it confirms. The stranger
        // visiting again meanwhile did not replace the code.
        $owner = $this->request('/');
        $ownerCookies = $owner['cookies'];
        $ownerToken = self::token($owner['body']);
        $this->request('/', $form, $strangerCookies);
        $adopted = $this->request('/', ['token' => $ownerToken, 'domain' => $domain, 'install_code' => $code] + $form, $ownerCookies);
        self::assertSame(303, $adopted['status'], $adopted['body'] . $this->serverLog());
        $config = parse_ini_file($this->root . '/configuration/config.ini');
        self::assertIsArray($config);
        self::assertSame('http://' . $domain . '/', $config['url']);
        self::assertSame($database, $config['dbname']);
        self::assertFileDoesNotExist($this->root . '/configuration/install-code');
        $written = file_get_contents($this->root . '/configuration/config.ini');

        // The stranger's next submission, with the used code or without, changes nothing: the site is installed, and while it
        // has no administrator a browser other than the owner's is told it is being set up.
        self::assertSame(503, $this->request('/', $form + ['install_code' => $code], $strangerCookies)['status']);
        self::assertSame($written, file_get_contents($this->root . '/configuration/config.ini'));
    }

    /**
     * @return array<string, array{0: string, 1: string}> a sign of an earlier site => its file (under the install root) and content
     */
    public static function earlierSites(): array
    {
        return [
            'a database file' => ['data/old.sqlite', 'what is left of a database'],
            'a stored upload' => ['Uploads/owner.example/a/b/c/d/0123456789abcdef0123456789abcdef.file', 'a private picture'],
            'a stray configuration file' => ['configuration/config.ini.bak', "url = 'https://owner.example/'\n"],
        ];
    }

    /**
     * A server that shows what an earlier site left (its configuration lost) installs nothing for a stranger: a NEW site on an
     * empty database of the visitor's own needs the code from the server, as adopting the old one does. Without it nothing is
     * written but the code; another database (a MySQL server the visitor names) does not avoid it; with it the install goes on.
     *
     * @dataProvider earlierSites
     */
    public function testASignOfAnEarlierSiteMakesAnyInstallAskForTheCode(string $file, string $content): void
    {
        if (!is_dir(dirname($this->root . '/' . $file))) {
            mkdir(dirname($this->root . '/' . $file), 0755, true);
        }
        file_put_contents($this->root . '/' . $file, $content);
        $this->startServer();
        $domain = substr($this->base, strlen('http://'));
        $first = $this->request('/');
        $cookies = $first['cookies'];
        $form = ['step' => 'database', 'token' => self::token($first['body']), 'title' => 'Mine now', 'domain' => $domain, 'database' => 'sqlite'];

        $asked = $this->request('/', $form, $cookies);
        self::assertSame(200, $asked['status'], $asked['body'] . $this->serverLog());
        self::assertStringContainsString('earlier Idno site', $asked['body']);
        self::assertStringContainsString('configuration/install-code', $asked['body']);
        self::assertStringContainsString('name="install_code"', $asked['body']);
        self::assertStringContainsString('earlier Idno site', $this->request('/', $form + ['install_code' => str_repeat('a', 32)], $cookies)['body']);
        $mysql = $this->request('/', ['database' => 'mysql', 'mysql_name' => 'mine', 'mysql_user' => 'u', 'mysql_pass' => 'p', 'mysql_host' => '127.0.0.1:9'] + $form, $cookies);
        self::assertStringContainsString('earlier Idno site', $mysql['body'], 'a database of the visitor\'s own does not avoid the code');
        self::assertFileDoesNotExist($this->root . '/configuration/config.ini');
        self::assertFileDoesNotExist($this->root . '/data/idno.sqlite');
        self::assertFileDoesNotExist($this->root . '/Uploads/.htaccess');
        self::assertSame($content, file_get_contents($this->root . '/' . $file));
        self::assertFileExists($this->root . '/configuration/install-code', 'only the code is written');

        $code = trim((string) file_get_contents($this->root . '/configuration/install-code'));
        $installed = $this->request('/', $form + ['install_code' => $code], $cookies);
        self::assertSame(303, $installed['status'], $installed['body'] . $this->serverLog());
        self::assertFileExists($this->root . '/configuration/config.ini');
        self::assertFileDoesNotExist($this->root . '/configuration/install-code');
        self::assertArrayHasKey(SetupToken::COOKIE, $installed['cookies'], 'the code holder\'s browser creates the administrator');
    }

    public function testAFreshServerInstallsWithoutACode(): void
    {
        $this->startServer();
        $this->installTheSite();

        self::assertFileExists($this->root . '/configuration/config.ini');
        self::assertFileDoesNotExist($this->root . '/configuration/install-code', 'no code was asked for or written');
    }

    public function testASiteConfiguredByItsEnvironmentIsInstalledForTheConsoleAndTheWeb(): void
    {
        $environment = ['IDNO_DATABASE' => 'sqlite', 'IDNO_DBNAME' => $this->root . '/data/env.sqlite', 'IDNO_UPLOADPATH' => $this->root . '/Uploads/'];

        [$exit, , $err] = $this->console(['idno:install', '--no-interaction', '--url=https://example.com/'], '', $environment);
        self::assertSame(1, $exit);
        self::assertStringContainsString('environment names a database', $err);
        self::assertFileDoesNotExist($this->root . '/configuration/config.ini');
        self::assertFileDoesNotExist($this->root . '/data/idno.sqlite');

        touch($this->root . '/data/env.sqlite');
        [$exit, $out, $err] = $this->console(['idno:upgrade'], '', $environment);
        self::assertSame(0, $exit, $out . $err);
        [$exit, $out, $err] = $this->console(['idno:admin:create', '--no-interaction', '--handle=ada', '--email=ada@example.com', '--password-stdin'], "correct horse battery\n", $environment);
        self::assertSame(0, $exit, $out . $err);
        self::assertFileDoesNotExist($this->root . '/configuration/config.ini', 'no configuration file was needed');
    }
}
