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
        self::assertSame(503, $this->request('/webmention/endpoint', ['source' => 'x', 'target' => 'y'])['status']);
        self::assertSame(503, $this->request('/', [], [], ['Accept: text/html'], 'DELETE')['status']);
        self::assertSame(['router.php', 'server.log'], array_values(array_diff(scandir($this->root) ?: [], ['.', '..', 'configuration', 'data', 'Uploads', 'warmup'])));
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
        // Registration is closed, and never makes an administrator.
        $register = $this->request('/account/register');
        self::assertSame(302, $register['status']);
        self::assertSame($written, file_get_contents($this->root . '/configuration/config.ini'));
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
}
