<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use Idno\Install\InstallCode;
use Idno\Install\InstallPaths;
use Idno\Install\SetupToken;
use Idno\Install\WebInstaller;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web installer, driven with requests on a project root of the test's own: what each step
 * shows, what the last step writes, and that nothing is written before it, twice or over anything.
 */
final class WebInstallerTest extends TestCase
{
    use TemporaryRoot;

    private const COOKIE = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed.');
        }
        $this->makeRoot();
    }

    protected function tearDown(): void
    {
        $this->removeRoot();
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $server
     */
    private function request(string $method, array $fields = [], array $server = [], bool $cookie = true): Response
    {
        if ($method === 'POST' && !array_key_exists('token', $fields)) {
            $fields['token'] = WebInstaller::fieldFor(self::COOKIE);
        }
        $request = Request::create('https://example.com/', $method, $fields, $cookie ? [WebInstaller::COOKIE => self::COOKIE] : [], [], $server);

        return (new WebInstaller(new InstallPaths($this->root)))->handle($request);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function save(array $fields = [], array $server = []): Response
    {
        return $this->request('POST', $fields + ['step' => 'database', 'title' => 'Ada\'s notes', 'domain' => 'example.com', 'database' => 'sqlite'], $server);
    }

    /**
     * @return array<string, string|false>
     */
    private function config(): array
    {
        $config = parse_ini_file($this->root . '/configuration/config.ini');
        self::assertIsArray($config);

        return $config;
    }

    public function testTheFirstPageChecksTheServerAndWritesNothing(): void
    {
        $response = $this->request('GET', [], [], false);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Checking your server</h1>', (string) $response->getContent());
        self::assertStringContainsString('This server can run Idno.', (string) $response->getContent());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame(WebInstaller::COOKIE, $cookies[0]->getName());
        self::assertTrue($cookies[0]->isHttpOnly());
        self::assertTrue($cookies[0]->isSecure());
        self::assertSame('strict', $cookies[0]->getSameSite());
        self::assertSame([], $this->filesInRoot());
    }

    public function testPlainHttpOnADomainStopsAtTheFirstPage(): void
    {
        $request = Request::create('http://example.com/', 'POST', ['step' => 'check', 'token' => WebInstaller::fieldFor(self::COOKIE)], [WebInstaller::COOKIE => self::COOKIE]);
        $html = (string) (new WebInstaller(new InstallPaths($this->root)))->handle($request)->getContent();

        self::assertStringContainsString('Secure connection (HTTPS)', $html);
        self::assertStringContainsString('Needs fixing', $html);
        self::assertStringNotContainsString('<h1>Your site</h1>', $html);
        self::assertStringNotContainsString('>Continue<', $html);

        $forwarded = Request::create('http://example.com/', 'POST', ['step' => 'check', 'token' => WebInstaller::fieldFor(self::COOKIE)], [WebInstaller::COOKIE => self::COOKIE], [], ['HTTP_X_FORWARDED_PROTO' => 'https']);
        self::assertStringContainsString('<h1>Your site</h1>', (string) (new WebInstaller(new InstallPaths($this->root)))->handle($forwarded)->getContent());
    }

    public function testTheStepsFollowOneAnotherAndCarryWhatWasTyped(): void
    {
        $site = (string) $this->request('POST', ['step' => 'check', 'rewrite' => 'ok'])->getContent();
        self::assertStringContainsString('<h1>Your site</h1>', $site);
        self::assertStringContainsString('name="domain" value="example.com"', $site);

        $database = (string) $this->request('POST', ['step' => 'site', 'title' => 'Ada & "co"', 'domain' => 'Notes.Example.com'])->getContent();
        self::assertStringContainsString('<h1>Where should Idno keep your site?</h1>', $database);
        self::assertStringContainsString('name="title" value="Ada &amp; &quot;co&quot;"', $database);
        self::assertStringContainsString('name="domain" value="notes.example.com"', $database);
        self::assertMatchesRegularExpression('/value="sqlite" checked/', $database);
        self::assertStringContainsString('class="needs-mysql off"', $database);

        $back = (string) $this->request('POST', ['step' => 'database', 'back' => '1', 'title' => 'Ada', 'domain' => 'notes.example.com'])->getContent();
        self::assertStringContainsString('<h1>Your site</h1>', $back);
        self::assertStringContainsString('name="title" value="Ada"', $back);
        self::assertSame([], $this->filesInRoot());
    }

    public function testAPageAddressThatDoesNotWorkStopsTheFirstStep(): void
    {
        $html = (string) $this->request('POST', ['step' => 'check', 'rewrite' => 'fail'])->getContent();

        self::assertStringContainsString('<li id="check-rewrite">', $html);
        self::assertStringNotContainsString('<h1>Your site</h1>', $html);
    }

    /**
     * The first page asks the web server for a shipped file in the upload folder: when the server hands it over, uploaded
     * files would be served without their posts' access rules, and the installation stops there and says how to refuse it.
     */
    public function testUploadedFilesTheServerServesStopTheFirstStep(): void
    {
        $page = (string) $this->request('GET')->getContent();
        self::assertStringContainsString("fetch('./Uploads/readme.txt'", $page);
        self::assertStringContainsString('idno-uploads-probe', $page);
        self::assertStringContainsString('<li id="check-uploads" hidden>', $page);

        $html = (string) $this->request('POST', ['step' => 'check', 'rewrite' => 'ok', 'uploads' => 'served'])->getContent();
        self::assertStringContainsString('<li id="check-uploads">', $html);
        self::assertStringContainsString('location ^~ /Uploads/ { deny all; }', $html);
        self::assertStringNotContainsString('<h1>Your site</h1>', $html);

        self::assertStringContainsString('<h1>Your site</h1>', (string) $this->request('POST', ['step' => 'check', 'rewrite' => 'ok', 'uploads' => 'refused'])->getContent());
        self::assertSame([], $this->filesInRoot());
    }

    public function testTheLastStepGivesTheUploadFolderItsOwnRefusalAndKeepsOneThatIsThere(): void
    {
        file_put_contents($this->root . '/Uploads/.htaccess', "# the operator's own\n");
        $this->save();

        self::assertSame("# the operator's own\n", file_get_contents($this->root . '/Uploads/.htaccess'));
    }

    public function testADomainThatIsNotOneIsExplainedBesideItsField(): void
    {
        foreach (['https://example.com', 'example.com/blog', '', "x\ny"] as $domain) {
            $html = (string) $this->request('POST', ['step' => 'site', 'title' => 'Ada', 'domain' => $domain])->getContent();
            self::assertStringContainsString('<h1>Your site</h1>', $html);
            self::assertStringContainsString('id="field-domain-error"', $html);
            self::assertStringContainsString('aria-invalid="true"', $html);
        }
        self::assertStringContainsString('<h1>Your site</h1>', (string) $this->save(['domain' => 'example.com/x'])->getContent());
        self::assertSame([], $this->filesInRoot());
    }

    public function testTheLastStepWritesTheConfigurationTheDatabaseAndTheSetupTokenAndSendsTheBrowserToTheSite(): void
    {
        $response = $this->save();

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->headers->get('Location'));
        $config = $this->config();
        self::assertSame('sqlite', $config['database']);
        self::assertSame($this->root . '/data/idno.sqlite', $config['dbname']);
        self::assertSame('https://example.com/', $config['url']);
        self::assertSame($this->root . '/Uploads/', $config['uploadpath']);
        self::assertSame(['Uploads/.htaccess', 'configuration/config.ini', 'configuration/setup-pending', 'data/idno.sqlite'], array_values(array_filter($this->filesInRoot(), static fn (string $f): bool => !str_contains($f, '.sqlite-'))));

        $setup = new SetupToken(new InstallPaths($this->root));
        self::assertSame('Ada\'s notes', $setup->title());
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }
        self::assertTrue($setup->matches($cookies[SetupToken::COOKIE]->getValue()));
        // The token is the value the browser already holds: if this response is lost (a double click),
        // the browser's installer cookie still opens the account step.
        self::assertSame(self::COOKIE, $cookies[SetupToken::COOKIE]->getValue());
        self::assertTrue($setup->matches(self::COOKIE));
        self::assertTrue($cookies[SetupToken::COOKIE]->isHttpOnly());
        self::assertTrue($cookies[SetupToken::COOKIE]->isSecure());
        self::assertSame('lax', $cookies[SetupToken::COOKIE]->getSameSite());
        self::assertGreaterThan(time() + 86400, $cookies[SetupToken::COOKIE]->getExpiresTime());
    }

    public function testASecondSubmissionChangesNothing(): void
    {
        $this->save();
        $config = file_get_contents($this->root . '/configuration/config.ini');
        $marker = file_get_contents($this->root . '/configuration/setup-pending');

        $again = $this->save(['title' => 'Other', 'domain' => 'other.example']);

        self::assertSame(303, $again->getStatusCode());
        self::assertSame($config, file_get_contents($this->root . '/configuration/config.ini'));
        self::assertSame($marker, file_get_contents($this->root . '/configuration/setup-pending'));
        self::assertSame([], array_filter($again->headers->getCookies(), static fn ($c): bool => $c->getName() === SetupToken::COOKIE));
    }

    public function testAFormWithoutTheBrowsersTokenIsNotAccepted(): void
    {
        foreach ([['token' => 'wrong'], ['token' => ['x']], ['token' => WebInstaller::fieldFor(str_repeat('a', 64))]] as $fields) {
            $html = (string) $this->save($fields)->getContent();
            self::assertStringContainsString('<h1>Checking your server</h1>', $html);
            self::assertStringContainsString('Please start again', $html);
        }
        self::assertStringContainsString('Please start again', (string) $this->request('POST', ['step' => 'database', 'domain' => 'example.com', 'database' => 'sqlite'], [], false)->getContent());
        self::assertSame([], $this->filesInRoot());
    }

    public function testNothingIsWrittenByAGetWhateverItCarries(): void
    {
        $request = Request::create('https://example.com/?step=database&domain=example.com&database=sqlite&token=' . WebInstaller::fieldFor(self::COOKIE), 'GET', [], [WebInstaller::COOKIE => self::COOKIE]);
        $html = (string) (new WebInstaller(new InstallPaths($this->root)))->handle($request)->getContent();

        self::assertStringContainsString('<h1>Checking your server</h1>', $html);
        self::assertSame([], $this->filesInRoot());
    }

    public function testMongoIsRefusedWithTheMessageThatNamesTheImport(): void
    {
        $html = (string) $this->save(['database' => 'MongoDB'])->getContent();

        self::assertStringContainsString('MongoDB is no longer supported', $html);
        self::assertStringContainsString('idno:import:mongo', $html);
        self::assertSame([], $this->filesInRoot());
    }

    public function testArraysWhereStringsAreExpectedAreEmptyAndRaiseNothing(): void
    {
        $html = (string) $this->request('POST', ['step' => ['database'], 'title' => ['x'], 'domain' => ['example.com'], 'database' => ['sqlite']])->getContent();
        self::assertStringContainsString('<h1>Checking your server</h1>', $html);
        $html = (string) $this->save(['title' => ['x'], 'domain' => ['example.com']])->getContent();
        self::assertStringContainsString('<h1>Your site</h1>', $html);
        self::assertSame([], $this->filesInRoot());
    }

    public function testASqlitePathInTheRequestIsIgnored(): void
    {
        $this->save(['sqlite_path' => 'shell.php', 'path' => '/tmp/x.sqlite', 'upload_path' => '/tmp/', 'uploadpath' => '/tmp/']);

        self::assertSame($this->root . '/data/idno.sqlite', $this->config()['dbname']);
        self::assertSame($this->root . '/Uploads/', $this->config()['uploadpath']);
        self::assertFileDoesNotExist($this->root . '/shell.php');
    }

    public function testAMysqlPasswordWithAQuoteIsRefusedBeforeAnythingIsDoneAndIsNeverPrinted(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('pdo_mysql is needed.');
        }
        $html = (string) $this->save(['database' => 'mysql', 'mysql_name' => 'idno', 'mysql_user' => 'u', 'mysql_pass' => "se'cret-pass", 'mysql_host' => 'localhost'])->getContent();

        self::assertStringContainsString('id="field-mysql_pass-error"', $html);
        self::assertStringNotContainsString('cret-pass', $html);
        self::assertStringContainsString('name="mysql_name" value="idno"', $html);
        self::assertStringNotContainsString('needs-mysql off', $html);
        self::assertSame([], $this->filesInRoot());
    }

    public function testMysqlNeedsItsNameAndServer(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('pdo_mysql is needed.');
        }
        $html = (string) $this->save(['database' => 'mysql', 'mysql_host' => ' '])->getContent();

        self::assertStringContainsString('id="field-mysql_name-error"', $html);
        self::assertStringContainsString('id="field-mysql_host-error"', $html);
        self::assertSame([], $this->filesInRoot());
    }

    public function testAFailedLastStepLeavesNothingBehind(): void
    {
        // The configuration folder cannot be written to: the database file made for this request is removed again.
        chmod($this->root . '/configuration', 0555);
        try {
            $html = (string) $this->save()->getContent();
        } finally {
            chmod($this->root . '/configuration', 0755);
        }

        self::assertStringContainsString('<h1>Checking your server</h1>', $html);
        self::assertSame([], $this->filesInRoot());
    }

    private function makeAnExistingSite(): string
    {
        $path = $this->root . '/data/idno.sqlite';
        $connection = \Idno\Storage\ConnectionFactory::forConfig(['database' => 'sqlite', 'dbname' => $path]);
        (new \Idno\Storage\SchemaMigrator($connection))->migrate();
        $connection->close();

        return $path;
    }

    private function code(): string
    {
        $text = file_get_contents($this->root . '/' . InstallCode::relativePath());
        self::assertIsString($text);

        return trim($text);
    }

    /**
     * A database that already holds a site is adopted only by someone who can read a file on the server: the
     * code the installer writes to configuration/install-code. Without it nothing is written but that file.
     */
    public function testAnExistingIdnoDatabaseIsUsedOnlyWithTheCodeFromTheServerAndIsNeverRemoved(): void
    {
        $path = $this->makeAnExistingSite();
        $before = sha1_file($path);

        $response = $this->save();
        $html = (string) $response->getContent();
        self::assertStringContainsString('already holds an Idno site', $html);
        self::assertStringContainsString('name="use_existing"', $html);
        self::assertStringContainsString('name="install_code"', $html);
        self::assertStringContainsString('configuration/install-code', $html);
        self::assertStringNotContainsString($this->root, $html, 'the file is named from the project');
        self::assertSame(['configuration/install-code', 'data/idno.sqlite'], $this->filesInRoot(), 'only the code was written');
        self::assertSame(0600, fileperms($this->root . '/configuration/install-code') & 0777);
        $code = $this->code();
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $code);
        self::assertStringNotContainsString($code, $html . $response->headers, 'the code is never printed or sent');

        // Ticking the box is not enough, and neither is a code that is not the one in the file.
        foreach ([['use_existing' => '1'], ['use_existing' => '1', 'install_code' => str_repeat('0', 32)], ['use_existing' => '1', 'install_code' => ['x']], ['use_existing' => '1', 'install_code' => substr($code, 0, 31)]] as $fields) {
            $html = (string) $this->save($fields)->getContent();
            self::assertStringContainsString('already holds an Idno site', $html);
            self::assertFileDoesNotExist($this->root . '/configuration/config.ini');
        }
        self::assertStringContainsString('id="field-install_code-error"', (string) $this->save(['use_existing' => '1', 'install_code' => str_repeat('0', 32)])->getContent());
        self::assertSame($code, $this->code(), 'visiting the page again does not replace the code the person at the server is reading');
        self::assertSame($before, sha1_file($path), 'the database is untouched');
        self::assertSame(['configuration/install-code', 'data/idno.sqlite'], $this->filesInRoot());

        // The code without the box changes nothing either.
        self::assertStringContainsString('already holds an Idno site', (string) $this->save(['install_code' => $code])->getContent());
        self::assertFileDoesNotExist($this->root . '/configuration/config.ini');

        // With both (spaces and capitals as a terminal may give them), the site is adopted, at the address shown.
        $adopted = $this->save(['use_existing' => '1', 'install_code' => ' ' . strtoupper(substr($code, 0, 16)) . ' ' . substr($code, 16) . "\n", 'domain' => 'notes.example.com']);
        self::assertSame(303, $adopted->getStatusCode());
        self::assertSame($path, $this->config()['dbname']);
        self::assertSame('https://notes.example.com/', $this->config()['url']);
        self::assertFileDoesNotExist($this->root . '/configuration/install-code', 'the code is used once');
        self::assertFileExists($path);
    }

    public function testTheAdoptionPageShowsTheAddressThatWillBeWrittenForConfirmation(): void
    {
        $this->makeAnExistingSite();

        $html = (string) $this->save(['domain' => 'notes.example.com'])->getContent();

        self::assertMatchesRegularExpression('/<input id="field-domain" name="domain" value="notes\.example\.com"/', $html, 'the address is a field the person sees and confirms with the code');
        $refused = (string) $this->save(['domain' => 'notes.example.com/evil', 'use_existing' => '1', 'install_code' => $this->code()])->getContent();
        self::assertStringContainsString('id="field-domain-error"', $refused);
        self::assertFileDoesNotExist($this->root . '/configuration/config.ini');
    }

    public function testAnExpiredCodeIsRefusedAndReplaced(): void
    {
        $this->makeAnExistingSite();
        $this->save();
        $code = $this->code();
        touch($this->root . '/' . InstallCode::relativePath(), time() - InstallCode::LIFETIME - 1);

        $html = (string) $this->save(['use_existing' => '1', 'install_code' => $code])->getContent();

        self::assertStringContainsString('already holds an Idno site', $html);
        self::assertFileDoesNotExist($this->root . '/configuration/config.ini');
        self::assertNotSame($code, $this->code(), 'a new code is written in place of the expired one');
    }

    public function testAUsedCodeDoesNotAdoptTheDatabaseAgain(): void
    {
        $this->makeAnExistingSite();
        $this->save();
        $code = $this->code();
        self::assertSame(303, $this->save(['use_existing' => '1', 'install_code' => $code])->getStatusCode());
        unlink($this->root . '/configuration/config.ini');
        unlink($this->root . '/configuration/setup-pending');

        $html = (string) $this->save(['use_existing' => '1', 'install_code' => $code])->getContent();

        self::assertStringContainsString('already holds an Idno site', $html);
        self::assertFileDoesNotExist($this->root . '/configuration/config.ini');
        self::assertNotSame($code, $this->code());
    }

    public function testADoubleSubmitWithTheCodeAdoptsOnceAndAStrangerAfterwardsChangesNothing(): void
    {
        $this->makeAnExistingSite();
        $this->save();
        $code = $this->code();

        self::assertSame(303, $this->save(['use_existing' => '1', 'install_code' => $code])->getStatusCode());
        $config = file_get_contents($this->root . '/configuration/config.ini');
        self::assertSame(303, $this->save(['use_existing' => '1', 'install_code' => $code, 'domain' => 'other.example'])->getStatusCode());
        self::assertSame(303, $this->save(['use_existing' => '1', 'domain' => 'evil.example'])->getStatusCode());

        self::assertSame($config, file_get_contents($this->root . '/configuration/config.ini'));
        self::assertFileDoesNotExist($this->root . '/configuration/install-code');
    }

    public function testTheCodeAndTheSetupTokenAreNotOneAnother(): void
    {
        $this->makeAnExistingSite();
        $this->save();
        $code = $this->code();
        $paths = new InstallPaths($this->root);
        $setup = new SetupToken($paths);
        $token = $setup->issue('Ada');

        self::assertFalse($setup->matches($code));
        self::assertFalse((new InstallCode($paths))->matches($token));
        self::assertTrue((new InstallCode($paths))->matches($code));
    }

    public function testANewSiteOnAnEmptyDatabaseNeedsNoCode(): void
    {
        self::assertSame(303, $this->save()->getStatusCode());
        self::assertFileDoesNotExist($this->root . '/' . InstallCode::relativePath());
    }

    public function testAFileThatIsNotADatabaseIsRefusedAndLeftAsItIs(): void
    {
        file_put_contents($this->root . '/data/idno.sqlite', 'not a database');

        $html = (string) $this->save()->getContent();

        self::assertStringContainsString('could not be opened as an SQLite database', $html);
        self::assertSame('not a database', file_get_contents($this->root . '/data/idno.sqlite'));
        self::assertFileDoesNotExist($this->root . '/configuration/config.ini');
        self::assertStringNotContainsString($this->root, $html, 'a file is named from the project, never by the server\'s path');
        self::assertStringContainsString('data/idno.sqlite could not be opened', $html);
    }

    public function testEveryValueIsEscaped(): void
    {
        $canary = '"><x-canary a=\'1';
        foreach ([
            $this->request('POST', ['step' => 'site', 'title' => $canary, 'domain' => $canary]),
            $this->request('POST', ['step' => 'site', 'title' => $canary, 'domain' => 'example.com']),
            $this->save(['title' => $canary, 'database' => 'mysql', 'mysql_name' => $canary, 'mysql_user' => $canary, 'mysql_host' => $canary]),
            $this->save(['title' => $canary, 'database' => $canary]),
        ] as $response) {
            self::assertStringNotContainsString('<x-canary', (string) $response->getContent());
        }
        $request = Request::create('https://example.com/', 'GET', [], [], [], ['HTTP_HOST' => $canary]);
        self::assertStringNotContainsString('<x-canary', (string) (new WebInstaller(new InstallPaths($this->root)))->handle($request)->getContent());
    }

    public function testThePagesAreSelfContained(): void
    {
        foreach ([$this->request('GET'), $this->request('POST', ['step' => 'check']), $this->request('POST', ['step' => 'site', 'domain' => 'example.com'])] as $response) {
            $html = (string) $response->getContent();
            self::assertSame(1, substr_count($html, '<h1>'));
            self::assertDoesNotMatchRegularExpression('#(src|href)="(https?:)?//#', $html);
            self::assertStringNotContainsString('<img', $html);
            self::assertStringNotContainsString('<link', $html);
            self::assertStringContainsString('name="viewport"', $html);
            preg_match_all('/<input(?![^>]*type="(hidden|radio|checkbox)")[^>]*id="([^"]+)"/', $html, $inputs);
            foreach ($inputs[2] as $id) {
                self::assertStringContainsString('<label for="' . $id . '">', $html);
            }
        }
    }
}
