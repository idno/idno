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
        $this->drawEntityCases();
        $this->drawAnnotationCases();
        $this->drawSyndicationCases();
        $this->drawProfileAndFollowingCases();
        $this->drawEmbedAndFileCases();
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

    /**
     * The php that starts a draw in the site, as a page of it is being served: the current page and request, and `$draw(name, vars, type)`,
     * which draws on a copy of the site's template with the port check's memory of the request cleared.
     */
    private function siteDrawPrelude(string $path = '/status/x'): string
    {
        return <<<PHP
        ini_set('memory_limit', '1G');
        \$uri = '{$path}';
        \$_SERVER['REQUEST_URI'] = \$uri;
        \\Idno\\Core\\Idno::container()->get('request_stack')->push(\\Symfony\\Component\\HttpFoundation\\Request::create(\$uri, 'GET', [], [], [], ['REQUEST_URI' => \$uri]));
        \\Idno\\Core\\Idno::site()->setCurrentPage(new \\Idno\\Pages\\Onboarding\\Begin());
        \$site = \\Idno\\Core\\Idno::site();
        \$ada = \\Idno\\Entities\\User::getByHandle('ada');
        \$bob = \\Idno\\Entities\\User::getByHandle('bob');
        \$status = null;
        foreach (\\IdnoPlugins\\Status\\Status::get([], [], 50) as \$candidate) {
            if (\$status === null && \$candidate->getOwnerID() === \$ada->getUUID() && \$candidate->countAnnotations('reply') > 0) {
                \$status = \$candidate;
            }
        }
        \$draw = static function (string \$name, array \$vars = [], string \$type = 'default') use (\$site): string {
            \\Tests\\Fixtures\\TwigPortCheck::forget();
            \$template = clone \$site->template();
            \$template->setTemplateType(\$type);

            return (string) \$template->__(\$vars)->draw(\$name);
        };
        \$out = [];

        PHP;
    }

    /**
     * Entities in feeds and the drafts page, which no visit of the golden pages reaches with every branch: a feed item, a followed
     * feed and its subscription, a stream, the drafts page with and without drafts and with its paging.
     */
    private function drawEntityCases(): void
    {
        $code = $this->siteDrawPrelude() . <<<'PHP'
        $feed = new \Idno\Entities\Reader\Feed();
        $feed->setFeedURL('https://feed.example/rss');
        $feed->setURL('https://feed.example/');
        $feed->title = 'A followed site';
        // The template of a feed item draws the item's own `draw()`, which draws the template of a feed item: a class whose `draw()` ends that
        $feedItem = new class extends \Idno\Entities\Reader\FeedItem {
            public function draw($feed_view = false, $suffix = "")
            {
                return '<p class="drawn-item">' . htmlspecialchars((string) $this->getTitle()) . '</p>';
            }
        };
        $item = clone $feedItem;
        $item->setFeedURL('https://feed.example/rss');
        $item->owner = $ada->getUUID();
        $item->created = 1773000000;
        $item->setBody('<p>An item of the feed.</p>');
        $item->setTitle('The item');
        // A subscription finds its feed in the database; these hold the feed they are given (or none)
        $held = new class extends \Idno\Entities\Reader\Subscription {
            public $heldFeed = false;

            public function getFeedObject()
            {
                return $this->heldFeed;
            }
        };
        $subscription = clone $held;
        $subscription->heldFeed = $feed;
        $lonely = clone $held;
        $out['feed item'] = $draw('entity/FeedItem', ['object' => $item]);
        $out['feed item without owner'] = $draw('entity/FeedItem', ['object' => clone $feedItem]);
        $out['stream'] = $draw('stream/home', ['items' => [$item, $item]]);
        $out['empty stream'] = $draw('stream/home', ['items' => []]);
        $out['subscription'] = $draw('entity/Subscription', ['object' => $subscription]);
        $out['subscription without a feed'] = $draw('entity/Subscription', ['object' => $lonely]);
        $out['following'] = $draw('following/home', ['subscriptions' => [$subscription, $lonely]]);
        $out['following nobody'] = $draw('following/home', ['subscriptions' => []]);
        $out['following confirm'] = $draw('following/confirm', ['feed' => $feed, 'items' => [$item]]);
        $out['following confirm, nothing yet'] = $draw('following/confirm', ['feed' => $feed, 'items' => []]);
        foreach ([
            'a card' => ['url' => 'https://a.example/post', 'title' => 'The title', 'description' => 'The description', 'image' => 'https://a.example/i.png', 'domain' => 'a.example', 'site_name' => 'A'],
            'a card with no image or domain' => ['url' => 'https://a.example/post', 'title' => 'Only a title'],
            'a card with nothing to show' => ['url' => 'https://a.example/post'],
            'a card with a script address' => ['url' => 'javascript:alert(1)', 'title' => 'x'],
            'a card with a quote' => ['url' => 'https://a.example/p?a=1&b=2', 'title' => 'Cafe "quoted"', 'description' => "It's <b>bold</b>", 'image' => 'javascript:x'],
            'no preview' => [],
        ] as $label => $preview) {
            $out['link preview, ' . $label] = $draw('entity/LinkPreviewCard', ['preview' => $preview]);
        }
        foreach ([[], [$status, $status->getOwner()]] as $drafts) {
            $out['drafts ' . count($drafts)] = $draw('drafts', ['drafts' => $drafts, 'total' => 25, 'count' => 10, 'offset' => 10]);
        }
        $draft = new \IdnoPlugins\Status\Status();
        $draft->body = 'A draft';
        $draft->updated = 1773000000;
        $draft->setOwner($ada);
        foreach ([[0, 25], [10, 25], [20, 25], [0, 5]] as [$offset, $total]) {
            $out['drafts paging ' . $offset . ' of ' . $total] = $draw('drafts', ['drafts' => [$draft], 'total' => $total, 'count' => 10, 'offset' => $offset]);
        }
        $out['the shell of a post with an owner and none'] = $draw('entity/shell', ['object' => $status]) . $draw('entity/shell', ['object' => new \IdnoPlugins\Status\Status()]);
        $reply = new \IdnoPlugins\Status\Status();
        $reply->setOwner($ada);
        $reply->body = 'In reply';
        $reply->created = 1773000000;
        foreach ([
            'one address' => 'https://elsewhere.example/post',
            'two addresses' => ['https://elsewhere.example/a', 'https://other.example/b'],
            'three addresses' => ['https://elsewhere.example/a', 'https://other.example/b', 'https://third.example/c'],
            'a local post' => $status->getURL(),
            'a script address' => 'javascript:alert(1)',
            'an empty list' => [],
        ] as $label => $inreplyto) {
            $reply->inreplyto = $inreplyto;
            $out['in reply to ' . $label] = $draw('entity/shell', ['object' => $reply]);
        }
        $out['a body given to the shell'] = $draw('entity/shell', ['object' => $status, 'body' => '<p>The body</p>']);
        $out['the feed of nothing and of entities'] = $draw('entity/feed', ['items' => []]) . $draw('entity/feed', ['items' => [$status, false, 'not an entity'], 'count' => 3]);
        $out['the edit wrapper'] = $draw('entity/editwrapper', ['body' => '<form>edit</form>']);

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertIsArray($out);
        $this->assertStringContainsString('The item', (string) $out['feed item']);
        $this->assertStringContainsString('class="subscription"', str_replace('row subscription', 'subscription', (string) $out['subscription']));
        $this->assertStringContainsString('Cafe &quot;quoted&quot;', (string) $out['link preview, a card with a quote']);
    }

    /**
     * The annotation lists and their parts, with several annotations of one kind, equal times, local and remote addresses, hostile
     * values and every answer of an RSVP.
     */
    private function drawAnnotationCases(): void
    {
        $code = $this->siteDrawPrelude() . <<<'PHP'
        $base = $status->getURL() . '/annotations/';
        $make = static fn (string $name, int $time, array $extra = []): array => [
            'permalink' => 'https://example.org/' . $name,
            'owner_name' => ucfirst($name),
            'owner_url' => 'https://example.org/' . $name . '/me',
            'owner_image' => 'https://example.org/' . $name . '.png',
            'content' => 'Words by ' . $name . ' at https://example.org/words and <b>markup</b> & more',
            'time' => $time,
            'title' => '',
        ] + $extra;
        $list = [
            $base . 'aaa111' => $make('late', 1773000500),
            $base . 'bbb222' => $make('early', 1773000100, ['permalink' => $base . 'bbb222']),
            'https://example.org/c/' . md5('c') => $make('tied-a', 1773000300, ['title' => 'A <b>title</b>']),
            'https://example.org/d/' . md5('d') => $make('tied-b', 1773000300, ['owner_image' => '', 'permalink' => '']),
            'https://example.org/e/' . md5('e') => $make('hostile', 1773000400, ['owner_name' => '"><script>x()</script>', 'owner_url' => 'javascript:alert(1)', 'owner_image' => 'data:text/html,x', 'permalink' => 'javascript:alert(2)', 'content' => '<script>x()</script>1 < 2 and "quotes"']),
        ];
        foreach (['likes', 'shares', 'mentions', 'replies'] as $name) {
            $out[$name] = $draw('entity/annotations/' . $name, ['annotations' => $list, 'object' => $status]);
            $out[$name . ' of none'] = $draw('entity/annotations/' . $name, ['annotations' => [], 'object' => $status]);
        }
        $out['like'] = $draw('entity/annotations/like', ['annotations' => $list, 'object' => $status]);
        $out['reply'] = $draw('entity/annotations/reply', ['annotations' => $list, 'object' => $status]);
        $rsvps = [];
        foreach (['yes', 'maybe', 'no', ' Yes ', '', 'perhaps'] as $i => $answer) {
            $rsvps['https://example.org/r' . $i . '/' . md5((string) $i)] = $make('guest' . $i, 1773000000 + (7 - $i) * 10, ['rsvp' => $answer, 'content' => 'Guest ' . $i . ' says ' . ($answer ?: 'nothing')]);
        }
        $out['rsvps'] = $draw('entity/annotations/rsvps', ['annotations' => $rsvps, 'object' => $status]);
        $out['rsvps of one answer'] = $draw('entity/annotations/rsvps', ['annotations' => array_slice($rsvps, 0, 1), 'object' => $status]);
        $out['rsvps of none'] = $draw('entity/annotations/rsvps', ['annotations' => [], 'object' => $status]);
        foreach (['reply', 'like', 'mention', 'share', 'rsvp'] as $subtype) {
            $out['annotation page, ' . $subtype] = $draw('entity/annotations/shell', ['annotation' => $make('visitor', 1773000100), 'subtype' => $subtype, 'permalink' => $base . 'zzz999', 'object' => $status]);
        }
        $out['the image of an annotation'] = $draw('entity/annotations/image', ['annotation' => $make('pictured', 1773000100)]) . $draw('entity/annotations/image', ['annotation' => $make('hostile', 1773000100, ['owner_url' => 'javascript:x', 'owner_image' => 'javascript:y', 'owner_name' => '"><i>'])]);
        $site->config()->attachment_base_host = 'cdn.example';
        $out['the image of an annotation on an attachment host'] = $draw('entity/annotations/image', ['annotation' => $make('pictured', 1773000100)]);
        $site->config()->attachment_base_host = '';
        $out['the delete link'] = $draw('content/annotation/edit', ['annotation_permalink' => $base . 'aaa111', 'object' => $status]);

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertIsArray($out);
        // The listing sorts by time: early, the two that tie, hostile, then late.
        $this->assertLessThan(strpos((string) $out['likes'], 'Late'), strpos((string) $out['likes'], 'Early'));
        $this->assertStringNotContainsString('<script>', (string) $out['replies']);
    }

    /**
     * The syndication buttons (services with and without accounts, one the viewer does not have) and the links to where a post was
     * syndicated, with the answers a service may have given.
     */
    private function drawSyndicationCases(): void
    {
        $code = $this->siteDrawPrelude() . <<<'PHP'
        $syndication = $site->syndication();
        $syndication->registerService('zzaccounts', static fn () => true, ['note']);
        $syndication->registerServiceAccount('zzaccounts', 'ada_l', 'Ada Lovelace');
        $syndication->registerServiceAccount('zzaccounts', 'bob & co', '');
        $syndication->registerServiceAccount('zzaccounts', 'carol', 'Carol <b>C</b>');
        $syndication->registerService('zz plain', static fn () => true, ['note']);
        $syndication->registerService('zznot', static fn () => false, ['note']);
        $posse = ['zzaccounts' => [['account_id' => 'ada_l', 'url' => 'https://x.example/1', 'identifier' => 'ada_l']], 'zz plain' => 'https://x.example/plain'];
        foreach ([[], $posse, ['zzaccounts' => 'https://x.example/legacy']] as $i => $links) {
            \Tests\Fixtures\TwigPortCheck::forget();
            $template = clone $site->template();
            $out['syndication ' . $i] = (string) $template->drawSyndication('note', $links);
        }
        \Tests\Fixtures\TwigPortCheck::forget();
        $template = clone $site->template();
        $out['syndication of a type with none'] = (string) $template->drawSyndication('no-such-type', []);
        $out['the generic icon'] = $draw('content/syndication/icon/generic');
        $out['a button'] = $draw('content/syndication/button', ['service' => 'zz plain', 'disabled' => 'disabled', 'selected' => true]) . $draw('content/syndication/button', ['service' => 'twitter', 'disabled' => '', 'selected' => false]) . $draw('content/syndication/button', []);
        $out['an account'] = $draw('content/syndication/account', ['service' => 'zzaccounts', 'username' => 'bob & co', 'name' => '', 'disabled' => '', 'selected' => false]) . $draw('content/syndication/account', ['service' => 'zzaccounts', 'username' => 'ada_l', 'name' => 'Ada "L" Lovelace', 'disabled' => 'disabled', 'selected' => true]) . $draw('content/syndication/account', ['service' => 'x']);
        $object = $status;
        foreach ([
            'no links' => [],
            'one link' => ['zzaccounts' => [['url' => 'https://x.example/1', 'identifier' => 'ada_l', 'account_id' => 'ada_l']]],
            'a legacy string and a list' => ['zz plain' => 'https://x.example/plain', 'zzaccounts' => [['url' => 'https://x.example/2', 'identifier' => 'a'], ['url' => '', 'identifier' => ''], ['identifier' => 'no address']]],
            'hostile' => ['zzaccounts' => [['url' => 'javascript:alert(1)', 'identifier' => '<b>x</b> & "y"'], ['url' => 'https://x.example/?a=1&b="2"', 'identifier' => 'ok']]],
        ] as $label => $links) {
            $object->posse = $links;
            foreach ([true, false] as $permalink) {
                $page = new \Idno\Pages\Onboarding\Begin();
                $property = new \ReflectionProperty(\Idno\Common\Page::class, 'isPermalinkPage');
                $property->setValue($page, $permalink);
                $site->setCurrentPage($page);
                $out['links, ' . $label . ($permalink ? ' on a permalink' : '')] = $draw('content/syndication/links', ['object' => $object]);
            }
        }

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertIsArray($out);
        $this->assertStringContainsString('class="syndication"', (string) $out['syndication 0']);
        $this->assertSame('', trim((string) $out['syndication of a type with none']));
    }

    /**
     * Profiles: the addresses of a member as fields, as contact links and in the edit form; the access control; the 403 and 500 pages.
     */
    private function drawProfileAndFollowingCases(): void
    {
        $code = $this->siteDrawPrelude() . <<<'PHP'
        $profiles = [
            'none' => [],
            'a list of addresses' => ['url' => [
                'https://example.org/ada', '@ada_l', 'https://www.github.com/ada/', 'https://twitter.com/ada_l/', 'mailto:ada@example.com', 'SMS:+15555550100', 'tel:+15555550100', 'sip:ada@example.com',
                'facetime:ada@example.com', 'skype:ada', 'xmpp:ada@example.com', 'bitcoin:abc', 'ethereum:0x1', 'spotify:user:ada', 'ssb:abc', 'ssh://example.org', 'example.org/bare', 'https://medium.com/@ada', '', 'javascript:alert(1)',
                'https://example.org/?a=1&b=<i>2</i>',
            ]],
            'one string' => ['url' => 'https://example.org/one'],
            'a description' => ['description' => 'About <b>Ada</b>', 'url' => ['https://example.org/ada']],
        ];
        $user = \Idno\Entities\User::getByHandle('ada');
        foreach ($profiles as $label => $profile) {
            $user->profile = $profile;
            foreach (['entity/User/profile/fields', 'entity/User/edit'] as $name) {
                $out[$name . ', ' . $label] = $draw($name, ['user' => $user]);
            }
        }
        // The contact links of a profile (nothing draws them now): the schemes the template knows. An address of another scheme made the old template fail
        foreach (['none' => [], 'one string' => ['url' => 'mailto:ada@example.com'], 'known schemes' => ['url' => ['mailto:ada@example.com', 'tel:+15555550100', 'sms:+15555550100', 'facetime:ada@example.com', 'https://example.org/ada', '']]] as $label => $profile) {
            $user->profile = $profile;
            $out['entity/User/profile/contact, ' . $label] = $draw('entity/User/profile/contact', ['user' => $user]);
        }
        $user->profile = [];
        $out['the avatar'] = $draw('entity/User/icon', ['user' => $user]) . $draw('entity/User/icon', ['user' => $bob]);
        $out['a profile'] = $draw('entity/User', ['object' => $user]) . $draw('entity/User', ['user' => $bob]);
        $site->session()->logUserOn($user);
        $group = new \Idno\Entities\AccessGroup();
        $group->title = 'Friends & "family"';
        $group->owner = $user->getUUID();
        $group->access_group_type = 'FOLLOWING';
        $group->save(true);
        $other = new \Idno\Entities\AccessGroup();
        $other->title = 'Editors';
        $other->owner = $user->getUUID();
        $other->save(true);
        foreach ([[null, null, false], [null, 'SITE', true], [$status, null, false], [$status, 'PRIVATE', true], [$status, $user->getUUID(), false], [$status, $group->getUUID(), false], [$status, $other->getUUID(), true], [null, 'x" onclick="y', false]] as $i => [$object, $default, $privacy]) {
            $site->config()->show_privacy = $privacy;
            $vars = ['object' => $object];
            if ($default !== null) {
                $vars['default-access'] = $default;
            }
            $out['access ' . $i] = $draw('content/access', $vars);
        }
        $private = new \IdnoPlugins\Status\Status();
        $private->access = $user->getUUID();
        $out['access of a private post'] = $draw('content/access', ['object' => $private]);
        $site->config()->show_privacy = false;
        $out['the comment form'] = $draw('entity/annotations/comment/main', ['object' => $status]);
        $site->session()->logUserOff();
        $out['the comment form of nobody'] = $draw('entity/annotations/comment/main', ['object' => $status]);
        $site->config()->debug = false;
        foreach ([null, new \RuntimeException('Something <b>went</b> wrong'), new \TypeError('A technical message')] as $i => $exception) {
            foreach ([false, true] as $debug) {
                $site->config()->debug = $debug;
                $out['the error page ' . $i . ($debug ? ' in debug' : '')] = $draw('pages/500', $exception === null ? [] : ['exception' => $exception]);
            }
        }
        $site->config()->debug = false;
        foreach (['nobody' => false, 'a member' => true] as $label => $in) {
            if ($in) {
                $site->session()->logUserOn($user);
            }
            $out['the 403 page of ' . $label] = $draw('pages/403');
            $site->session()->logUserOff();
        }
        $out['the 404 page'] = $draw('pages/404') . $draw('pages/410');
        foreach (['a search' => ['q' => 'words'], 'no search' => []] as $label => $input) {
            $_GET = $input;
            $_REQUEST = $input;
            $out['nothing found, ' . $label] = $draw('pages/home/nocontent');
        }
        $_GET = [];
        $_REQUEST = [];
        foreach ([[0, [1]], [10, [1]], [0, []], [10, []]] as $i => [$offset, $types]) {
            $out['the home blurb ' . $i] = $draw('pages/home/blurb', ['offset' => $offset]) . $draw('pages/home', ['offset' => $offset, 'contentTypes' => $types, 'items' => []]);
        }

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertIsArray($out);
        $this->assertStringContainsString('class="u-email"', (string) $out['entity/User/profile/fields, a list of addresses']);
    }

    /**
     * What a post's text embeds, a share page and the file picker.
     */
    private function drawEmbedAndFileCases(): void
    {
        $code = $this->siteDrawPrelude() . <<<'PHP'
        $texts = [
            'nothing' => 'Just words',
            'an image' => 'See https://img.example/a.b.png and https://img.example/c.d.jpeg done',
            'a hostile image' => 'See https://img.example/x"><script>.y.gif and javascript:alert(1).a.png',
            'archive' => 'https://archive.org/embed/some-item_1',
            'bitchute' => 'https://www.bitchute.com/video/AbC-1_/',
            'youtube watch' => 'https://www.youtube.com/watch?v=abc-1_ and https://youtu.be/watch?v=def',
            'youtube channel' => 'https://www.youtube.com/channel/UC123',
            'youtube c' => 'https://www.youtube.com/c/Name',
            'youtube user' => 'https://www.youtube.com/user/Someone',
            'youtube plain' => 'https://youtu.be/xyz123',
            'vimeo' => 'https://vimeo.com/123456 and vimeo.com/789',
            'instagram' => 'https://www.instagram.com/p/AbC123_-/ and https://instagram.com/p/Zzz/',
            'everything' => 'https://img.example/a.b.png https://archive.org/embed/x https://vimeo.com/1 https://www.instagram.com/p/Q/ https://youtu.be/q',
        ];
        foreach ($texts as $label => $text) {
            $out['embed, ' . $label] = $draw('content/embed', ['value' => $text]);
            $post = new \IdnoPlugins\Status\Status();
            $post->body = $text;
            $out['embed of a post, ' . $label] = $draw('entity/content/embed', ['object' => $post]);
        }
        $out['a post with no body'] = $draw('entity/content/embed', ['object' => new \IdnoPlugins\Status\Status()]);
        $out['share'] = $draw('entity/share', ['content_type' => 'note']) . $draw('entity/share', []);
        $out['the bookmarklet'] = $draw('entity/bookmarklet');
        $out['the file picker'] = $draw('file/picker') . $draw('file/picker/image') . $draw('pages/webmention');
        $file = new \Idno\Entities\File();
        $file->file = ['_id' => 'abc123'];
        $out['done, with a file'] = $draw('file/picker/donejs', ['file' => $file]);
        $out['done, with none'] = $draw('file/picker/donejs', []);
        $out['the edit header'] = $draw('entity/edit/header');
        $out['the edit footer'] = $draw('entity/edit/footer');
        $out['spinners'] = $draw('entity/edit/spinner') . $draw('entity/edit/spinner', ['id' => 'mine', 'class' => 'big', 'start-hidden' => false]) . $draw('entity/edit/spinner', ['id' => 'shown', 'start-hidden' => 'no']);
        $out['the delete and edit menu'] = $draw('content/edit', ['object' => $status]);
        $out['the end of a post, in a feed'] = $draw('content/feed/end', ['object' => $status]) . $draw('content/feed/end', ['object' => new \IdnoPlugins\Status\Status()]);

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertIsArray($out);
        $this->assertStringContainsString('youtube-nocookie.com/embed/abc-1_', (string) $out['embed, youtube watch']);
    }
}
