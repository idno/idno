<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Fixtures\Smtp\ScriptedSmtpServer;
use Tests\Fixtures\TestMailTransportFactory;
use Tests\Mail\ReceivedMessage;
use Tests\Portability\PortabilityTestCase;
use Tests\Queue\AsyncSiteTestCase;

/**
 * What the owner of a new site does, from the first mail setting to a full export, in one ordered scenario on
 * ONE site of the test's own (private database, the asynchronous queue, a worker as a subprocess), the way a
 * person does it: over HTTP, with forms and their signed tokens and a session cookie each for the owner
 * (alice, the first user, who administers the site), a member (bob) and a visitor.
 *
 *  1. the owner saves the mail settings on /admin/email (host, port, user name, a WRONG password) and sends the
 *     test mail: it fails and the scripted SMTP server saw a refused login; the owner corrects the password on
 *     the same page and the test mail arrives. The settings the page saved are the ones the site uses: the
 *     mail needs the login, and the port a fresh process of the site's builds its transport for is the saved one;
 *  2. the owner invites bob: the invitation arrives and its link registers him;
 *  3. bob asks for a password reset: the mail arrives, its link works, the new password logs him in;
 *  4. bob publishes (a public note, a note for the site's members, a draft, a photo with an uploaded file);
 *  5. the site answers in French to `Accept-Language: fr-FR` (a string of the Event plugin's catalogue) and in
 *     English without it;
 *  6. the owner asks for the archive; the worker makes it; the result mail arrives; the archive downloads for the
 *     owner with `Cache-Control: private, no-store` and is refused to bob and to a visitor; it holds bob's posts as
 *     JSON and HTML with their access and publish status, the uploaded file's bytes, the record dump, and no
 *     secret;
 *  7. the owner imports a WordPress file: the worker imports it, the posts and the draft are what the file says,
 *     a follower's inbox is told nothing of them but is told of a post published by hand afterwards, and the
 *     result mail arrives;
 *  8. HSTS state recorded by one process of the site is seen by another, and expires.
 *
 * Step 8 cannot be driven through a request: HSTS state is only recorded from the response of an outbound
 * HTTPS request, which needs a TLS server (the test client's allowance, `IDNO_TEST_HTTP_ALLOW`, is for plain-http
 * origins, and an IP address never has HSTS). It runs in three separate command-line processes that boot the
 * site as the queue worker does (`IDNO_CONSOLE`, so the cache pool of the site's host), one recording the state
 * through Webservice::checkForHSTSHeader(), one reading it at once and one after the max-age. NOT covered: that a
 * web server process and the worker share the pool (nothing a request can do reaches it).
 *
 * In-process shortcuts (`runInSite()`) are used only where no page exists: the settings and the follower of
 * step 7, the entities read back after the import (a draft has no public page), the transport probe of step 1,
 * the secrets of the site to look for in the archive.
 */
final class PhaseWalkthroughTest extends AsyncSiteTestCase
{
    private const PASSWORD = 'correct-horse-battery-9';

    private ?ScriptedSmtpServer $smtp = null;

    private Browser $browser;

    private string $step = '';

    /** @var list<string> */
    private array $scratch = [];

    protected function setUp(): void
    {
        // The server the site's mail goes to demands a login: no mail is sent until the owner's settings are right.
        $this->smtp = ScriptedSmtpServer::start(['auth' => ['user' => 'mailer', 'pass' => 'right-pw']]);
        parent::setUp();
        $this->browser = new Browser($this->site());
    }

    protected function tearDown(): void
    {
        $this->smtp?->stop();
        $this->smtp = null;
        foreach ($this->scratch as $directory) {
            self::removeTree($directory);
        }
        parent::tearDown();
    }

    /** A distinctive value for a BOOT secret of the site (an environment variable becomes a boot key). */
    private ?string $bootSecret = null;

    protected function siteEnvironment(): array
    {
        $this->bootSecret ??= 'boot-secret-' . bin2hex(random_bytes(12));

        // IDNO_AWS_SECRET is the boot key `aws_secret`, which nothing here uses: harmless, and a secret by its name.
        return [TestMailTransportFactory::DELIVER => '127.0.0.1:' . $this->smtp->port(), 'IDNO_AWS_SECRET' => $this->bootSecret];
    }

    public function testTheOwnerOfANewSiteSetsUpMailInvitesAMemberExportsAndImports(): void
    {
        $owner = $this->step('0 the first person to register administers the site', fn () => $this->registerUser('alice', self::PASSWORD));

        // ---------------------------------------------------------------- 1 mail settings and the test mail
        $this->step('1 the mail settings are saved on the admin page, and the test mail is refused on a wrong password');
        $save = fn (string $password) => $this->browser->submit('admin/email', 'admin/email', [
            'from_email' => 'site@example.com',
            'smtp_host' => '127.0.0.1',
            'smtp_port' => '2525',
            'smtp_username' => 'mailer',
            'smtp_password' => $password,
            'smtp_secure' => '',
        ], $owner);
        $this->assertSame(302, $save('wrong-pw')->status);
        $page = $this->browser->get('admin/email', $owner);
        $this->assertStringContainsString('name="smtp_host"', $page->body);
        $this->assertStringContainsString('value="127.0.0.1"', $page->body, $this->step . ': the page shows what it saved');
        $this->assertStringContainsString('value="2525"', $page->body);
        $this->assertStringContainsString('restart the queue worker', $page->body, $this->step . ': the page says the worker reads the settings when it starts');

        $text = $this->sendTestMail($owner, 'owner@example.net');
        $this->assertStringContainsString('The test email to owner@example.net was not sent', $text, $this->step);
        $this->assertSame([], $this->smtp->messages(), $this->step . ': nothing was delivered');
        $this->assertFalse($this->smtp->connections()[0]['authAccepted'], $this->step . ': the server saw the wrong login');

        $this->step('1 the corrected password, saved on the same page, is the one the site uses');
        $this->assertSame(302, $save('right-pw')->status);
        $text = $this->sendTestMail($owner, 'owner@example.net');
        $this->assertStringContainsString('The mail server accepted a test email for owner@example.net', $text, $this->step);
        $mails = $this->mailsWith('Test email from');
        $this->assertCount(1, $mails, $this->step);
        $this->assertStringContainsString('site@example.com', (string) $mails[0]->header('From'));
        $this->assertStringContainsString('owner@example.net', (string) $mails[0]->header('To'));
        $logins = array_values(array_filter($this->smtp->connections(), static fn (array $c): bool => $c['authAccepted'] === true));
        $this->assertCount(1, $logins, $this->step . ': one connection logged in with the saved credentials');

        // The port the settings MEAN is the saved one: a fresh process of the site (the page's own process is gone)
        // builds the production transport from the stored settings; the test wiring then connects to the scripted server.
        $probe = $this->runInSite(
            '\Tests\Fixtures\TestMailTransportFactory::forget();' . "\n"
            . '$mail = new \Idno\Core\Email(); $mail->addTo("probe@example.net"); $mail->setSubject("transport probe"); $mail->setTextBody("probe");' . "\n"
            . 'return ["sent" => $mail->send(), "built" => \Tests\Fixtures\TestMailTransportFactory::built()];'
        );
        $this->assertSame(1, $probe['sent'], $this->step);
        $this->assertCount(1, $probe['built']);
        $this->assertSame(2525, $probe['built'][0]['port'], $this->step . ': the saved port is the port the settings mean');
        $this->assertSame('127.0.0.1', $probe['built'][0]['host']);
        $this->assertSame('mailer', $probe['built'][0]['username']);
        $this->assertFalse($probe['built'][0]['implicitTls']);
        $this->assertCount(1, $this->mailsWith('transport probe'));

        // ---------------------------------------------------------------- 2 the invitation
        $this->step('2 the owner invites a member: the mail arrives and its link registers him');
        $invite = $this->browser->submit('admin/users', 'admin/users', ['action' => 'invite_users', 'invitation_emails' => 'bob@example.com'], $owner);
        $this->assertSame(302, $invite->status, $this->step . $this->serverLog());
        $mails = $this->mailsWith("You've been invited to join");
        $this->assertCount(1, $mails, $this->step . $this->serverLog());
        $this->assertSame('bob@example.com', $mails[0]->header('To'));
        $this->assertSame(1, preg_match('~account/register/\?email=([^&\s]+)&code=([0-9a-f]+)~', (string) $mails[0]->part('text/plain'), $link), $this->step);
        $this->assertStringContainsString('account/register/?email=', (string) $mails[0]->part('text/html'));
        $registerPath = 'account/register/?email=' . $link[1] . '&code=' . $link[2];
        $this->assertSame(302, $this->browser->get('account/register/?email=' . $link[1] . '&code=0000')->status, $this->step . ': a wrong code is refused');
        $registered = $this->browser->submit($registerPath, 'account/register', [
            'name' => 'Bob', 'handle' => 'bob', 'email' => 'bob@example.com', 'password' => self::PASSWORD, 'password2' => self::PASSWORD, 'code' => $link[2],
        ], null);
        $this->assertSame(302, $registered->status, $this->step . ': ' . substr($registered->body, 0, 500));
        $bob = Browser::cookie($registered);
        $this->assertTrue($this->browser->isLoggedIn($bob), $this->step . ': the invited member is logged in');
        $this->assertNull($this->browser->tryLogin('mallory', 'x'), 'a person who is not a member is not');

        // ---------------------------------------------------------------- 3 the password reset
        $this->step('3 the member asks for a password reset: the mail arrives and its link sets a new password');
        $new = 'a-brand-new-password-42';
        $asked = $this->browser->submit('account/password/', 'account/password', ['email' => 'bob@example.com'], null);
        $this->assertSame(302, $asked->status, $this->step);
        $this->assertStringContainsString('sent=true', (string) $asked->header('Location'));
        $mails = $this->mailsWith('Reset your password');
        $this->assertCount(1, $mails, $this->step);
        $this->assertSame('bob@example.com', $mails[0]->header('To'));
        $this->assertSame(1, preg_match('~account/password/reset/\?email=([^&\s]+)&code=([0-9a-f]+)~', (string) $mails[0]->part('text/plain'), $reset), $this->step);
        $resetPath = 'account/password/reset/?email=' . $reset[1] . '&code=' . $reset[2];
        $form = $this->browser->get($resetPath);
        $this->assertSame(200, $form->status, $this->step . ': the link opens the reset form');
        $done = $this->browser->submit($resetPath, 'account/password/reset', ['email' => 'bob@example.com', 'password' => $new, 'password2' => $new, 'code' => $reset[2]], null);
        $this->assertSame(302, $done->status, $this->step);
        $this->assertNull($this->browser->tryLogin('bob', self::PASSWORD), $this->step . ': the old password no longer works');
        $bob = $this->browser->tryLogin('bob', $new);
        $this->assertNotNull($bob, $this->step . ': the new one does');
        $this->assertSame(302, $this->browser->get($resetPath)->status, $this->step . ': the code works once');

        // ---------------------------------------------------------------- 4 the member's content
        $this->step('4 the member publishes a public note, a note for members, a draft and a photo');
        $png = (string) base64_decode(PortabilityTestCase::PNG_BASE64, true);
        $this->publishStatus($bob, 'Bob public note about tide pools');
        $this->publishStatus($bob, 'Bob note for the site members only', [], 'SITE');
        $draft = $this->browser->submit('status/edit?body=x', 'status/edit/', ['body' => 'Bob unfinished draft note', 'access' => 'PUBLIC', 'publish_status' => 'draft'], $bob);
        $this->assertSame(302, $draft->status, $this->step);
        $photo = $this->browser->upload('photo/edit', 'photo/edit/', ['title' => 'Bob tide pool picture', 'body' => 'One pixel of a pool', 'access' => 'PUBLIC'], ['field' => 'photo', 'name' => 'pool.png', 'type' => 'image/png', 'bytes' => $png], $bob);
        $this->assertSame(302, $photo->status, $this->step . ': ' . substr($photo->body, 0, 800) . $this->serverLog());

        // ---------------------------------------------------------------- 5 translation
        $this->step('5 a French browser is shown the French string, an English one the English');
        // Idno 2026 shows the name of each content type (the Event plugin's catalogue has `Event`) in the compose dialog of a signed-in member
        $french = $this->browser->get('content/all/', $bob, ['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8']);
        $this->assertSame(200, $french->status, $this->step);
        $this->assertStringContainsString('Évènement', $french->body, $this->step);
        $english = $this->browser->get('content/all/', $bob);
        $this->assertSame(200, $english->status);
        $this->assertStringNotContainsString('Évènement', $english->body, $this->step);
        $this->assertStringContainsString('Event</div>', $english->body, $this->step);

        // ---------------------------------------------------------------- 6 the full archive
        $this->step('6 the owner asks for the archive; the worker makes it and mails the owner');
        $ask = $this->browser->submit('admin/export/', 'admin/export/generate/', [], $owner);
        $this->assertSame(302, $ask->status, $this->step . $this->serverLog());
        $this->assertSame(1, $this->queued('portability/export'), $this->step . ': the page queued the job, once');
        $this->assertStringContainsString('is being created', $this->browser->get('admin/export/', $owner)->body, $this->step);
        $worker = $this->runWorkerOnce();
        $this->assertSame(0, $worker['exit'], $this->step . ': ' . $worker['output']);
        $this->assertSame([], $this->failedEvents(), $this->step);
        $mails = $this->mailsWith('Your export of');
        $this->assertCount(1, $mails, $this->step . $this->serverLog());
        $this->assertSame('alice@example.com', $mails[0]->header('To'));
        // The mail's BODY is checked in testTheMailsAWorkerSendsAboutAnExportAndAnImportHaveABody.

        $this->step('6 the archive downloads for the owner only');
        $page = $this->browser->get('admin/export/', $owner);
        $this->assertSame(1, preg_match('~file/([0-9a-f]{32})/([^"]+\.zip)"~', $page->body, $match), $this->step . ': the page links to the archive');
        $archivePath = 'file/' . $match[1] . '/' . $match[2];
        $download = $this->browser->get($archivePath, $owner);
        $this->assertSame(200, $download->status, $this->step);
        $this->assertSame('private, no-store', $download->header('Cache-Control'), $this->step);
        $this->assertSame(403, $this->browser->get($archivePath, $bob)->status, $this->step . ': refused to the member');
        $this->assertSame(403, $this->browser->get($archivePath)->status, $this->step . ': refused to a visitor');

        $this->step('6 the archive opens and holds the member\'s posts, the uploaded file, the record dump and no secret');
        $folder = $this->unzip($download->body);
        $this->assertFileExists($folder . '/known.json', $this->step);
        $byBody = [];
        foreach (glob($folder . '/json/*.json') ?: [] as $file) {
            $json = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($json, $file);
            foreach ([$json['content'] ?? '', $json['displayName'] ?? ''] as $key) {
                if ($key !== '') {
                    $byBody[(string) $key] = [basename($file, '.json'), $json];
                }
            }
        }
        foreach ([
            'Bob public note about tide pools' => ['PUBLIC', 'published'],
            'Bob note for the site members only' => ['SITE', 'published'],
            'Bob unfinished draft note' => ['PUBLIC', 'draft'],
        ] as $body => [$access, $status]) {
            $this->assertArrayHasKey($body, $byBody, $this->step . ': ' . $body . ' is in the archive as JSON (found: ' . implode(' | ', array_map(static fn ($k) => substr((string) $k, 0, 60), array_keys($byBody))) . ')');
            [$id, $json] = $byBody[$body];
            $this->assertSame($access, $json['access'], $this->step . ': access of ' . $body);
            $this->assertSame($status, $json['publish_status'], $this->step . ': publish status of ' . $body);
            $this->assertFileExists($folder . '/html/' . $id . '.html', $this->step . ': ' . $body . ' is in the archive as HTML');
            $html = (string) file_get_contents($folder . '/html/' . $id . '.html');
            $this->assertStringContainsString($body, Browser::text($html), $this->step);
            $this->assertStringContainsString('h-entry', $html, $this->step . ': ' . $body . ' is drawn with its h-entry markup');
            $this->assertMatchesRegularExpression('~class="[^"]*\bh-card\b[^"]*"~', $html, $this->step . ': and its author as an h-card');
            $this->assertStringContainsString('bob', strtolower(Browser::text($html)), $this->step . ': and the author is named');
        }
        $this->assertArrayHasKey('Bob tide pool picture', $byBody, $this->step . ': the photo is in the archive');
        $this->assertSame('PUBLIC', $byBody['Bob tide pool picture'][1]['access']);
        $this->assertSame('published', $byBody['Bob tide pool picture'][1]['publish_status']);

        $hashes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder . '/files', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $hashes[hash_file('sha256', $file->getPathname())][] = substr($file->getPathname(), strlen($folder) + 1);
            }
        }
        $this->assertArrayHasKey(hash('sha256', $png), $hashes, $this->step . ': the uploaded file\'s bytes are in the archive');
        $this->assertTrue((bool) array_filter($hashes[hash('sha256', $png)], static fn (string $p): bool => str_starts_with($p, 'files/readable/')), $this->step);
        $this->assertTrue((bool) array_filter($hashes[hash('sha256', $png)], static fn (string $p): bool => str_contains($p, '/uploads/') && str_ends_with($p, '.file')), $this->step);

        $this->assertFileExists($folder . '/exported_data.sql', $this->step . ': the record dump');
        $this->assertGreaterThan(1000, filesize($folder . '/exported_data.sql'));
        $this->assertStringContainsString('Bob public note about tide pools', (string) file_get_contents($folder . '/exported_data.sql'), $this->step . ': the dump holds the records');

        $config = json_decode((string) file_get_contents($folder . '/config.json'), true);
        $this->assertIsArray($config, $this->step);
        $this->assertSame('127.0.0.1', $config['config']['smtp_host'] ?? null, $this->step . ': the settings are there');
        $secrets = $this->runInSite(
            '$boot = \Idno\Core\Idno::container()->get(\Idno\Config\BootConfig::class)->all();' . "\n"
            . 'return ["site_secret" => (string) (\Idno\Core\Idno::site()->config()->site_secret ?? ""), "boot_site_secret" => (string) ($boot["site_secret"] ?? ""), "dbpass" => (string) ($boot["dbpass"] ?? "")];'
        );
        $unwanted = ['right-pw' => 'the SMTP password saved on the admin page'];
        $bootKey = $this->runInSite('return (string) (\Idno\Core\Idno::container()->get(\Idno\Config\BootConfig::class)->all()["aws_secret"] ?? "");');
        $this->assertNotSame('', $this->bootSecret);
        $this->assertSame($this->bootSecret, $bootKey, $this->step . ': the site has the distinctive boot secret as a boot key');
        $unwanted[$this->bootSecret] = 'a secret of the boot configuration';
        foreach (['site_secret' => 'the site secret', 'boot_site_secret' => 'the site secret of the boot configuration', 'dbpass' => 'the database password'] as $key => $what) {
            if ($secrets[$key] !== '') {
                $unwanted[$secrets[$key]] = $what;
            }
        }
        $configText = (string) file_get_contents($folder . '/config.json');
        foreach ($unwanted as $secret => $what) {
            $this->assertStringNotContainsString((string) $secret, $configText, $this->step . ': config.json holds ' . $what);
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
            // Every text entry of the archive (the attached files are bytes of the uploads, which hold no setting)
            if ($file->isFile() && !str_starts_with(substr($file->getPathname(), strlen($folder) + 1), 'files/')) {
                $text = (string) file_get_contents($file->getPathname());
                foreach (['right-pw' => 'the SMTP password', $this->bootSecret => 'the boot secret'] as $secret => $what) {
                    $this->assertStringNotContainsString((string) $secret, $text, $this->step . ': ' . $file->getFilename() . ' holds ' . $what);
                }
            }
        }

        // ---------------------------------------------------------------- 7 the import
        $this->step('7 a follower exists; the owner uploads a WordPress file; the worker imports it');
        $inbox = $this->newSink('followers');
        $this->addFollower('alice', $this->baseUrl() . '_test/sink/' . $inbox);
        $wordpress = (string) file_get_contents(dirname(__DIR__) . '/Portability/fixtures/wordpress-export.xml');
        $upload = $this->browser->upload('admin/import/', 'admin/import/', ['import_type' => 'WordPress'], ['field' => 'import', 'name' => 'export.xml', 'type' => 'text/xml', 'bytes' => $wordpress], $owner);
        $this->assertSame(302, $upload->status, $this->step . $this->serverLog());
        $this->assertSame(1, $this->queued('portability/import'), $this->step . ': the page queued the job, once');
        $worker = $this->runWorkerOnce();
        $this->assertSame(0, $worker['exit'], $this->step . ': ' . $worker['output']);
        $this->assertSame([], $this->failedEvents(), $this->step);

        $imported = $this->articles();
        $this->assertSame(
            ['Draft: what lives under the jetty', 'Low tide, high hopes', 'Notes from the tide pool — café edition 🌊'],
            array_column($imported, 'title'),
            $this->step . ': the posts of the file, and only the posts (not its page or its attachment)'
        );
        $byTitle = array_column($imported, null, 'title');
        $this->assertSame(['PUBLIC', 'published'], [$byTitle['Low tide, high hopes']['access'], $byTitle['Low tide, high hopes']['status']], $this->step);
        $this->assertSame('draft', $byTitle['Draft: what lives under the jetty']['status'], $this->step . ': a draft stays a draft');
        $this->assertSame(gmdate('Y-m-d', 1708796700), gmdate('Y-m-d', (int) $byTitle['Low tide, high hopes']['created']), $this->step . ': the date of the file');

        $this->assertSame(200, $this->browser->get($this->path($byTitle['Low tide, high hopes']['url']))->status, $this->step . ': a published post is public');
        $this->assertStringContainsString('Low tide, high hopes', Browser::text($this->browser->get($this->path($byTitle['Low tide, high hopes']['url']))->body));
        $draftPath = $this->path($byTitle['Draft: what lives under the jetty']['url']);
        $asVisitor = $this->browser->get($draftPath);
        $this->assertSame(410, $asVisitor->status, $this->step . ': the draft is gone to a visitor (the status the site gives an entity one may not read, never a 5xx)');
        $this->assertStringNotContainsString('what lives under the jetty', $asVisitor->body, $this->step);
        $this->assertSame(410, $this->browser->get($draftPath, $bob)->status, $this->step . ': nor to another member');
        $this->assertSame(200, $this->browser->get($draftPath, $owner)->status, $this->step . ': but to its owner');
        $this->assertStringNotContainsString('what lives under the jetty', $this->browser->get('content/all/')->body, $this->step . ': it is on no public list');

        $mails = $this->mailsWith('Your import into');
        $this->assertCount(1, $mails, $this->step . $this->serverLog());
        $this->assertSame('alice@example.com', $mails[0]->header('To'));

        $this->step('7 nothing imported was announced to the follower; a post published by hand is');
        // What an import that announced its posts would leave: events still queued (the worker handles what it finds
        // when it starts, and what the import queues is queued after that) or deliveries already made. Neither.
        $this->assertSame(0, $this->queued('activitypub/deliver') + $this->queued('websub/ping') + $this->queued('webmention/sendall'), $this->step . ': the import queued no announcement');
        $this->assertSame([], $this->sink($inbox), $this->step . ': the follower was told nothing of the import');
        $this->assertSame(0, $this->runWorkerOnce()['exit'], $this->step . ': a second run, which would deliver what the import queued');
        $this->assertSame([], $this->sink($inbox), $this->step . ': and the follower still knows nothing of the import');
        $this->publishStatus($owner, 'Alice writes after the import');
        $this->assertSame(0, $this->runWorkerOnce()['exit'], $this->step);
        $delivered = $this->sink($inbox);
        $this->assertCount(1, $delivered, $this->step . ': the new post is federated' . $this->serverLog());
        $this->assertStringContainsString('Alice writes after the import', (string) base64_decode($delivered[0]['body'], true), $this->step);

        // ---------------------------------------------------------------- 8 the cache
        $this->step('8 a host\'s HSTS state recorded by one process is seen by another, and expires');
        $host = 'hsts-' . bin2hex(random_bytes(4)) . '.example.test';
        $maxAge = 5;
        $store = 'Idno\Core\Webservice::checkForHSTSHeader("https://' . $host . '/", ["HTTP/1.1 200 OK", "Strict-Transport-Security: max-age=' . $maxAge . '"]);'
            . 'return Idno\Core\Webservice::isHSTS("http://' . $host . '/");';
        $start = microtime(true);
        try {
            $this->assertTrue($this->runInSite($store, true), $this->step . ': the process that recorded it sees it');
            $this->assertTrue($this->runInSite('return Idno\Core\Webservice::isHSTS("http://' . $host . '/");', true), $this->step . ': another process sees it');
            $this->assertFalse($this->runInSite('return Idno\Core\Webservice::isHSTS("http://other-' . $host . '/");', true), $this->step . ': and only for that host');
            usleep((int) max(0, ($start + $maxAge + 1.5 - microtime(true)) * 1_000_000));
            $this->assertFalse($this->runInSite('return Idno\Core\Webservice::isHSTS("http://' . $host . '/");', true), $this->step . ': it has expired');
        } finally {
            $this->runInSite('\Idno\Core\Idno::site()->cache()->delete("' . $host . '"); return true;', true);
        }

        $this->step('the end: nothing failed, and no password was written to the site\'s log');
        $this->assertSame([], $this->failedEvents());
        foreach (['right-pw', 'wrong-pw'] as $password) {
            $this->assertStringNotContainsString($password, $this->serverLogContents(), 'the log holds no password');
        }
    }

    /**
     * The mail that ends an export names the page the archive is downloaded from, and the one that ends an
     * import says it is done. They are sent by the worker, a command-line process.
     *
     * They are sent by a command-line process, which must find the core templates as a web request does (a worker once
     * drew every core template as an empty string: Tests/Kernel/ConsoleTemplatesTest pins the cause), so both parts
     * of each mail are checked to have a body that names what it is about.
     */
    public function testTheMailsAWorkerSendsAboutAnExportAndAnImportHaveABody(): void
    {
        $owner = $this->registerUser('alice', self::PASSWORD);
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); foreach (["from_email" => "site@example.com", "smtp_host" => "127.0.0.1", "smtp_username" => "mailer", "smtp_password" => "right-pw"] as $k => $v) { $config->config[$k] = $v; } $config->save(); return true;');

        $this->browser->submit('admin/export/', 'admin/export/generate/', [], $owner);
        $this->assertSame(0, $this->runWorkerOnce()['exit']);
        $wordpress = (string) file_get_contents(dirname(__DIR__) . '/Portability/fixtures/wordpress-export.xml');
        $this->browser->upload('admin/import/', 'admin/import/', ['import_type' => 'WordPress'], ['field' => 'import', 'name' => 'export.xml', 'type' => 'text/xml', 'bytes' => $wordpress], $owner);
        $this->assertSame(0, $this->runWorkerOnce()['exit']);

        $export = $this->mailsWith('Your export of');
        $import = $this->mailsWith('Your import into');
        $this->assertCount(1, $export);
        $this->assertCount(1, $import);
        $text = (string) $export[0]->part('text/plain');
        $this->assertStringContainsString($this->baseUrl() . 'admin/export/', $text, 'the export mail links to the page the archive is fetched from');
        $this->assertDoesNotMatchRegularExpression('~/file/[0-9a-f]{32}~', $text, 'and, by design, not to the archive itself');
        $this->assertNotSame('', (string) $export[0]->part('text/html'));
        $importText = (string) $import[0]->part('text/plain');
        $this->assertStringContainsString('3 posts were imported', $importText, 'the import mail says how many posts it made');
        $this->assertStringContainsString('2 items', $importText, 'and how many items of the file it did not import');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Runs a step: records its label for the messages of the assertions that follow, and returns what $do returns.
     *
     * @template T
     *
     * @param (callable(): T)|null $do
     *
     * @return T|null
     */
    private function step(string $label, ?callable $do = null): mixed
    {
        $this->step = 'step ' . $label;

        return $do === null ? null : $do();
    }

    /**
     * Posts the admin's test-mail form and returns the text of the page the redirect leads to (the session's messages).
     */
    private function sendTestMail(string $owner, string $to): string
    {
        $post = $this->browser->submit('admin/email', 'admin/emailtest', ['to_email' => $to], $owner);
        $this->assertSame(302, $post->status, $this->step . substr($post->body, 0, 500) . $this->serverLog());

        return html_entity_decode(strip_tags($this->browser->get('admin/email', $owner)->body), ENT_QUOTES);
    }

    /**
     * The mail the scripted server received whose subject starts with $subject, oldest first.
     *
     * @return list<ReceivedMessage>
     */
    private function mailsWith(string $subject): array
    {
        $found = [];
        foreach ($this->smtp->messages() as $raw) {
            $message = ReceivedMessage::parse($raw);
            if (str_starts_with((string) $message->header('Subject'), $subject)) {
                $found[] = $message;
            }
        }

        return $found;
    }

    /**
     * The articles of the site whatever their status (read in a process of the site: a draft has no public page),
     * ordered by title.
     *
     * @return list<array{title: string, access: string, status: string, url: string, created: int}>
     */
    private function articles(): array
    {
        $rows = $this->runInSite(
            '\Idno\Core\Idno::site()->db()->setIgnoreAccess(true);' . "\n"
            . '$rows = []; foreach (\IdnoPlugins\Text\Entry::get([], [], 100) as $e) { $rows[] = ["title" => (string) $e->title, "access" => (string) $e->access, "status" => (string) ($e->getPublishStatus() ?: "published"), "url" => $e->getURL(), "created" => (int) $e->created]; }' . "\n"
            . 'usort($rows, static fn ($a, $b) => strcmp($a["title"], $b["title"])); return $rows;'
        );
        $this->assertIsArray($rows);

        return $rows;
    }

    /**
     * How many events of the name wait in the queue (a post, an upload and the like queue others too).
     */
    private function queued(string $event): int
    {
        return count(array_filter($this->queuedEvents(), static fn (array $e): bool => $e['event'] === $event));
    }

    private function path(string $url): string
    {
        $this->assertStringStartsWith($this->baseUrl(), $url);

        return substr($url, strlen($this->baseUrl()));
    }

    /**
     * Unpacks the zip archive $bytes into a new temporary directory (removed in tearDown()) and returns it.
     */
    private function unzip(string $bytes): string
    {
        $this->assertTrue(class_exists(\ZipArchive::class));
        $directory = sys_get_temp_dir() . '/idno-acceptance-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $this->scratch[] = $directory;
        file_put_contents($directory . '.zip', $bytes);
        $this->scratch[] = $directory . '.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($directory . '.zip') === true, $this->step . ': the download is a zip archive');
        $this->assertTrue($zip->extractTo($directory));
        $zip->close();

        return $directory;
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
