<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Storage\AttributeTypeMap;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\IndexedFields;
use Idno\Storage\Platform\MySqlDialect;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use Idno\Upgrade\LegacyDataCopier;
use Idno\Upgrade\SchemaGate;
use Idno\Upgrade\SchemaState;
use Idno\Upgrade\UpgradeLock;
use Idno\Upgrade\UpgradeProgress;
use Idno\Upgrade\UpgradeRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Storage\PrivateDatabase;

/**
 * The upgrade end to end: a site whose database is a 1.x one (LegacyFixture, on a private database
 * of the rig's MySQL or MariaDB server), or empty, served by PHP's built-in web server in a
 * subprocess (`php -S` on a free port, never the rig's 8089, through Tests/rig/router.php, with
 * PHP_CLI_SERVER_WORKERS so that two requests can run at once) whose environment names that
 * database (IDNO_DBNAME) and the server's URL (IDNO_URL), and nothing else of the developer's: the
 * subprocess connects with the rig server's credentials (the rig's configuration/config.ini), and
 * only ever to the private database IDNO_DBNAME names. The console runs the same way. The server is
 * stopped (a bounded wait, then SIGKILL) and the database dropped whatever the outcome (tearDown).
 *
 * Test-environment hooks the subprocesses may be given (config/services_test.php; no request can
 * set them): IDNO_TEST_UPGRADE_STEP_SECONDS (the step's time budget: 0 makes every step do one unit
 * of work, so an upgrade takes many steps) and IDNO_TEST_FAIL_FIRST_CONNECTION (the first
 * connection attempt fails, as a server refusing one connection would).
 *
 * The empty-database case also runs on SQLite (a file in a temporary directory of the test's own);
 * the rest skips there: no 1.x site runs on SQLite.
 */
final class UpgradeEndToEndTest extends TestCase
{
    /**
     * The DBAL statements of an anonymous homepage request on the fixture site once upgraded (see
     * testTheHomepageStatementsArePinned): the catalog, the gate's probe, and the legacy application's.
     */
    private const HOMEPAGE_STATEMENTS = 13;

    /** The most steps a whole upgrade of the fixture may take. */
    private const MAX_STEPS = 100;

    private ?LegacyFixture $fixture = null;

    private ?Connection $connection = null;

    private string $dbname = '';

    private ?string $directory = null;

    /** @var resource|null */
    private $server = null;

    private string $url = '';

    private string $serverLog = '';

    /** @var list<int> the entities' copied count of each `working` answer of the last stepUntilCurrent() */
    private array $copiedCounts = [];

    /** @var array<string, string> the subprocesses' environment beyond environment()'s */
    private array $extraEnvironment = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/idno-upgrade-e2e-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $port = self::freePort();
        $this->url = 'http://127.0.0.1:' . $port . '/';
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        if ($this->fixture !== null) {
            $this->fixture->drop();
            $this->fixture = null;
        } elseif ($this->connection !== null) {
            if (($this->connection->getParams()['driver'] ?? '') === 'pdo_sqlite') {
                $this->connection->close();
            } else {
                PrivateDatabase::drop($this->connection);
            }
        }
        $this->connection = null;
        if ($this->directory !== null) {
            foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->directory);
            $this->directory = null;
        }
    }

    // ------------------------------------------------------------------ the cases

    public function testASiteOnAOneXDatabaseUpgradesItselfOverHttp(): void
    {
        $site = $this->oneXSite(1200);
        // Every step does one unit of work (an index, a batch of the copy, the rename...): the upgrade
        // takes many steps, and the page sees it move.
        $this->extraEnvironment = ['IDNO_TEST_UPGRADE_STEP_SECONDS' => '0'];
        $this->startServer();

        // While the gate is closed: the page for a browser, plain text for everything else.
        $page = $this->request('GET', '/', ['Accept: text/html,application/xhtml+xml']);
        $this->assertSame(503, $page['status'], $page['body']);
        $this->assertSame('30', $page['headers']['retry-after'] ?? null);
        $this->assertStringContainsString('no-store', $page['headers']['cache-control'] ?? '');
        $this->assertStringContainsString('<h1>Idno is upgrading its database</h1>', $page['body']);
        $this->assertStringContainsString("default-src 'none'; script-src 'nonce-", $page['headers']['content-security-policy'] ?? '');
        $this->assertDoesNotMatchRegularExpression('#https?:|//[a-z0-9]#i', $page['body'], 'no URL to another origin');

        $head = $this->request('HEAD', '/', ['Accept: text/html']);
        $this->assertSame(503, $head['status']);
        $this->assertSame('', $head['body']);
        $this->assertSame('30', $head['headers']['retry-after'] ?? null);

        foreach ([
            ['POST', '/inbox', ['Content-Type: application/activity+json', 'Accept: application/activity+json'], '{"type":"Follow"}'],
            ['POST', '/profile/' . $site['handle'] . '/inbox', ['Content-Type: application/activity+json'], '{"type":"Follow"}'],
            ['GET', '/', ['Accept: application/activity+json'], null],
            ['GET', '/?_t=rss', ['Accept: application/rss+xml'], null],
            ['GET', '/_upgrade/step', ['Accept: application/json'], null],
            ['POST', '/_upgrade/step', ['Accept: application/json', 'X-HTTP-Method-Override: DELETE'], null],
            ['POST', '/_upgrade/step', ['Accept: application/json', 'X-HTTP-Method-Override: PATCH'], null],
            ['POST', '/_upgrade/step/', ['Accept: application/json'], null],
            ['OPTIONS', '/_upgrade/step', ['Accept: application/json'], null],
        ] as [$method, $path, $headers, $body]) {
            $answer = $this->request($method, $path, $headers, $body);
            $this->assertSame(503, $answer['status'], $method . ' ' . $path);
            $this->assertSame('text/plain; charset=UTF-8', $answer['headers']['content-type'] ?? null, $method . ' ' . $path);
            $this->assertSame('30', $answer['headers']['retry-after'] ?? null, $method . ' ' . $path);
            $this->assertSame("Idno is upgrading its database.\n", $answer['body'], $method . ' ' . $path);
        }

        // A request Symfony rejects before routing (a malformed Host header) is not handed to the
        // legacy router, which has not booted: a page load still gets the upgrade page, and a step
        // Symfony's own 400.
        $this->assertSame(503, $this->request('GET', '/', ['Accept: text/html', 'Host: bad host!'])['status']);
        $rejected = $this->request('POST', '/_upgrade/step', ['Accept: application/json', 'Host: bad host!']);
        $this->assertSame(400, $rejected['status'], $rejected['body'] . $this->log());

        // The steps, until the schema is current: the copied count rises over several `working` steps.
        $statuses = $this->stepUntilCurrent();
        $this->assertSame('current', end($statuses));
        $rising = array_values(array_unique(array_filter($this->copiedCounts, static fn (int $count): bool => $count > 0)));
        $this->assertGreaterThanOrEqual(2, count($rising), 'at least two working answers with different copied counts: ' . implode(', ', $this->copiedCounts));
        $sorted = $rising;
        sort($sorted);
        $this->assertSame($sorted, $rising, 'strictly increasing');

        // The site is up, with its 1.x content.
        $home = $this->request('GET', '/', ['Accept: text/html']);
        $this->assertSame(200, $home['status'], substr($home['body'], 0, 2000) . $this->log());
        $post = $this->request('GET', '/view/' . $site['post'], ['Accept: text/html']);
        $this->assertSame(200, $post['status'], substr($post['body'], 0, 2000));
        $this->assertStringContainsString($site['body'], $post['body']);
        $profile = $this->request('GET', '/profile/' . $site['handle'], ['Accept: text/html']);
        $this->assertSame(200, $profile['status'], substr($profile['body'], 0, 2000));

        $tables = $this->tables();
        $this->assertContains('legacy_entities', $tables);
        $this->assertNotContains('entities_metadata', $tables);
        $this->assertNotContains('entities', $tables);
        $this->assertSame(1202, (int) $this->db()->fetchOne('select count(*) from idno_entities'));

        // With the gate open the endpoint only says so.
        $step = $this->request('POST', '/_upgrade/step', ['Accept: application/json']);
        $this->assertSame(200, $step['status']);
        $this->assertSame('current', json_decode($step['body'], true)['status'] ?? null);
    }

    /**
     * After the rename the 1.x tables are `legacy_<name>`, but the copy is verified against them
     * before it is complete: the site must not run (and write) in between.
     */
    public function testTheGateStaysClosedBetweenTheRenameAndCompletion(): void
    {
        $this->oneXSite(1200);
        $runner = $this->runner();
        for ($i = 0; $i < self::MAX_STEPS && $this->copier()->progress()->phase !== UpgradeProgress::VERIFYING; ++$i) {
            $runner->step(microtime(true) - 1);
        }
        $this->assertSame(UpgradeProgress::VERIFYING, $this->copier()->progress()->phase);
        $this->assertFalse($this->copier()->progress()->complete);
        $this->assertContains('legacy_entities', $this->tables());
        $this->startServer();

        $this->assertSame(503, $this->request('GET', '/', ['Accept: text/html'])['status']);
        $this->assertSame(503, $this->request('POST', '/inbox', ['Content-Type: application/activity+json'], '{}')['status']);

        $this->stepUntilCurrent();
        $this->assertSame(200, $this->request('GET', '/', ['Accept: text/html'])['status']);
    }

    /**
     * A step that cannot get the lock (another process is upgrading: here the test holds it) reports
     * `working` with the progress and copies nothing; that is the proof of serialisation. Then two
     * steps sent at the same time (they may or may not overlap) leave every row copied once.
     */
    public function testAStepThatCannotGetTheLockDoesNothingAndTwoAtOnceCopyEachRowOnce(): void
    {
        $this->oneXSite(1200);
        $runner = $this->runner();
        for ($i = 0; $i < self::MAX_STEPS && $this->copier()->progress()->collections['entities']['copied'] === 0; ++$i) {
            $runner->step(microtime(true) - 1);
        }
        $copied = $this->copier()->progress()->collections['entities']['copied'];
        $this->assertGreaterThan(0, $copied);
        $this->startServer();

        $holder = $this->connect();
        $lock = new UpgradeLock($holder);
        $this->assertTrue($lock->acquire());
        $answer = $this->request('POST', '/_upgrade/step', ['Accept: application/json']);
        $data = json_decode($answer['body'], true);
        $this->assertSame(200, $answer['status'], $answer['body']);
        $this->assertSame('working', $data['status'] ?? null);
        $this->assertSame($copied, $data['collections']['entities']['copied'] ?? null, 'nothing was copied while another process held the lock');
        $lock->release();
        $holder->close();

        $answers = $this->parallel(2, 'POST', '/_upgrade/step');
        foreach ($answers as $answer) {
            $this->assertContains(json_decode($answer['body'], true)['status'] ?? null, ['working', 'current'], $answer['body']);
        }
        $this->stepUntilCurrent();
        $this->assertSame(1202, (int) $this->db()->fetchOne('select count(*) from idno_entities'));
        $this->assertSame(1202, (int) $this->db()->fetchOne('select count(distinct uuid) from idno_entities'));
    }

    /**
     * What stopped the upgrade is on the page (escaped) and in the step's JSON.
     */
    public function testAStoppedUpgradeShowsWhy(): void
    {
        $this->oneXSite(30);
        // Until the copy has begun (a legacy_ table there before it begins stops it at once, with
        // nothing stored): one unit of work per step.
        for ($i = 0; $i < 100 && $this->copier()->progress()->phase === UpgradeProgress::NOT_STARTED; ++$i) {
            $this->runner()->step(microtime(true) - 1);
        }
        $this->assertSame(UpgradeProgress::COPYING, $this->copier()->progress()->phase);
        // Something else made a legacy_ table beside its 1.x table: the rename is blocked.
        $this->db()->executeStatement('create table legacy_versions like versions');
        $this->startServer();

        $answer = $this->request('POST', '/_upgrade/step', ['Accept: application/json']);
        $data = json_decode($answer['body'], true);
        $this->assertSame(500, $answer['status'], $answer['body']);
        $this->assertSame('error', $data['status'] ?? null);
        $this->assertStringContainsString('versions and legacy_versions both exist', (string) $data['message']);
        $this->assertSame($data['message'], $data['error']);

        $this->db()->executeStatement('update idno_upgrade_progress set error = ?', ['The <b>rename</b> & "the rest"']);
        $page = $this->request('GET', '/', ['Accept: text/html']);
        $this->assertSame(503, $page['status']);
        $this->assertStringContainsString('The &lt;b&gt;rename&lt;/b&gt; &amp; &quot;the rest&quot;', $page['body']);
        $this->assertStringNotContainsString('<b>rename</b>', $page['body']);

        // Without JavaScript: the form's post goes back to the site's own root.
        $form = $this->request('POST', '/_upgrade/step?html=1', ['Referer: https://elsewhere.example/']);
        $this->assertSame(303, $form['status']);
        $this->assertSame('/', $form['headers']['location'] ?? null);
    }

    /**
     * A copy about to start beside rows already in the new tables (here the settings of a site in
     * use) stops before anything is written: the step's JSON, the page and `idno:upgrade` say why.
     */
    public function testACopyBesideRowsInTheNewTablesNeverStarts(): void
    {
        $this->oneXSite(30);
        $this->fixture?->prepareNewSchema();
        $this->db()->executeStatement("insert into idno_settings (name, value, position) values ('title', '\"A site in use\"', 0)");
        $message = \Idno\Upgrade\PublicMessage::newTablesNotEmpty(['idno_settings'])->text;
        $this->startServer();

        $answer = $this->request('POST', '/_upgrade/step', ['Accept: application/json']);
        $data = json_decode($answer['body'], true);
        $this->assertSame(500, $answer['status'], $answer['body']);
        $this->assertSame('error', $data['status'] ?? null);
        $this->assertSame($message, $data['message'] ?? null);

        $page = $this->request('GET', '/', ['Accept: text/html']);
        $this->assertSame(503, $page['status']);
        $this->assertStringContainsString(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'), $page['body']);

        [$exit, , $stderr] = $this->console('idno:upgrade');
        $this->assertSame(1, $exit);
        $this->assertStringContainsString($message, $stderr);
        $this->assertSame(0, (int) $this->db()->fetchOne('select count(*) from idno_upgrade_progress'), 'no upgrade begun');
        $this->assertSame(0, (int) $this->db()->fetchOne('select count(*) from idno_entities'), 'nothing copied');
    }

    /**
     * While something holds the 1.x tables (an open transaction that has read one, as a backup's
     * does), the rename gives up after its short wait: the step answers `working` with the reason as
     * its message (HTTP 200, no error stored), and the upgrade finishes once they are free.
     * `idno:upgrade` waits as for the lock and then gives up with that reason.
     */
    public function testTablesInUseHoldUpTheRenameWithoutStoppingTheUpgrade(): void
    {
        $this->oneXSite(30);
        $holder = $this->connect();
        $holder->beginTransaction();
        $holder->fetchAllAssociative('select * from entities limit 1');
        $notice = \Idno\Upgrade\PublicMessage::tablesInUse()->text;
        try {
            $this->holdUpTheRename($holder, $notice);
        } finally {
            // Never leave the tables held: dropping the database would wait for them.
            if ($holder->isTransactionActive()) {
                $holder->rollBack();
            }
            $holder->close();
        }
        $statuses = $this->stepUntilCurrent();
        $this->assertSame('current', end($statuses));
        $this->assertContains('legacy_entities', $this->tables());
    }

    private function holdUpTheRename(Connection $holder, string $notice): void
    {

        $lines = [];
        $started = microtime(true);
        $result = $this->runner(lockWait: 3.0)->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertSame('error', $result['status']);
        $this->assertSame($notice, $result['message']);
        $this->assertContains('Waiting: ' . $notice, $lines);
        $this->assertLessThan(30.0, microtime(true) - $started, 'it gives up');
        $this->assertContains('entities', $this->tables(), 'nothing renamed');
        $this->assertNull($this->copier()->progress()->error, 'nothing stored as an error');

        $this->startServer();
        $seen = false;
        for ($i = 0; $i < 10 && !$seen; ++$i) {
            $answer = $this->request('POST', '/_upgrade/step', ['Accept: application/json']);
            $data = json_decode($answer['body'], true);
            $this->assertSame(200, $answer['status'], $answer['body']);
            $this->assertSame('working', $data['status'] ?? null, $answer['body']);
            $this->assertNull($data['error'] ?? null);
            $seen = ($data['message'] ?? null) === $notice;
        }
        $this->assertTrue($seen, 'a step said why it could not go on');
        $this->assertNotContains('legacy_entities', $this->tables());

        $holder->commit();
    }

    /**
     * A failure's detail (a verification mismatch names the record; a driver error carries the
     * server's message) never reaches the page or the JSON: they show the one generic sentence; the
     * detail is in the log and in `idno:upgrade --status`.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function privateFailures(): iterable
    {
        yield 'a verification mismatch' => ["create trigger idno_e2e before insert on idno_entities for each row set new.data = concat(new.data, ' ')", 'does not match its source'];
        // The driver's message carries the record's uuid (http://127.0.0.1:<port>/view/…): both leak shapes, a
        // uuid and a driver message, are in the detail and must stay out of the page and the JSON.
        yield 'a driver error' => ["create trigger idno_e2e before insert on idno_entities for each row begin declare m varchar(128); set m = concat('refused by e2e-secret-trigger ', new.uuid); signal sqlstate '45000' set message_text = m; end", 'e2e-secret-trigger http://127.0.0.1:'];
    }

    /**
     * @dataProvider privateFailures
     */
    public function testAFailuresDetailIsNeverPublic(string $trigger, string $detail): void
    {
        $this->oneXSite(30);
        // The new schema and its indexes, the copy not begun.
        $runner = $this->runner();
        for ($i = 0; $i < self::MAX_STEPS && (new SchemaMigrator($this->db()))->pending() !== []; ++$i) {
            $runner->step(microtime(true) - 1);
        }
        $this->assertSame(0, (int) $this->db()->fetchOne('select count(*) from idno_upgrade_progress'));
        $this->db()->executeStatement($trigger);
        $this->startServer();

        $answer = $this->request('POST', '/_upgrade/step', ['Accept: application/json']);
        $data = json_decode($answer['body'], true);
        $this->assertSame(500, $answer['status'], $answer['body']);
        $this->assertSame('error', $data['status'] ?? null);
        $this->assertSame(\Idno\Upgrade\PublicMessage::stopped()->text, $data['message']);
        $this->assertSame(\Idno\Upgrade\PublicMessage::stopped()->text, $data['error']);
        $page = $this->request('GET', '/', ['Accept: text/html']);
        foreach ([$answer['body'], $page['body']] as $public) {
            $this->assertStringNotContainsString($detail, $public);
            $this->assertStringNotContainsString('view/', $public, 'no uuid');
            $this->assertStringNotContainsString('SQLSTATE', $public);
        }
        $this->assertStringContainsString('The upgrade stopped.', $page['body']);
        $this->assertStringContainsString($detail, $this->log(), 'the detail is logged');

        [, $stdout] = $this->console('idno:upgrade', '--status');
        $this->assertStringContainsString('Detail: ', $stdout);
        $this->assertStringContainsString($detail, $stdout, 'idno:upgrade shows it');
    }

    /**
     * The three renamed 1.x `entities` tables with no upgrade on record (an operator dropped the new
     * tables to start again without renaming the 1.x ones back): closed with a message saying what
     * to do; no step migrates, reconciles or copies anything; idno:upgrade exits 1 with the same
     * message. On an empty database (the 1.x tables renamed, nothing else) and on a migrated one with
     * no progress row.
     *
     * @return iterable<string, array{bool}>
     */
    public static function renamedWithoutRecord(): iterable
    {
        yield 'empty database' => [false];
        yield 'migrated database, no progress row' => [true];
    }

    /**
     * @dataProvider renamedWithoutRecord
     */
    public function testRenamedOneXTablesWithoutARecordStopEverything(bool $migrated): void
    {
        $this->oneXSite(30);
        $this->db()->executeStatement('rename table ' . implode(', ', array_map(static fn (string $t): string => $t . ' to legacy_' . $t, LegacyDataCopier::LEGACY_TABLES)));
        if ($migrated) {
            (new SchemaMigrator($this->db()))->migrate();
        }
        $before = $this->snapshot();
        $message = \Idno\Upgrade\PublicMessage::renamedWithoutRecord()->text;
        $this->startServer();

        $page = $this->request('GET', '/', ['Accept: text/html']);
        $this->assertSame(503, $page['status']);
        $this->assertStringContainsString(htmlspecialchars($message, ENT_QUOTES | ENT_HTML5), $page['body']);
        $this->assertStringNotContainsString('<script', $page['body']);
        $answer = $this->request('POST', '/_upgrade/step', ['Accept: application/json']);
        $this->assertSame('error', json_decode($answer['body'], true)['status'] ?? null, $answer['body']);
        $this->assertSame($message, json_decode($answer['body'], true)['message'] ?? null);

        [$exit, , $stderr] = $this->console('idno:upgrade');
        $this->assertSame(1, $exit);
        $this->assertStringContainsString($message, $stderr);
        [$exit, $stdout, $stderr] = $this->console('idno:upgrade', '--status');
        $this->assertSame(1, $exit, '--status exits 1 for a state the upgrade refuses');
        $this->assertStringStartsWith('Schema status: ' . \Idno\Upgrade\SchemaStatus::RenamedWithoutRecord->value . "\n", $stdout);
        $this->assertStringContainsString($message, $stderr);
        $this->assertSame($before, $this->snapshot(), 'nothing was changed (no idno_ table created on the empty database)');
    }

    /**
     * In the copy phase a 1.x table is dropped by hand: an upgrade is on record and not complete, so
     * the gate stays closed (the probe says so, whatever the tables look like), and the step and the
     * page name the missing table.
     */
    public function testAOneXTableDroppedInTheCopyPhaseKeepsTheGateClosed(): void
    {
        $this->oneXSite(1200);
        $runner = $this->runner();
        for ($i = 0; $i < self::MAX_STEPS && $this->copier()->progress()->collections['entities']['copied'] === 0; ++$i) {
            $runner->step(microtime(true) - 1);
        }
        $this->db()->executeStatement('set foreign_key_checks = 0');
        $this->db()->executeStatement('drop table entities_metadata');
        $this->db()->executeStatement('set foreign_key_checks = 1');
        $this->startServer();

        $this->assertSame(503, $this->request('GET', '/', ['Accept: text/html'])['status']);
        $answer = $this->request('POST', '/_upgrade/step', ['Accept: application/json']);
        $data = json_decode($answer['body'], true);
        $this->assertSame('error', $data['status'] ?? null, $answer['body']);
        $this->assertStringContainsString('entities_metadata', (string) $data['message']);
        $this->assertSame(503, $this->request('GET', '/', ['Accept: text/html'])['status']);
    }

    /**
     * In the verify phase one of the renamed tables is dropped by hand: the gate stays closed (an
     * upgrade is on record and not complete), and the step and the page name the missing table.
     */
    public function testARenamedTableDroppedMidUpgradeKeepsTheGateClosed(): void
    {
        $this->oneXSite(1200);
        $runner = $this->runner();
        for ($i = 0; $i < self::MAX_STEPS && $this->copier()->progress()->phase !== UpgradeProgress::VERIFYING; ++$i) {
            $runner->step(microtime(true) - 1);
        }
        $this->assertSame(UpgradeProgress::VERIFYING, $this->copier()->progress()->phase);
        $this->db()->executeStatement('drop table legacy_entities_search');
        $this->startServer();

        $this->assertSame(503, $this->request('GET', '/', ['Accept: text/html'])['status']);
        $answer = $this->request('POST', '/_upgrade/step', ['Accept: application/json']);
        $data = json_decode($answer['body'], true);
        $this->assertSame('error', $data['status'] ?? null, $answer['body']);
        $this->assertStringContainsString('legacy_entities_search', (string) $data['message']);
        $this->assertStringContainsString('legacy_entities_search', $this->request('GET', '/', ['Accept: text/html'])['body']);
        $this->assertFalse($this->copier()->progress()->complete);
    }

    /**
     * After completion, 1.x tables an old worker recreates are ignored: the site stays open. A 1.x
     * process booting after the rename runs every dated 1.x upgrade file and re-creates exactly
     * `reader`, `versions`, `entities_search`, `entities_metadata`, `site`, `site_metadata` and
     * `site_search` (never `entities`): two of the three `entities` tables, which is why only all
     * three mean a 1.x site is back (next test). Another two-of-three set stays open as well.
     */
    public function testOneXTablesRecreatedAfterCompletionAreIgnored(): void
    {
        $this->oneXSite(30);
        $this->runner()->run(static function (string $line): void {
        });
        $this->assertTrue($this->copier()->progress()->complete);
        // What an old worker creates (its dated upgrade files), in LEGACY_TABLES order.
        $recreated = ['entities_metadata', 'entities_search', 'reader', 'site', 'site_metadata', 'site_search', 'versions'];
        foreach ($recreated as $table) {
            $this->db()->executeStatement('create table ' . $table . ' like legacy_' . $table);
        }
        $this->startServer();

        $this->assertSame(200, $this->request('GET', '/', ['Accept: text/html'])['status'], $this->log());
        $this->assertSame($recreated, $this->copier()->reappearedTables());

        // Another two of the three: still open.
        $this->db()->executeStatement('drop table entities_search');
        $this->db()->executeStatement('create table entities like legacy_entities');
        $this->assertSame(200, $this->request('GET', '/', ['Accept: text/html'])['status'], $this->log());
    }

    /**
     * After completion, `entities`, `entities_metadata` AND `entities_search` back again (the undo's
     * RENAME, a restored backup) close the site, in every entry point, with one message: the earlier
     * copy is never opened over a 1.x site that may have changed since. Nothing is changed, and
     * `--drop-legacy` refuses too (with and without --yes).
     */
    public function testTheOneXEntitiesTablesBackBesideACompletedUpgradeCloseTheSite(): void
    {
        $this->oneXSite(30);
        $this->runner()->run(static function (string $line): void {
        });
        $this->assertTrue($this->copier()->progress()->complete);
        foreach (LegacyDataCopier::EVIDENCE_TABLES as $table) {
            $this->db()->executeStatement('create table ' . $table . ' like legacy_' . $table);
        }
        $before = $this->snapshot();
        $message = \Idno\Upgrade\PublicMessage::oneXBesideCompletedUpgrade()->text;
        $this->startServer();

        $page = $this->request('GET', '/', ['Accept: text/html']);
        $this->assertSame(503, $page['status'], $this->log());
        $this->assertStringContainsString(htmlspecialchars($message, ENT_QUOTES | ENT_HTML5), $page['body']);
        $this->assertStringNotContainsString('<script', $page['body']);
        $this->assertStringNotContainsString('<form', $page['body']);
        $plain = $this->request('GET', '/feed.xml', ['Accept: application/rss+xml']);
        $this->assertSame(503, $plain['status']);
        $this->assertSame('Idno cannot start: ' . $message . "\n", $plain['body']);
        $answer = $this->request('POST', '/_upgrade/step', ['Accept: application/json']);
        $this->assertSame('error', json_decode($answer['body'], true)['status'] ?? null, $answer['body']);
        $this->assertSame($message, json_decode($answer['body'], true)['message'] ?? null);

        [$exit, , $stderr] = $this->console('idno:upgrade');
        $this->assertSame(1, $exit);
        $this->assertStringContainsString($message, $stderr);
        [$exit, $stdout, $stderr] = $this->console('idno:upgrade', '--status');
        $this->assertSame(1, $exit);
        $this->assertStringStartsWith('Schema status: ' . \Idno\Upgrade\SchemaStatus::OneXBesideCompletedUpgrade->value . "\n", $stdout);
        $this->assertStringContainsString($message, $stderr);
        foreach ([['--drop-legacy'], ['--drop-legacy', '--yes']] as $options) {
            [$exit, $stdout, $stderr] = $this->console('idno:upgrade', ...$options);
            $this->assertSame(1, $exit, implode(' ', $options) . "\nstdout: $stdout\nstderr: $stderr");
            $this->assertStringContainsString($message, $stderr);
        }
        [$exit, , $stderr] = $this->console('version');
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString(\Idno\Upgrade\SchemaGate::CLOSED_MESSAGE, $stderr);
        $this->assertSame($before, $this->snapshot(), 'nothing was changed, no legacy_ table dropped');
    }

    /**
     * The admin's page of what the upgrade left behind (/admin/legacytables), on an upgraded site in
     * the Twenty26 theme: the menu entry, the legacy tables with their counts, the rejects (escaped,
     * never their contents), and the delete, which needs the CSRF token AND the confirmation.
     */
    public function testTheAdminReviewsAndDeletesTheLegacyTables(): void
    {
        $password = bin2hex(random_bytes(12));
        $this->fixture = LegacyFixture::create('e2e');
        $this->connection = $this->fixture->connection;
        $this->dbname = (string) $this->connection->getDatabase();
        $site = $this->fixture->insertSite($this->url, plugins: ['Status', 'Text'], password: $password, theme: 'Twenty26');
        $this->fixture->insertMany('entities', 30);
        $odd = 'https://legacy.example/view/<i>odd</i>&amp;';
        $this->fixture->insert('entities', ['title' => 'Unreadable'], ['uuid' => $odd], 'not <b>JSON</b> at all');
        $this->runner()->run(static function (string $line): void {
        });
        $this->assertTrue($this->copier()->progress()->complete);
        $this->startServer();
        $cookie = $this->logIn($site['handle'], $password);
        $html = ['Accept: text/html', 'Cookie: ' . $cookie];

        $admin = $this->request('GET', '/admin/', $html);
        $this->assertSame(200, $admin['status'], substr($admin['body'], 0, 2000) . $this->log());
        $this->assertStringContainsString('idno-admin-nav', $admin['body'], 'the Twenty26 admin menu');
        $this->assertStringContainsString($this->url . 'admin/legacytables/', $admin['body'], 'the menu entry, while there is something to see');

        $page = $this->request('GET', '/admin/legacytables/', $html);
        $this->assertSame(200, $page['status'], substr($page['body'], 0, 2000) . $this->log());
        $this->assertStringContainsString('idno-admin-page-title', $page['body']);
        $this->assertMatchesRegularExpression('#<code>legacy_entities</code></td><td>\d+</td>#', $page['body'], 'the 1.x entities with their (approximate) count');
        $this->assertStringContainsString('approximate', $page['body']);
        foreach (LegacyDataCopier::LEGACY_TABLES as $table) {
            $this->assertStringContainsString('<code>legacy_' . $table . '</code>', $page['body']);
        }
        $this->assertStringContainsString('id="legacytables-rejects"', $page['body']);
        $this->assertStringContainsString('<code>https://legacy.example/view/&lt;i&gt;odd&lt;/i&gt;&amp;amp;</code>', $page['body'], 'the uuid, escaped');
        $this->assertStringNotContainsString('<i>odd</i>', $page['body']);
        $this->assertStringContainsString('contents are not valid JSON', $page['body']);
        $this->assertStringNotContainsString('JSON</b> at all', $page['body'], 'never the contents');
        $this->assertStringNotContainsString('JSON&lt;/b&gt; at all', $page['body']);
        $this->assertStringContainsString('name="confirm"', $page['body']);
        $before = $this->tables();

        // Without the CSRF token, and with the token but not the confirmation: nothing is deleted.
        $this->request('POST', '/admin/legacytables/', [...$html, 'Content-Type: application/x-www-form-urlencoded'], http_build_query(['confirm' => 'delete']));
        $this->assertSame($before, $this->tables());
        $answer = $this->request('GET', '/admin/legacytables/', $html)['body'];
        $this->assertStringContainsString('Invalid token.', $answer, 'refused by the token check');
        $this->assertStringNotContainsString('Deleted ', $answer);
        $token = $this->formToken($page['body'], 'admin/legacytables/');
        $refused = $this->request('POST', '/admin/legacytables/', [...$html, 'Content-Type: application/x-www-form-urlencoded'], http_build_query($token + ['confirm' => 'Delete']));
        $this->assertSame(302, $refused['status'], $refused['body']);
        $this->assertSame($before, $this->tables());
        $this->assertStringContainsString('Nothing was deleted: type delete in the box to confirm.', $this->request('GET', '/admin/legacytables/', $html)['body']);

        $deleted = $this->request('POST', '/admin/legacytables/', [...$html, 'Content-Type: application/x-www-form-urlencoded'], http_build_query($token + ['confirm' => 'delete']));
        $this->assertSame(302, $deleted['status'], $deleted['body'] . $this->log());
        $this->assertStringStartsWith($this->url . 'admin/legacytables/', $deleted['headers']['location'] ?? '', 'back to the page (the legacy forward() keeps the template type)');
        $tables = $this->tables();
        foreach (LegacyDataCopier::LEGACY_TABLES as $table) {
            $this->assertNotContains('legacy_' . $table, $tables);
        }
        $this->assertSame(1, (int) $this->db()->fetchOne('select count(*) from idno_upgrade_rejects'), 'the rejects are kept');
        $after = $this->request('GET', '/admin/legacytables/', $html);
        $this->assertStringContainsString('Deleted 14 legacy tables: legacy_entities_metadata, legacy_entities_search, ', $after['body'], 'what drop() removed, in the order it dropped them');
        $this->assertStringNotContainsString('id="legacytables-tables"', $after['body']);
        $this->assertStringContainsString('id="legacytables-rejects"', $after['body']);
        $this->assertStringContainsString($this->url . 'admin/legacytables/', $this->request('GET', '/admin/', $html)['body'], 'the rejects keep the entry');

        $this->assertSame(200, $this->request('GET', '/', ['Accept: text/html'])['status'], 'the site is up');
        $post = $this->request('GET', '/view/' . $site['post'], ['Accept: text/html']);
        $this->assertSame(200, $post['status']);
        $this->assertStringContainsString($site['body'], $post['body']);
    }

    /**
     * A delete blocked by a foreign key from a table outside the fourteen (a 1.x plugin's table that
     * referenced `entities` followed the rename): the console says what it deleted before the error,
     * names the referencing table and constraint, and exits 1; the admin page says a table outside
     * the 1.x tables refers to the legacy table, and deletes nothing more.
     */
    public function testADeleteBlockedByAnotherTablesForeignKeyIsExplainedOnTheConsoleAndThePage(): void
    {
        $password = bin2hex(random_bytes(12));
        $this->fixture = LegacyFixture::create('e2e');
        $this->connection = $this->fixture->connection;
        $this->dbname = (string) $this->connection->getDatabase();
        $site = $this->fixture->insertSite($this->url, plugins: ['Status', 'Text'], password: $password, theme: 'Twenty26');
        $this->fixture->insertMany('entities', 3);
        $this->runner()->run(static function (string $line): void {
        });
        $this->assertTrue($this->copier()->progress()->complete);
        $this->db()->executeStatement('create table plugin_things (id int primary key, entity_id varchar(36) character set utf8, constraint plugin_things_entity foreign key (entity_id) references legacy_entities (_id)) engine=InnoDB');

        [$exit, $stdout, $stderr] = $this->console('idno:upgrade', '--drop-legacy', '--yes');
        $this->assertSame(1, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertStringContainsString('Deleted before the error: legacy_entities_metadata, legacy_entities_search, ', $stdout);
        $this->assertStringContainsString('legacy_entities', $stderr);
        $this->assertStringContainsString('plugin_things', $stderr);
        $this->assertStringContainsString('plugin_things_entity', $stderr);
        $this->assertStringContainsString('Deal with that table first; running the command again cannot succeed until then.', $stderr);
        $this->assertContains('legacy_entities', $this->tables());
        $remaining = $this->tables();

        $this->startServer();
        $cookie = $this->logIn($site['handle'], $password);
        $html = ['Accept: text/html', 'Cookie: ' . $cookie];
        $page = $this->request('GET', '/admin/legacytables/', $html);
        $this->assertSame(200, $page['status'], substr($page['body'], 0, 2000) . $this->log());
        $token = $this->formToken($page['body'], 'admin/legacytables/');
        $refused = $this->request('POST', '/admin/legacytables/', [...$html, 'Content-Type: application/x-www-form-urlencoded'], http_build_query($token + ['confirm' => 'delete']));
        $this->assertSame(302, $refused['status'], $refused['body'] . $this->log());
        $this->assertSame($remaining, $this->tables(), 'nothing more deleted');
        $after = $this->request('GET', '/admin/legacytables/', $html)['body'];
        $this->assertStringContainsString('A table outside the Idno 1.x tables refers to the legacy table legacy_entities (it has a foreign key into it)', $after);
        $this->assertStringNotContainsString('Deleted ', $after);
        $this->db()->executeStatement('drop table plugin_things');
    }

    /**
     * `idno:upgrade --drop-legacy`: refused before completion, a list and exit 1 without --yes, the
     * delete with it, then nothing to do.
     */
    public function testTheConsoleDeletesTheLegacyTablesOnlyWhenToldTo(): void
    {
        $this->oneXSite(30);
        $this->fixture?->insert('entities', ['title' => 'Unreadable'], [], 'not JSON');
        $runner = $this->runner();
        for ($i = 0; $i < self::MAX_STEPS && $this->copier()->progress()->phase !== UpgradeProgress::VERIFYING; ++$i) {
            $runner->step(microtime(true) - 1);
        }
        $this->assertSame(UpgradeProgress::VERIFYING, $this->copier()->progress()->phase);
        $before = $this->tables();

        [$exit, $stdout, $stderr] = $this->console('idno:upgrade', '--drop-legacy', '--yes');
        $this->assertSame(1, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertStringContainsString('The upgrade from Idno 1.x is not complete', $stderr);
        $this->assertSame($before, $this->tables(), 'nothing deleted, nothing upgraded');

        $runner->run(static function (string $line): void {
        });
        $this->assertTrue($this->copier()->progress()->complete);
        $before = $this->tables();

        [$exit, $stdout, $stderr] = $this->console('idno:upgrade', '--yes');
        $this->assertSame(1, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertStringContainsString('--yes', $stderr);

        [$exit, $stdout, $stderr] = $this->console('idno:upgrade', '--drop-legacy');
        $this->assertSame(1, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertStringContainsString('approximate', $stdout);
        foreach (LegacyDataCopier::LEGACY_TABLES as $table) {
            $this->assertMatchesRegularExpression('#^  legacy_' . $table . ': about \d+ rows?$#m', $stdout);
        }
        $this->assertStringContainsString('--drop-legacy --yes', $stderr);
        $this->assertSame($before, $this->tables(), 'without --yes nothing is deleted');

        [$exit, $stdout, $stderr] = $this->console('idno:upgrade', '--drop-legacy', '--yes');
        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        // (stderr carries the booted legacy application's own log lines, such as a plugin's warning.)
        $this->assertStringNotContainsString('legacy', $stderr);
        $this->assertStringContainsString('Deleted 14 legacy tables: legacy_entities_metadata, ', $stdout, 'what drop() removed, in the order it dropped them');
        $this->assertStringContainsString(', legacy_session.', $stdout);
        $this->assertStringContainsString('1 row that was not carried over is kept in idno_upgrade_rejects.', $stdout);
        $tables = $this->tables();
        foreach (LegacyDataCopier::LEGACY_TABLES as $table) {
            $this->assertNotContains('legacy_' . $table, $tables);
        }
        $this->assertSame(1, (int) $this->db()->fetchOne('select count(*) from idno_upgrade_rejects'));

        [$exit, $stdout, $stderr] = $this->console('idno:upgrade', '--drop-legacy');
        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame("Nothing to delete: there are no legacy tables.\n", $stdout);

        [$exit, $stdout] = $this->console('idno:upgrade', '--status');
        $this->assertSame(0, $exit);
        $this->assertStringStartsWith("Schema status: current\n", $stdout);
    }

    /**
     * A database that refuses the first connection and would accept the next (error 1040, "Too many
     * connections", while every visitor's page is stepping): the gate saw it unreachable, and that is
     * final for the request. The legacy application does not boot on the database (nothing is
     * written), the visitor gets the legacy database error page, and a step answers 503 `working`.
     * On an empty database, a 1.x one, and one whose copy is in progress.
     *
     * @return iterable<string, array{string}>
     */
    public static function notCurrentDatabases(): iterable
    {
        yield 'empty' => ['empty'];
        yield '1.x' => ['1.x'];
        yield 'copy in progress' => ['copying'];
    }

    /**
     * @dataProvider notCurrentDatabases
     */
    public function testAConnectionRefusedOnceNeverBootsTheSiteOnADatabaseThatIsNotCurrent(string $kind): void
    {
        if ($kind === 'empty') {
            $this->emptyDatabase();
            if (($this->db()->getParams()['driver'] ?? '') === 'pdo_sqlite') {
                $this->markTestSkipped('SQLite has no server to refuse a connection; the middleware is tested in the unit tier.');
            }
        } else {
            $this->oneXSite(1200);
            if ($kind === 'copying') {
                $runner = $this->runner();
                for ($i = 0; $i < self::MAX_STEPS && $this->copier()->progress()->collections['entities']['copied'] === 0; ++$i) {
                    $runner->step(microtime(true) - 1);
                }
            }
        }
        $before = $this->snapshot();
        $this->extraEnvironment = ['IDNO_TEST_FAIL_FIRST_CONNECTION' => '1'];
        $this->startServer();

        $page = $this->request('GET', '/', ['Accept: text/html']);
        $this->assertSame(500, $page['status'], substr($page['body'], 0, 1500));
        $this->assertStringContainsString('connect to the database', $page['body'], 'the legacy database error page');
        $this->assertStringContainsString('Too many connections', $this->log(), 'logged as before');

        $step = $this->request('POST', '/_upgrade/step', ['Accept: application/json']);
        $this->assertSame(503, $step['status'], $step['body']);
        $this->assertSame('working', json_decode($step['body'], true)['status'] ?? null);
        $this->assertSame('30', $step['headers']['retry-after'] ?? null);
        $this->assertStringContainsString('no-store', $step['headers']['cache-control'] ?? '');

        $this->assertSame($before, $this->snapshot(), 'nothing was written');
    }

    /**
     * With the database down, one request makes exactly one connection attempt (the gate's): the
     * legacy application's attempt gets the same failure without connecting. The "server" is a port
     * this test listens on, which accepts and drops every connection.
     */
    public function testADownDatabaseIsConnectedToOncePerRequest(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        stream_set_blocking($listener, false);
        $address = (string) stream_socket_get_name($listener, false);
        $this->extraEnvironment = [
            'IDNO_DATABASE' => 'mysql',
            'IDNO_DBHOST' => '127.0.0.1',
            'IDNO_DBPORT' => substr($address, strrpos($address, ':') + 1),
            'IDNO_DBNAME' => 'idno_down',
        ];
        $this->startServer();

        $multi = curl_multi_init();
        $handle = $this->curl('GET', '/', ['Accept: text/html'], null);
        curl_multi_add_handle($multi, $handle);
        $attempts = 0;
        $deadline = microtime(true) + 60;
        do {
            curl_multi_exec($multi, $running);
            $client = @stream_socket_accept($listener, 0.05);
            if ($client !== false) {
                ++$attempts;
                fclose($client);
            }
        } while ($running > 0 && microtime(true) < $deadline);
        $answer = $this->answer($handle, (string) curl_multi_getcontent($handle));
        curl_multi_remove_handle($multi, $handle);
        curl_multi_close($multi);
        while (($client = @stream_socket_accept($listener, 0.2)) !== false) {
            ++$attempts;
            fclose($client);
        }
        fclose($listener);

        $this->assertSame(500, $answer['status'], substr($answer['body'], 0, 1500) . $this->log());
        $this->assertStringContainsString('connect to the database', $answer['body']);
        $this->assertSame(1, $attempts, 'one connection attempt for the request');
    }

    /**
     * The statements one anonymous homepage request sends through DBAL (a test-environment middleware
     * logs them: IDNO_TEST_STATEMENT_LOG; the PHP session handler's statements go through the native
     * PDO and are not in it). The gate's are exactly the catalog read (which the record store
     * reuses) and the one probe; and the total is the same whatever legacy_ or 1.x-named tables a
     * current site has: after a completed upgrade with its legacy_ tables, with them deleted, with
     * 1.x tables recreated by an old worker, with another application's legacy_config.
     */
    public function testTheHomepageStatementsArePinned(): void
    {
        $this->oneXSite(30);
        $this->runner()->run(static function (string $line): void {
        });
        $log = $this->directory . '/statements.log';
        $this->extraEnvironment = ['IDNO_TEST_STATEMENT_LOG' => $log];
        $this->startServer();
        $this->request('GET', '/', ['Accept: text/html']);

        $counts = [];
        $states = [
            'completed upgrade, legacy_ tables kept' => static function (): void {
            },
            'legacy_ tables deleted' => function (): void {
                // MariaDB checks the 1.x foreign keys between the tables of one DROP TABLE.
                $this->db()->executeStatement('set foreign_key_checks = 0');
                $this->db()->executeStatement('drop table ' . implode(', ', array_map(static fn (string $t): string => 'legacy_' . $t, LegacyDataCopier::LEGACY_TABLES)));
                $this->db()->executeStatement('set foreign_key_checks = 1');
            },
            '1.x tables recreated after completion' => function (): void {
                // What an old worker creates (its dated upgrade files): two of the three `entities`
                // tables, never `entities` (all three close the site).
                foreach (['reader', 'versions', 'entities_search', 'entities_metadata', 'site', 'site_metadata', 'site_search'] as $table) {
                    $this->db()->executeStatement('create table ' . $table . ' (x int)');
                }
            },
            'another application\'s legacy_config' => function (): void {
                $this->db()->executeStatement('create table legacy_config (x int)');
            },
        ];
        foreach ($states as $state => $change) {
            $change();
            file_put_contents($log, '');
            $page = $this->request('GET', '/', ['Accept: text/html']);
            $this->assertSame(200, $page['status'], $state . $this->log());
            $statements = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
            $this->assertSame('select table_name, column_name from information_schema.columns where table_schema = database()', $statements[0] ?? null, $state);
            $this->assertSame("select version from idno_migrations union all select case when completed_at is null then '#incomplete' else '#complete' end from idno_upgrade_progress", $statements[1] ?? null, $state);
            foreach (array_slice($statements, 2) as $statement) {
                $this->assertDoesNotMatchRegularExpression('/idno_upgrade_progress|legacy_|\bversions\b|idno_migrations/', $statement, $state . ': the gate asks nothing more');
            }
            $counts[$state] = count($statements);
        }
        $this->assertSame(array_fill_keys(array_keys($states), self::HOMEPAGE_STATEMENTS), $counts, implode("\n", $statements ?? []));
    }

    public function testAnEmptyDatabaseIsSetUpByTheFirstStep(): void
    {
        $this->emptyDatabase();
        $this->startServer();

        $page = $this->request('GET', '/', ['Accept: text/html']);
        $this->assertSame(503, $page['status']);
        $this->assertStringContainsString('<h1>Idno is setting up its database</h1>', $page['body']);
        // Where the web installer sends the browser once it has written the configuration.
        $this->assertSame(503, $this->request('GET', '/begin/register', ['Accept: text/html'])['status']);

        $answer = $this->request('POST', '/_upgrade/step', ['Accept: application/json']);
        $this->assertSame(200, $answer['status'], $answer['body'] . $this->log());
        $this->assertSame('current', json_decode($answer['body'], true)['status'] ?? null, $answer['body']);

        $register = $this->request('GET', '/begin/register', ['Accept: text/html']);
        $this->assertNotSame(503, $register['status']);
        $this->assertSame(200, $register['status'], substr($register['body'], 0, 2000) . $this->log());
        $this->assertNotSame(503, $this->request('GET', '/', ['Accept: text/html'])['status']);
    }

    public function testATooOldDatabaseIsToldToUpgradeFirst(): void
    {
        $this->fixture = LegacyFixture::create('e2e');
        $this->connection = $this->fixture->connection;
        $this->dbname = (string) $this->connection->getDatabase();
        $this->fixture->insertSite($this->url);
        $this->db()->executeStatement("update versions set value = '2019121401' where label = 'schema'");
        $before = $this->checksums();
        $this->startServer();

        $page = $this->request('GET', '/', ['Accept: text/html']);
        $this->assertSame(503, $page['status']);
        $this->assertStringContainsString('1.6.4', $page['body']);
        $this->assertStringNotContainsString('<script', $page['body']);
        $this->assertStringNotContainsString('<form', $page['body']);

        $answer = $this->request('POST', '/_upgrade/step', ['Accept: application/json']);
        $data = json_decode($answer['body'], true);
        $this->assertSame('too_old', $data['status'] ?? null, $answer['body']);
        $this->assertStringContainsString('1.6.4', (string) $data['message']);

        [$exit, $stdout, $stderr] = $this->console('idno:upgrade', '--status');
        $this->assertSame(1, $exit, '--status exits 1 for a state the upgrade refuses');
        $this->assertStringStartsWith("Schema status: legacy too old\n", $stdout);
        $this->assertStringContainsString(\Idno\Upgrade\PublicMessage::tooOld()->text, $stderr);

        $this->assertSame([], array_values(array_filter($this->tables(), static fn (string $table): bool => str_starts_with($table, 'idno_'))), 'nothing was created');
        $this->assertSame($before, $this->checksums(), 'the 1.x tables are untouched');
    }

    public function testTheConsoleUpgradesInOneRun(): void
    {
        $site = $this->oneXSite(1200);

        [$exit, $stdout, $stderr] = $this->console('idno:upgrade');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame('', $stderr);
        $this->assertStringContainsString('Ran the migration ', $stdout);
        $this->assertStringContainsString('Created the index entities.handle.', $stdout);
        $this->assertMatchesRegularExpression('#^entities: \d+ of 1202 copied$#m', $stdout);
        $this->assertStringContainsString("entities: 1202 of 1202 copied\n", $stdout);
        $this->assertStringContainsString("Ran the migration 20261115000001.\n", $stdout);
        $this->assertStringContainsString('The 1.x data is copied.', $stdout);
        $tables = $this->tables();
        $this->assertContains('legacy_entities', $tables);
        $this->assertNotContains('entities_metadata', $tables);
        $this->assertSame(1202, (int) $this->db()->fetchOne('select count(*) from idno_entities'));
        $this->assertTrue($this->copier()->progress()->complete);
        $this->assertSame($site['body'], json_decode((string) $this->db()->fetchOne('select data from idno_entities where id = ?', [$site['post']]), true)['body'] ?? null);

        [$exit, $stdout, $stderr] = $this->console('idno:upgrade');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame("Nothing to do: the schema is up to date.\n", $stdout);
    }

    /**
     * A plugin's declarations are reconciled after the copy, in the same run: the stored settings
     * (copied from 1.x) then say which plugins are enabled, and the plugin's indexes are built on the
     * copied rows. ActivityPub (bundled) declares `actor_uri` and `user_uuid` on `entities`.
     */
    public function testTheConsoleCreatesEnabledPluginsIndexesAfterTheCopy(): void
    {
        $this->oneXSite(30);

        [$exit, $stdout, $stderr] = $this->console('idno:upgrade');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $copied = strpos($stdout, 'The 1.x data is copied.');
        $this->assertNotFalse($copied, $stdout);
        foreach (['Created the index entities.actor_uri.', 'Created the index entities.user_uuid.'] as $line) {
            $at = strpos($stdout, $line);
            $this->assertNotFalse($at, $line . "\n" . $stdout);
            $this->assertGreaterThan($copied, $at, $line . ' comes after the copy');
        }
        $this->assertContains('actor_uri', (new SchemaCatalog($this->db()))->indexedFields('entities'));
    }

    /**
     * A plugin enabled ONLY by the 1.x site's stored configuration (copied by the upgrade) gets its
     * indexes in the same run, after the copy: the settings are empty before the copy, so the
     * plugin list is read after it. The subprocess has no always-loaded plugins (the environment's
     * `alwaysplugins` is not a list, over the rig's list), so StaticPages (`category` on `entities`) can
     * only come from the stored list.
     */
    public function testAPluginEnabledOnlyByTheStoredConfigurationIsReconciledAfterTheCopy(): void
    {
        $this->oneXSite(30, ['Status', 'Text', 'StaticPages']);
        // A string over the rig's list: not a list of plugin names, so none is always loaded (an
        // empty value would not reach the subprocess: proc_open drops it).
        $this->extraEnvironment = ['IDNO_ALWAYSPLUGINS' => 'none'];
        $this->assertSame('none', $this->environment()['IDNO_ALWAYSPLUGINS'], 'no plugin is always loaded');
        $this->assertNotContains('category', (new SchemaCatalog($this->db()))->indexedFields('entities'));

        [$exit, $stdout, $stderr] = $this->console('idno:upgrade');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $copied = strpos($stdout, 'The 1.x data is copied.');
        $this->assertNotFalse($copied, $stdout);
        $created = strpos($stdout, 'Created the index entities.category.');
        $this->assertNotFalse($created, $stdout);
        $this->assertGreaterThan($copied, $created, 'the plugin\'s index is made after the copy');
        $this->assertContains('category', (new SchemaCatalog($this->db()))->indexedFields('entities'));
        $this->assertNotContains('actor_uri', (new SchemaCatalog($this->db()))->indexedFields('entities'), $stdout);
    }

    /**
     * A plugin with a unique declaration, and 1.x rows that hold the same value of that field: every
     * row is copied (the plugin's index is built only after the copy, so it never turns a 1.x row into
     * a reject), the run warns that the plugin's index could not be built, and ends `current`.
     */
    public function testAPluginsUniqueIndexNeverRejectsACopiedRow(): void
    {
        $this->oneXSite(30);
        foreach (['dup-a', 'dup-b'] as $id) {
            $this->fixture?->insert('entities', ['_id' => $id, 'title' => $id, 'upgrade_clash_field' => 'same']);
        }
        $lines = [];

        $result = $this->runner(['UniqueClash'], dirname(__DIR__) . '/Unit/Upgrade/Fixtures/Plugins')->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertSame('current', $result['status'], (string) $result['message']);
        $this->assertSame(0, (int) $this->db()->fetchOne('select count(*) from idno_upgrade_rejects'), 'no row was rejected');
        $this->assertSame(2, (int) $this->db()->fetchOne("select count(*) from idno_entities where id in ('dup-a', 'dup-b')"));
        $warnings = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'Warning: ')));
        $this->assertCount(1, $warnings, implode("\n", $lines));
        $this->assertStringContainsString('UniqueClash', $warnings[0]);
        // On MySQL the generated column may be there (its index failed): no index was made.
        $this->assertSame(0, (int) $this->db()->fetchOne("select count(*) from information_schema.statistics where table_schema = database() and table_name = 'idno_entities' and index_name = 'ix_upgrade_clash_field'"));
    }

    public function testTheConsoleSaysWhenTheGateIsClosed(): void
    {
        $this->oneXSite(30);

        [$exit, $stdout, $stderr] = $this->console('list');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame(SchemaGate::CLOSED_MESSAGE . "\n", $stderr);
        $this->assertStringContainsString('idno:upgrade', $stdout);
        $this->assertNotContains('idno_entities', $this->tables(), 'nothing was created');

        [$exit, $stdout] = $this->console('idno:upgrade', '--status');
        $this->assertSame(0, $exit);
        $this->assertStringStartsWith("Schema status: legacy\nPending migration: ", $stdout);
    }

    // ------------------------------------------------------------------ the database

    /**
     * A 1.x database with a realistic site at the test server's URL and $many more small records.
     *
     * @param list<string> $plugins the plugins its stored configuration enables
     *
     * @return array{user: string, handle: string, post: string, body: string}
     */
    private function oneXSite(int $many, array $plugins = ['Status', 'Text']): array
    {
        $this->fixture = LegacyFixture::create('e2e');
        $this->connection = $this->fixture->connection;
        $this->dbname = (string) $this->connection->getDatabase();
        $site = $this->fixture->insertSite($this->url, plugins: $plugins);
        $this->fixture->insertMany('entities', $many);

        return $site;
    }

    /**
     * An empty database: a private one on the rig's MySQL or MariaDB server, or an SQLite file of
     * this test's own on an SQLite rig.
     */
    private function emptyDatabase(): void
    {
        $family = ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all());
        if ($family === 'mysql') {
            $this->connection = PrivateDatabase::mysql('e2e', migrate: false);
            $this->dbname = (string) $this->connection->getDatabase();
        } elseif ($family === 'sqlite') {
            $this->dbname = $this->directory . '/site.sqlite';
            $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbname], (new Configuration())->setMiddlewares([new SqliteConnectionSetup()]));
        } else {
            $this->markTestSkipped('The site\'s database is not SQL: there is no schema to set up.');
        }
    }

    private function db(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }

    /**
     * Another connection to the private database (another process, as far as the lock goes).
     */
    private function connect(): Connection
    {
        return DriverManager::getConnection($this->db()->getParams());
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return (new SchemaCatalog($this->db()))->tables();
    }

    /**
     * Every table of the private database with its row count (and CHECKSUM TABLE on MySQL), to tell
     * that nothing was written.
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $mysql = ($this->db()->getParams()['driver'] ?? '') !== 'pdo_sqlite';
        $snapshot = [];
        foreach ($this->tables() as $table) {
            $quoted = $this->db()->quoteSingleIdentifier($table);
            $snapshot[$table] = $this->db()->fetchOne('select count(*) from ' . $quoted) . ($mysql ? ' ' . ($this->db()->fetchNumeric('checksum table ' . $quoted)[1] ?? '') : '');
        }

        return $snapshot;
    }

    /**
     * CHECKSUM TABLE of every 1.x table.
     *
     * @return array<string, string>
     */
    private function checksums(bool $renamed = false): array
    {
        $sums = [];
        foreach (LegacyDataCopier::LEGACY_TABLES as $table) {
            $name = ($renamed ? 'legacy_' : '') . $table;
            $row = $this->db()->fetchNumeric('checksum table ' . $this->db()->quoteSingleIdentifier($name));
            $sums[$name] = (string) ($row[1] ?? '');
        }

        return $sums;
    }

    private function copier(): LegacyDataCopier
    {
        return new LegacyDataCopier($this->db(), new MySqlDialect(), new AttributeTypeMap(), new UpgradeLock($this->db()));
    }

    /**
     * The runner the site builds, on the private database, in this process.
     */
    /**
     * @param list<string>|null $plugins       the enabled plugins (alwaysplugins), instead of the rig's configuration
     * @param string|null       $pluginsRoot   the plugins directory, instead of IdnoPlugins/
     */
    private function runner(?array $plugins = null, ?string $pluginsRoot = null, float $lockWait = 60.0): UpgradeRunner
    {
        $connection = $this->db();
        $catalog = new SchemaCatalog($connection);
        $migrator = new SchemaMigrator($connection);
        $lock = new UpgradeLock($connection);
        $copier = new LegacyDataCopier($connection, new MySqlDialect(), new AttributeTypeMap(), $lock);
        $state = new SchemaState(
            new BootConfig(['database' => 'mysql'], false),
            static fn (): Connection => $connection,
            static fn (): SchemaCatalog => $catalog,
            static fn (): SchemaMigrator => $migrator,
            static fn (): LegacyDataCopier => $copier,
        );

        // The enabled plugins as the site would see them: the rig's boot configuration and the
        // private database's stored list.
        $enabled = new \Idno\Upgrade\EnabledPlugins($plugins === null ? Idno::container()->get(BootConfig::class) : new BootConfig(['alwaysplugins' => $plugins], false), $connection);

        return new UpgradeRunner($state, $migrator, new IndexedFields($connection, new MySqlDialect(), $catalog), $catalog, $copier, $lock, $enabled, new NullLogger(), dirname(__DIR__, 2), $lockWait, null, $pluginsRoot);
    }

    // ------------------------------------------------------------------ the server and the console

    /**
     * POSTs the step URL until the status is `current` (failing after MAX_STEPS steps, or on any
     * other status), checking that the copied counts never go down.
     *
     * @return list<string> the statuses, in order
     */
    private function stepUntilCurrent(): array
    {
        $statuses = [];
        $this->copiedCounts = [];
        $previous = -1;
        for ($i = 0; $i < self::MAX_STEPS; ++$i) {
            $answer = $this->request('POST', '/_upgrade/step', ['Accept: application/json']);
            $data = json_decode($answer['body'], true);
            $this->assertIsArray($data, $answer['body'] . $this->log());
            $this->assertStringContainsString('no-store', $answer['headers']['cache-control'] ?? '');
            $statuses[] = (string) $data['status'];
            if ($data['status'] === 'current') {
                return $statuses;
            }
            $this->assertSame('working', $data['status'], $answer['body']);
            $copied = (int) ($data['collections']['entities']['copied'] ?? 0);
            $this->copiedCounts[] = $copied;
            $this->assertGreaterThanOrEqual($previous, $copied, 'the copied count never goes down');
            $previous = $copied;
        }
        $this->fail('The upgrade did not finish in ' . self::MAX_STEPS . ' steps: ' . implode(', ', $statuses));
    }

    private function startServer(): void
    {
        $root = dirname(__DIR__, 2);
        $router = $this->directory . '/router.php';
        // The built-in server does not put the environment into $_SERVER, where the configuration
        // is read from: this router passes on the test's settings, then runs the rig's.
        $script = '<?php' . "\n"
            . "if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }\n"
            . "foreach (['IDNO_DBNAME', 'IDNO_URL', 'IDNO_PROXY_STRING', 'IDNO_DATABASE', 'IDNO_DBHOST', 'IDNO_DBPORT'] as \$name) { if (getenv(\$name) !== false) { \$_SERVER[\$name] = getenv(\$name); } }\n"
            . 'return require ' . json_encode($root . '/Tests/rig/router.php', JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ";\n";
        file_put_contents($router, $script);
        $this->serverLog = $this->directory . '/server.log';
        $port = (string) parse_url($this->url, PHP_URL_PORT);
        $server = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=22527', '-S', '127.0.0.1:' . $port, $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->serverLog, 'a'], 2 => ['file', $this->serverLog, 'a']],
            $pipes,
            $root,
            $this->environment() + ['PHP_CLI_SERVER_WORKERS' => '2'],
        );
        $this->assertIsResource($server);
        $this->server = $server;
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        $this->fail('The web server did not start.' . $this->log());
    }

    private function stopServer(): void
    {
        if ($this->server === null) {
            return;
        }
        $pid = (int) proc_get_status($this->server)['pid'];
        // The workers PHP_CLI_SERVER_WORKERS forks are the server's children.
        exec('pkill -TERM -P ' . $pid . ' 2>/dev/null');
        proc_terminate($this->server);
        $deadline = microtime(true) + 5;
        while (proc_get_status($this->server)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if (proc_get_status($this->server)['running']) {
            exec('pkill -KILL -P ' . $pid . ' 2>/dev/null');
            proc_terminate($this->server, 9);
        }
        proc_close($this->server);
        $this->server = null;
    }

    /**
     * The environment of the subprocesses: the rig's kernel settings, the private database, the
     * test server's URL, a closed proxy for any outbound request, and PATH, with the test's own
     * hooks over them ($extraEnvironment). Nothing else is inherited.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        return $this->extraEnvironment + [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '1',
            'IDNO_DBNAME' => $this->dbname,
            'IDNO_URL' => $this->url,
            'IDNO_PROXY_STRING' => '127.0.0.1:9',
            'PATH' => (string) getenv('PATH'),
        ];
    }

    /**
     * @param list<string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $handle = $this->curl($method, $path, $headers, $body);
        $raw = (string) curl_exec($handle);

        return $this->answer($handle, $raw);
    }

    /**
     * $count identical requests sent at the same time.
     *
     * @return list<array{status: int, headers: array<string, string>, body: string}>
     */
    private function parallel(int $count, string $method, string $path): array
    {
        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < $count; ++$i) {
            $handles[] = $handle = $this->curl($method, $path, ['Accept: application/json'], null);
            curl_multi_add_handle($multi, $handle);
        }
        do {
            $state = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $state === CURLM_OK);
        $answers = [];
        foreach ($handles as $handle) {
            $answers[] = $this->answer($handle, (string) curl_multi_getcontent($handle));
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);

        return $answers;
    }

    /**
     * @param list<string> $headers
     */
    private function curl(string $method, string $path, array $headers, ?string $body): \CurlHandle
    {
        $handle = curl_init(rtrim($this->url, '/') . $path);
        $this->assertInstanceOf(\CurlHandle::class, $handle);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        return $handle;
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function answer(\CurlHandle $handle, string $raw): array
    {
        $size = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headers = [];
        foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => (string) substr($raw, $size)];
    }

    /**
     * Logs in through the login form over HTTP and returns the session cookie (`name=value`).
     */
    private function logIn(string $handle, string $password): string
    {
        $form = $this->request('GET', '/session/login', ['Accept: text/html']);
        $this->assertSame(200, $form['status'], substr($form['body'], 0, 2000) . $this->log());
        $cookie = $this->sessionCookie($form);
        $this->assertNotNull($cookie, 'the login form starts a session');
        $login = $this->request(
            'POST',
            '/session/login',
            ['Accept: text/html', 'Cookie: ' . $cookie, 'Content-Type: application/x-www-form-urlencoded'],
            http_build_query($this->formToken($form['body'], 'session/login') + ['email' => $handle, 'password' => $password]),
        );
        $this->assertSame(302, $login['status'], substr($login['body'], 0, 2000) . $this->log());

        return $this->sessionCookie($login) ?? $cookie;
    }

    /**
     * @param array{headers: array<string, string>} $answer
     */
    private function sessionCookie(array $answer): ?string
    {
        // answer() keeps the last header of each name; the session cookie is the last one set.
        $header = $answer['headers']['set-cookie'] ?? '';

        return preg_match('/^([A-Za-z0-9_]+)=([^;\s]+)/', $header, $m) === 1 && $m[2] !== 'deleted' ? $m[1] . '=' . $m[2] : null;
    }

    /**
     * The CSRF token fields of the form in $html whose action ends with $action.
     *
     * @return array<string, string>
     */
    private function formToken(string $html, string $action): array
    {
        foreach (preg_split('/<form\b/i', $html) ?: [] as $form) {
            if (preg_match('/^[^>]*action="[^"]*' . preg_quote($action, '/') . '"/', $form) !== 1) {
                continue;
            }
            $fields = [];
            foreach (['__bTs', '__bTk', '__bTa'] as $field) {
                if (preg_match('/name="' . $field . '" value="([^"]*)"/', $form, $m) === 1) {
                    $fields[$field] = html_entity_decode($m[1], ENT_QUOTES);
                }
            }
            if (count($fields) === 3) {
                return $fields;
            }
        }
        $this->fail('No signed form posting to ' . $action . ' in the page.');
    }

    /**
     * Runs bin/console with the arguments on the private database.
     *
     * @return array{0: int, 1: string, 2: string} the exit code, stdout and stderr
     */
    private function console(string ...$arguments): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=22527', '-d', 'display_errors=stderr', '-d', 'log_errors=0', $root . '/bin/console', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $this->environment(),
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * The end of the server's log, for failure messages.
     */
    private function log(): string
    {
        $log = is_file($this->serverLog) ? (string) file_get_contents($this->serverLog) : '';

        return "\nserver log:\n" . substr($log, -4000);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        self::assertNotSame(8089, $port);

        return $port;
    }
}
