<?php

declare(strict_types=1);

namespace Tests\Templating;

use Tests\Acceptance\Browser;
use Tests\Fixtures\BundledTemplates;

/**
 * Proves every Twig port of a core or bundled-plugin template against the `.tpl.php` original beside it. The site (the same
 * private site as the golden pages, with the same content) is visited as a visitor, as the administrator, as a member and as a
 * new user; each time a page draws a Twig file that has its original beside it, Tests\Fixtures\TwigPortCheck draws the original
 * too, with the same variables, in the same request (the templates read the session, the current page and the site, so the
 * variables are not replayed from a capture) and reports whether the normalised outputs are the same.
 *
 * The test fails for a difference that is not in fixtures/port-exceptions.php, for an exception that matches no difference or
 * whose test is missing, and for a Twig file with an original beside it that no visit compared (add a page to EXTRA_PAGES, or a
 * draw to drawCases(), that reaches it).
 */
final class TwigPortEquivalenceTest extends RenderedSiteTestCase
{
    /**
     * Pages beyond the golden ones, by who asks: `visitor`, `admin`, `member` (not an administrator) and `new` (a user who has
     * just registered, for the onboarding pages). Each must answer, not fail: a status below 500.
     *
     * @var list<array{string, string}>
     */
    public const EXTRA_PAGES = [
        ['visitor', 'account/password/reset/?email=ada%40example.com&code=00000000000000000000000000000000'],
        ['visitor', 'account/register/?email=new%40example.com&code=abc'],
        ['visitor', 'pages/about-this-site'],
        ['visitor', 'tag/nothing-is-tagged-with-this'],
        ['visitor', '?q=zzzznothingmatches'],
        ['visitor', 'content/all/?offset=10'],
        ['member', 'admin/'],
        ['member', 'admin/users/'],
        ['member', 'account/settings/'],
        ['admin', 'admin/about/'],
        ['admin', 'admin/dependencies/'],
        ['admin', 'admin/diagnostics/'],
        ['admin', 'admin/email/'],
        ['admin', 'admin/export/'],
        ['admin', 'admin/homepage/'],
        ['admin', 'admin/import/'],
        ['admin', 'admin/legacytables/'],
        ['admin', 'admin/logs/'],
        ['admin', 'admin/plugins/'],
        ['admin', 'admin/statistics/'],
        ['admin', 'admin/themes/'],
        ['admin', 'admin/users/'],
        ['admin', 'admin/activitypub/'],
        ['admin', 'admin/staticpages/'],
        ['admin', 'admin/staticpages/categories/'],
        ['admin', 'admin/webhooks/'],
        ['admin', 'account/settings/'],
        ['admin', 'account/settings/tools/'],
        ['admin', 'account/settings/following/'],
        ['admin', 'account/settings/feedback/'],
        ['admin', 'account/settings/feedback/confirm/'],
        ['admin', 'account/export/'],
        ['admin', 'account/bridgy/'],
        ['admin', 'account/indiepub/'],
        ['admin', 'following/'],
        ['admin', 'following/add/'],
        ['admin', 'stream/'],
        ['admin', 'file/picker/'],
        ['admin', 'share/?share_url=https%3A%2F%2Fexample.org%2Fshared&share_title=Shared'],
        ['admin', 'search/users/?q=ada'],
        ['admin', 'drafts/'],
        ['admin', 'staticpages/edit/'],
        ['new', 'begin/'],
        ['new', 'begin/connect/'],
        ['new', 'begin/connect-forwarder/'],
        ['new', 'begin/profile/'],
        ['new', 'begin/publish/'],
    ];

    /**
     * Templates no page of this test reaches, drawn in the site by name: [type, name, variables]. A port that no page reaches
     * adds its case here.
     *
     * @var list<array{string, string, array<string, mixed>}>
     */
    public const DRAWS = [];

    protected bool $compareGoldens = false;

    private string $report = '';

    protected function setUp(): void
    {
        $this->report = sys_get_temp_dir() . '/idno-twig-port-' . bin2hex(random_bytes(6));
        touch($this->report);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->report !== '' && is_file($this->report)) {
            unlink($this->report);
        }
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_TWIG_PORT_REPORT' => $this->report];
    }

    public function testEveryPortedTemplateDrawsWhatItsOriginalDrew(): void
    {
        $paths = $this->seedSite();
        $admin = $this->loginAsAdmin();

        $this->visitorPages('twenty26', $paths);
        $this->loggedInPages('logged-in', $paths, $admin, true);
        $this->nonHtmlPages($paths, $admin);
        $this->extraPages($paths, $admin);
        $this->drawCases();
        $this->assertNoDifferences();

        $root = dirname(__DIR__, 2);
        /** @var array<string, array{reason: string, test: string}> $exceptions */
        $exceptions = require __DIR__ . '/fixtures/port-exceptions.php';
        $lines = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($this->report))) as $line) {
            $lines[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }

        // 1. Every difference is an exception.
        $unexpected = [];
        $compared = [];
        $differed = [];
        foreach ($lines as $line) {
            $compared[$line['file']] = true;
            if (!$line['same']) {
                $differed[$line['file']] = true;
                if (!isset($exceptions[$line['file']]) && count($unexpected) < 5) {
                    $unexpected[$line['file']] = $line['file'] . ":\n  original: " . json_encode($line['original'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n  port:     " . json_encode($line['port'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                }
            }
        }
        $this->assertSame([], array_values($unexpected), "A Twig port draws something other than its original (the first five), and is not in port-exceptions.php:\n" . implode("\n", $unexpected));

        // 2. Every exception differs, and its test exists.
        foreach ($exceptions as $file => $exception) {
            $this->assertNotSame('', trim($exception['reason']), $file . ': an exception needs its reason');
            $this->assertArrayHasKey($file, $compared, $file . ' is an exception, and no page or draw compared it');
            $this->assertArrayHasKey($file, $differed, $file . ' is a stale exception: it draws what its original drew');
            [$class, $method] = explode('::', $exception['test'] . '::') + [1 => ''];
            $this->assertTrue(class_exists($class) && method_exists($class, $method), $file . ': the test ' . $exception['test'] . ' does not exist');
        }

        // 3. Every Twig file with an original beside it was compared.
        $originals = BundledTemplates::files($root, '.tpl.php');
        $uncompared = [];
        foreach (BundledTemplates::twigTemplates($root) as $logical => $path) {
            if (isset($originals[$logical]) && !isset($compared[substr($path, strlen($root) + 1)])) {
                $uncompared[] = substr($path, strlen($root) + 1);
            }
        }
        $this->assertSame([], $uncompared, "These Twig files have an original beside them and no page or draw of this test compared them (add a page to EXTRA_PAGES, or a draw to DRAWS):\n" . implode("\n", $uncompared));
    }

    /**
     * Visits EXTRA_PAGES as who they say; every page must answer with a status below 500.
     *
     * @param array<string, string> $paths
     */
    private function extraPages(array $paths, string $admin): void
    {
        $cookies = ['visitor' => null, 'admin' => $admin];
        $cookies['member'] = (new Browser($this->site()))->login(self::MEMBER, self::PASSWORD);
        $cookies['new'] = $this->registerUser('carol');
        $failed = [];
        foreach (self::EXTRA_PAGES as [$who, $path]) {
            $response = $this->site()->request('GET', $path, $cookies[$who] === null ? [] : ['Cookie' => $cookies[$who]]);
            if ($response->status >= 500) {
                $failed[] = $who . ' ' . $path . ' answered ' . $response->status . ': ' . substr(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('#<(head|script|style)\b.*?</\1>#si', '', $response->body)))), 0, 300);
            }
        }
        $this->assertSame([], $failed, implode("\n", $failed) . $this->serverLog());
        $this->assertNotEmpty($paths);
    }

    /**
     * Draws, in the site, the mails (every template of `email` and `email-text` with representative variables) and the
     * templates in DRAWS that no page reaches.
     */
    private function drawCases(): void
    {
        $this->assertNotEmpty($this->drawMails());
        if (self::DRAWS === []) {
            return;
        }
        $code = <<<'PHP'
        $drawn = [];
        foreach (json_decode(%DRAWS%, true) as [$type, $name, $vars]) {
            $template = clone \Idno\Core\Idno::site()->template();
            $template->setTemplateType($type);
            $drawn[] = (string) $template->__($vars)->draw($name);
        }

        return $drawn;
        PHP;
        $this->runInSite(str_replace('%DRAWS%', $this->literal(json_encode(self::DRAWS, JSON_THROW_ON_ERROR)), $code));
    }
}
