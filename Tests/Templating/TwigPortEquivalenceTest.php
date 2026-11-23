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
        ['visitor', 'robots.txt'],
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
    public const DRAWS = [
        ['default', 'js/idno', []],
        ['default', 'shell/simple/messages', ['messages' => [['message' => 'Saved. <a href="/x">See it</a>', 'message_type' => 'alert-success'], ['message' => 'Plain text', 'message_type' => 'alert-danger']]]],
        ['default', 'shell/simple/messages', ['messages' => []]],
        ['default', 'shell/pagination', ['count' => 'abc', 'offset' => '3', 'items_per_page' => '12abc']],
        ['default', 'shell/pagination', ['count' => '45xyz', 'offset' => 'abc', 'items_per_page' => 'abc']],
        ['default', 'shell/pagination', ['count' => 45, 'offset' => 20, 'items_per_page' => 10, 'control-id' => 'c', 'source-url' => '/s?a=1&b=2']],
        ['default', 'shell/simple', ['title' => 'Welcome', 'description' => 'A <b>fresh</b> site', 'body' => '<p>Hello</p>']],
        ['default', 'shell/simple', ['title' => '', 'description' => 'Some words that make a long description of more than ten words in all', 'body' => '', 'bootstrap' => true]],
    ];

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
        /** @var array<string, array{reason: string, test: string, differences: list<string>}> $exceptions */
        $exceptions = require __DIR__ . '/fixtures/port-exceptions.php';
        $lines = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($this->report))) as $line) {
            $lines[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }

        $originals = BundledTemplates::files($root, '.tpl.php');
        $ported = [];
        foreach (BundledTemplates::twigTemplates($root) as $logical => $path) {
            if (isset($originals[$logical])) {
                $ported[] = substr($path, strlen($root) + 1);
            }
        }

        $result = PortReportChecker::check($lines, $exceptions, $ported);
        foreach ($result['notices'] as $notice) {
            fwrite(STDERR, "\nNotice: " . $notice . "\n");
        }
        $this->assertSame([], $result['failures'], implode("\n", $result['failures']));
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
        $this->visitWithAMessage();
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
     * A visitor's failed login sets a message in the session, and the next page draws it: the pages' message area is compared
     * with something in it.
     */
    private function visitWithAMessage(): void
    {
        $form = $this->site()->request('GET', 'session/login');
        $cookie = Browser::cookie($form);
        $post = $this->site()->request('POST', 'session/login', ['Cookie' => $cookie], Browser::token($form->body, 'session/login') + ['email' => 'nobody', 'password' => 'not the password']);
        $cookie = Browser::cookieOr($post, $cookie) ?? $cookie;
        $page = $this->site()->request('GET', 'session/login', ['Cookie' => $cookie]);
        $this->assertSame(200, $page->status);
        $this->assertMatchesRegularExpression('/id="page-messages">\s*<\w/', $page->body, 'the page after a failed login draws a message');
    }

    /**
     * Draws, in the site, the mails (every template of `email` and `email-text` with representative variables) and the
     * templates in DRAWS that no page reaches.
     */
    private function drawCases(): void
    {
        $this->assertNotEmpty($this->drawMails());
        $this->drawIdentityLinks();
        $this->drawIconLeakCases();
        if (self::DRAWS === []) {
            return;
        }
        $code = <<<'PHP'
        // A page of the site is being served: the templates read its address and the page itself
        $uri = '/begin/?a=1&b=2';
        $_SERVER['REQUEST_URI'] = $uri;
        \Idno\Core\Idno::site()->setCurrentPage(new \Idno\Pages\Onboarding\Begin());
        \Idno\Core\Idno::container()->get('request_stack')->push(\Symfony\Component\HttpFoundation\Request::create($uri, 'GET', [], [], [], ['REQUEST_URI' => $uri]));
        $drawn = [];
        foreach (json_decode(%DRAWS%, true) as [$type, $name, $vars]) {
            \Tests\Fixtures\TwigPortCheck::forget();
            $template = clone \Idno\Core\Idno::site()->template();
            $template->setTemplateType($type);
            $drawn[] = (string) $template->__($vars)->draw($name);
        }

        return $drawn;
        PHP;
        $this->runInSite(str_replace('%DRAWS%', $this->literal(json_encode(self::DRAWS, JSON_THROW_ON_ERROR)), $code));
    }

    /**
     * A member's profile addresses, as the shell links them for rel=me: a plain address, a Twitter handle, an address that is not
     * http(s), and an empty one. The profile is set in memory only.
     */
    private function drawIdentityLinks(): void
    {
        $code = <<<'PHP'
        $uri = '/profile/ada';
        $_SERVER['REQUEST_URI'] = $uri;
        \Idno\Core\Idno::site()->setCurrentPage(new \Idno\Pages\Onboarding\Begin());
        \Idno\Core\Idno::container()->get('request_stack')->push(\Symfony\Component\HttpFoundation\Request::create($uri, 'GET', [], [], [], ['REQUEST_URI' => $uri]));
        $user = \Idno\Entities\User::getByHandle('ada');
        $user->profile = ['url' => ['https://example.org/ada', '@ada_l', 'mailto:ada@example.com', 'SMS:+15555550100', 'tel:+15555550100', 'javascript:alert(1)', 'data:text/html,x', 'mailto:a b@c', '']];
        \Tests\Fixtures\TwigPortCheck::forget();
        $template = clone \Idno\Core\Idno::site()->template();

        return (string) $template->__(['user' => $user])->draw('shell/identities');
        PHP;
        $drawn = (string) $this->runInSite($code);
        $this->assertStringContainsString('https://twitter.com/ada_l', $drawn);
    }

    /**
     * What the shell's partials after the icons see as `user`: whatever the last icon lookup left on the site's template object. Drawn
     * on the site's own template object (as a page is), with a page owner and an object whose owner is another user, neither with a
     * picture; with a picture (no default avatar is drawn: nothing is left); a `user` that is not a user.
     */
    private function drawIconLeakCases(): void
    {
        $code = <<<'PHP'
        $uri = '/view/x';
        $_SERVER['REQUEST_URI'] = $uri;
        \Idno\Core\Idno::container()->get('request_stack')->push(new \Symfony\Component\HttpFoundation\Request([], [], [], [], [], ['REQUEST_URI' => $uri]));
        $ada = \Idno\Entities\User::getByHandle('ada');
        $bob = \Idno\Entities\User::getByHandle('bob');
        $status = null;
        foreach (\IdnoPlugins\Status\Status::get([], [], 50) as $candidate) {
            if ($candidate->getOwnerID() === $ada->getUUID() && $status === null) {
                $status = $candidate;
            }
        }
        $live = \Idno\Core\Idno::site()->template();
        $out = [];
        foreach ([
            'owners without pictures' => [[], [], $bob, null],
            'the object\'s owner has a picture' => [['ada' => 'https://example.org/ada.png'], [], $bob, null],
            'both have pictures' => [['ada' => 'https://example.org/ada.png', 'bob' => 'https://example.org/bob.png'], [], $bob, $ada],
        ] as $label => [$images, $unused, $owner, $user]) {
            \Tests\Fixtures\TwigPortCheck::forget();
            $ada->image = $images['ada'] ?? null;
            $status->getOwner()->image = $images['ada'] ?? null;
            $bob->image = $images['bob'] ?? null;
            $page = new \Idno\Pages\Onboarding\Begin();
            if ($owner) {
                $page->setOwner($owner);
            }
            $property = new \ReflectionProperty(\Idno\Common\Page::class, 'isPermalinkPage');
            $property->setValue($page, true);
            \Idno\Core\Idno::site()->setCurrentPage($page);
            $live->vars = [];
            $vars = ['title' => 'T', 'description' => 'D', 'body' => '<p>b</p>', 'object' => $status];
            if ($user !== null) {
                $vars['user'] = $user;
            }
            $out[$label] = (string) $live->__($vars)->draw('shell');
        }

        \Tests\Fixtures\TwigPortCheck::forget();
        $probe = clone $live;
        $probe->vars = [];
        $out['a user that is not a user'] = (string) $probe->__(['user' => 'not a user', 'object' => $status])->draw('shell/activitypub');

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertIsArray($out);
        $this->assertCount(4, $out);
        $this->assertStringContainsString('<script type="application/ld+json">', (string) $out['owners without pictures']);
        // The page's owner is bob and the object's is ada: the last icon lookup was for the object, so the ActivityPub link is ada's
        $this->assertStringContainsString('/actor/ada"', (string) $out['owners without pictures']);
        $this->assertStringContainsString('/view/', (string) $out['a user that is not a user'], 'a user that is not a user is not one: the object\'s own address is linked');
        $this->assertStringNotContainsString('/actor/', (string) $out['a user that is not a user']);
    }
}
