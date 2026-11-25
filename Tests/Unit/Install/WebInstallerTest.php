<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

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
        self::assertSame(['configuration/config.ini', 'configuration/setup-pending', 'data/idno.sqlite'], array_values(array_filter($this->filesInRoot(), static fn (string $f): bool => !str_contains($f, '.sqlite-'))));

        $setup = new SetupToken(new InstallPaths($this->root));
        self::assertSame('Ada\'s notes', $setup->title());
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }
        self::assertTrue($setup->matches($cookies[SetupToken::COOKIE]->getValue()));
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

    public function testAnExistingIdnoDatabaseIsUsedOnlyWhenConfirmedAndIsNeverRemoved(): void
    {
        $path = $this->root . '/data/idno.sqlite';
        $connection = \Idno\Storage\ConnectionFactory::forConfig(['database' => 'sqlite', 'dbname' => $path]);
        (new \Idno\Storage\SchemaMigrator($connection))->migrate();
        $connection->close();

        $html = (string) $this->save()->getContent();
        self::assertStringContainsString('already holds an Idno site', $html);
        self::assertStringContainsString('name="use_existing"', $html);
        self::assertFileDoesNotExist($this->root . '/configuration/config.ini');
        self::assertFileExists($path);

        self::assertSame(303, $this->save(['use_existing' => '1'])->getStatusCode());
        self::assertSame($path, $this->config()['dbname']);
    }

    public function testAFileThatIsNotADatabaseIsRefusedAndLeftAsItIs(): void
    {
        file_put_contents($this->root . '/data/idno.sqlite', 'not a database');

        $html = (string) $this->save()->getContent();

        self::assertStringContainsString('could not be opened as an SQLite database', $html);
        self::assertSame('not a database', file_get_contents($this->root . '/data/idno.sqlite'));
        self::assertFileDoesNotExist($this->root . '/configuration/config.ini');
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
