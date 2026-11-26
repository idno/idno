<?php

declare(strict_types=1);

namespace Tests\Templating;

use Tests\Acceptance\Browser;
use Tests\Fixtures\BundledTemplates;

/**
 * Draws the branches no golden page reaches, through the real engines of a private site: hostile values, empty states, plugin
 * states, every mail, the forms and the admin pages with their odd inputs. The golden pages carry the proof of what a page looks
 * like; this test is a smoke test of what they do not draw. The site (the same private site as the golden pages, with the same
 * content) is visited as a visitor, as the administrator, as a member and as a new user, and the draw cases run in the site's
 * own process.
 *
 * What is asserted: every page answers below 500; every case draws without an error; every labelled case draws something (its
 * output is not empty, or only white space, unless the label is in EMPTY_OUTPUT with the reason that state draws nothing); within
 * the cases one draw method returns, no two states draw the same output (unless the pair is in SAME_OUTPUT with its reason), except
 * that a state drawn again in another context (`a reply: `, `embed of a post, `, ` on its page`, ` in a feed`) is only checked for
 * drawing something; the cases of DRAWS draw outputs that are pairwise different; an entry of EMPTY_OUTPUT or SAME_OUTPUT that no
 * case produces fails; and every Twig template of core and the bundled plugins must have been drawn at least once by the
 * pages and the cases together (the template trace of Tests\Fixtures\TemplateTrace records each draw): add a page to EXTRA_PAGES,
 * or a draw to one of the draw methods, for a template that no visit reaches.
 */
final class TemplateDrawCasesTest extends RenderedSiteTestCase
{
    /**
     * Labels of draw cases whose right output is empty, with why.
     *
     * @var array<string, string>
     */
    private const EMPTY_OUTPUT = [
        'feed item without owner' => 'a feed item is drawn only for an entity that has an owner',
        'subscription without a feed' => 'a subscription whose feed cannot be found draws nothing',
        'link preview, a card with nothing to show' => 'a preview that is only an address has no title, description or image: no card',
        'link preview, a card with a script address' => 'an address that is not http or https draws no card',
        'link preview, no preview' => 'no preview, no card',
        'likes of none' => 'no annotations, nothing to list',
        'shares of none' => 'no annotations, nothing to list',
        'mentions of none' => 'no annotations, nothing to list',
        'replies of none' => 'no annotations, nothing to list',
        'rsvps of none' => 'no annotations, nothing to list',
        'syndication of a type with none' => 'no service takes this content type, so there is no form',
        'links, no links on a permalink' => 'a post with no syndication links draws no "Also on" list',
        'links, no links' => 'a post with no syndication links draws no "Also on" list',
        'entity/User/profile/fields, none' => 'a profile with no list of addresses has no links to draw',
        'entity/User/profile/fields, one string' => 'an address that is not in a list is not drawn',
        'entity/User/profile/contact, none' => 'a profile with no list of addresses has no links to draw',
        'entity/User/profile/contact, one string' => 'an address that is not in a list is not drawn',
        'the comment form of nobody' => 'the form is for a logged in member',
        'nothing found, no search' => 'the notice is for a search that found nothing: with no search words this draws nothing',
        'the home blurb 0' => 'the blurb is a page owner\'s profile and there is none here, and the rest of the home page is for someone who can write',
        'the home blurb 1' => 'the blurb is a page owner\'s profile and there is none here, and the rest of the home page is for someone who can write',
        'the home blurb 2' => 'the blurb is a page owner\'s profile and there is none here, and the rest of the home page is for someone who can write',
        'the home blurb 3' => 'the blurb is a page owner\'s profile and there is none here, and the rest of the home page is for someone who can write',
        'embed, nothing' => 'a text with nothing to embed',
        'embed of a post, nothing' => 'a post with nothing to embed',
        'embed, youtube channel' => 'a channel address is not a video: nothing is embedded',
        'embed of a post, youtube channel' => 'a channel address is not a video: nothing is embedded',
        'embed, youtube c' => 'a channel address is not a video: nothing is embedded',
        'embed of a post, youtube c' => 'a channel address is not a video: nothing is embedded',
        'a post with no body' => 'a post with no text has nothing to embed',
        'the edit header' => 'the share tabs are drawn only for a share (`sharing` in the request)',
        'the delete and edit menu' => 'the menu is for someone who can edit the post',
        'tags output, none' => 'no tags, no tag row',
        'tags, an empty string' => 'a tag list that is empty has no tag row',
        'tags, null' => 'a tag list that is empty has no tag row',
        'tags, false' => 'a tag list that is empty has no tag row',
        'standard.site sync head, no address' => 'a post with no address on the network has no link to put in the head',
        'standard.site sync head, something that is not an entity' => 'a value that is not an entity has no link to put in the head',
        'standard.site sync head, no object' => 'with no post there is no link to put in the head',
        'tags, zero' => 'a tag list that is empty has no tag row',
        'tags, the string zero' => 'a tag list that is empty has no tag row',
        'user row, not a user' => 'a value that is not a user has no row',
        'embed, a hostile image' => 'an image address that is not an http or https one is not embedded',
        'embed of a post, a hostile image' => 'an image address that is not an http or https one is not embedded',
    ];

    /** @var list<string> what assertDrawn() found wrong, reported together at the end of the draw cases */
    private array $drawProblems = [];

    /** @var array<string, true> the labels and the groups of equal outputs that assertDrawn() has seen */
    private array $drawSeen = [];

    /**
     * Draw cases of one group that draw exactly the same output, keyed by their labels joined with " | " (in the order they are
     * returned), with why that is right.
     *
     * @var array<string, string>
     */
    private const SAME_OUTPUT = [
        'zone Europe/London for a member | a zone for a member with none' => 'London\'s offset is zero, and the template prints a difference from the member\'s own zone only for a zone whose offset is not zero.',
        'rich text output, a rel of zero | rich text output, a rel of the string zero | rich text output, a rel of an empty string | rich text output, a rel of null | rich text output, a rel of false' => 'Each is a value PHP and Twig read as false, which is no rel.',
        'richtext, zero | richtext, the string zero | richtext, an empty string | richtext, null | richtext, false' => 'Each is a value PHP and Twig read as false, which is no value: the editor takes a made-up id (`editor-` and a random number, which the case makes the same) and the template prints nothing else of them.',
        'form list, zero | form list, the string zero | form list, an empty string | form list, null | form list, false' => 'Each is a value PHP and Twig read as false, which is no value.',
        'idno version 0.1 | idno version 1.0-DEV | idno version ' => 'A required version below the installed one only says what is installed; these three are all below it.',
        'build version 1 | build version 7' => 'A required build below the installed one only says what is installed.',
        'php version 7 | php version 8.4.1' => 'A required version at or below the installed one only says what is installed.',
        'plugin dependency Status  | plugin dependency Status 0.0.1' => 'A required version at or below the plugin\'s only says that it is installed.',
        'statistics, none | statistics, not a list' => 'Both have no report to list, so only the page\'s heading is drawn.',
        'the shell of a post with an owner and none | the feed of nothing and of entities' => 'The shell of a post with no owner draws nothing, and the feed draws each entity through the shell, skips what is not one, and draws the no-content notice (empty here, with no search) for no items.',
        'likes | like' => '`entity/annotations/like` is `entity/annotations/likes`: it draws the same list.',
        'replies | reply' => '`entity/annotations/reply` is `entity/annotations/replies`: it draws the same list.',
        'annotation page, mention | annotation page, share | annotation page, rsvp' => 'There is no template `entity/annotations/mention`, `share` or `rsvp` (the lists are `mentions`, `shares` and `rsvps`), so the page of one of these draws only the wrapper.',
        'syndication 0 | syndication 2' => 'A service\'s links that are a string and not a list disable no account, as with no links.',
        'links, hostile on a permalink | links, hostile' => 'No address of this state is linked, and being on a permalink only adds `rel="syndication"` to a link.',
        'the error page 0 | the error page 0 in debug' => 'With no exception there is no detail for debug mode to add.',
        'owners without pictures | the object\'s owner has a picture' => 'The shell prints the picture of the page\'s owner (bob here), not of the owner of the object it describes (ada): the object\'s owner only decides which user the ActivityPub link names, and that is ada in both states.',
    ];

    /**
     * Pages beyond the golden ones, by who asks: `visitor`, `admin`, `member` (not an administrator) and `new` (a member added
     * last). Each must answer, not fail: a status below 500.
     *
     * @var list<array{string, string}>
     */
    public const EXTRA_PAGES = [
        ['visitor', 'account/password/reset/?email=ada%40example.com&code=00000000000000000000000000000000'],
        ['visitor', 'account/register/?email=new%40example.com&code=abc'],
        ['visitor', 'account/password/'],
        ['visitor', 'pages/about-this-site'],
        ['visitor', 'robots.txt'],
        ['visitor', 'tag/nothing-is-tagged-with-this'],
        ['visitor', '?q=zzzznothingmatches'],
        ['visitor', 'content/all/?offset=10'],
        ['member', 'admin/'],
        ['member', 'admin/users/'],
        ['member', 'account/settings/'],
        ['admin', 'admin/about/'],
        ['admin', 'admin/welcome/'],
        ['member', 'admin/welcome/'],
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
        ['admin', 'admin/styles/'],
        ['admin', 'admin/standardsitesync/'],
        ['admin', 'account/settings/standardsitesync/'],
        ['admin', 'indieauth/auth?me=http%3A%2F%2F127.0.0.1%2F&client_id=https%3A%2F%2Fclient.example%2F&redirect_uri=https%3A%2F%2Fclient.example%2Fcb&state=s1&scope=create'],
        ['admin', 'indieauth/auth?me=http%3A%2F%2F127.0.0.1%2F&client_id=https%3A%2F%2Fclient.example%2F&redirect_uri=https%3A%2F%2Fclient.example%2Fcb&state=s1'],
        ['admin', 'following/'],
        ['admin', 'following/add/'],
        ['admin', 'file/picker/'],
        ['admin', 'share/?share_url=https%3A%2F%2Fexample.org%2Fshared&share_title=Shared'],
        ['admin', 'search/users/?q=ada'],
        ['admin', 'drafts/'],
        ['admin', 'staticpages/edit/'],
    ];

    /**
     * Templates no page of this test reaches, drawn in the site by name: [type, name, variables]. A port that no page reaches
     * adds its case here.
     *
     * @var list<array{string, string, array<string, mixed>}>
     */
    public const DRAWS = [
        ['default', 'js/idno', []],
        ['default', 'shell/pagination', ['count' => 'abc', 'offset' => '3', 'items_per_page' => '12abc']],
        ['default', 'shell/pagination', ['count' => '45xyz', 'offset' => 'abc', 'items_per_page' => 'abc']],
        ['default', 'shell/pagination', ['count' => 45, 'offset' => 20, 'items_per_page' => 10, 'control-id' => 'c', 'source-url' => '/s?a=1&b=2']],
        ['email', 'shell', ['body' => '<p>Hello</p>']],
        ['email', 'shell', ['body' => '<p>Hello</p>', 'title' => 'Password reset', 'preheader' => 'A preview line']],
    ];

    protected bool $compareGoldens = false;

    private string $trace = '';

    protected function setUp(): void
    {
        $this->trace = sys_get_temp_dir() . '/idno-template-trace-' . bin2hex(random_bytes(6));
        touch($this->trace);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->trace !== '' && is_file($this->trace)) {
            unlink($this->trace);
        }
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_TEMPLATE_TRACE' => $this->trace];
    }

    /**
     * Every script the draw cases run in the site records its template draws in the trace, as the site's requests do.
     */
    protected function runInSite(string $code, bool $asConsole = false): mixed
    {
        $install = '\\Tests\\Fixtures\\TemplateTrace::install(' . $this->literal($this->trace) . ', ' . $this->literal(dirname(__DIR__, 2)) . ");\n";

        return parent::runInSite($install . $code, $asConsole);
    }

    public function testEveryTemplateDrawsWithoutAnErrorAndEveryTwigFileIsDrawn(): void
    {
        $paths = $this->seedSite();
        $admin = $this->loginAsAdmin();
        $this->enablePluginsTheSiteDoesNotHaveOn();

        $this->visitorPages('twenty26', $paths);
        $this->loggedInPages('logged-in', $paths, $admin, true);
        $this->nonHtmlPages($paths, $admin);
        $this->extraPages($paths, $admin);
        $this->drawCases();
        $this->assertSame([], $this->drawProblems);
        $this->assertSame([], array_values(array_diff([...array_keys(self::EMPTY_OUTPUT), ...array_keys(self::SAME_OUTPUT)], array_keys($this->drawSeen))), 'an entry of EMPTY_OUTPUT or SAME_OUTPUT that no draw case produces any more');
        $this->assertNoDifferences();

        $root = dirname(__DIR__, 2);
        $drawn = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($this->trace))) as $line) {
            $drawn[explode("\t", $line)[2] ?? '-'] = true;
        }
        $notDrawn = [];
        foreach (BundledTemplates::twigTemplates($root) as $path) {
            if (!isset($drawn[substr($path, strlen($root) + 1)])) {
                $notDrawn[] = substr($path, strlen($root) + 1);
            }
        }
        $this->assertSame([], $notDrawn, 'a Twig template that no page or draw case reaches: add one that does');
    }

    /**
     * What every labelled draw case must show: each output is text and is not empty (unless the label is in EMPTY_OUTPUT, which says
     * why that state draws nothing, and then it holds nothing but white space), and no two cases of one group draw the same output (unless the
     * pair is in SAME_OUTPUT, which says why). A group is what one draw method returns: its cases are states of the same templates, so
     * two states that look the same are a state the template does not tell apart.
     *
     * @param mixed $out the labelled outputs a draw method returned from the site
     */
    private function assertDrawn(mixed $out): void
    {
        $this->assertIsArray($out);
        $seen = [];
        $problems = [];
        // What a draw method is called is the group of its cases: `drawEntityCases` is `entity`
        $group = strtolower((string) preg_replace('/^draw|Cases$/', '', (string) (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'draw')));
        foreach ($out as $label => $html) {
            $label = (string) $label;
            $this->drawSeen[$label] = true;
            $this->assertIsString($html, $label);
            $this->drawGolden($group . '--' . $label, $html, true);
            if (isset(self::EMPTY_OUTPUT[$label])) {
                if (trim($html) !== '') {
                    $problems[] = $label . ' is meant to draw nothing (' . self::EMPTY_OUTPUT[$label] . ') and draws ' . strlen($html) . ' bytes';
                }
                continue;
            }
            if (trim($html) === '') {
                $problems[] = $label . ' drew nothing';
                continue;
            }
            // The same state drawn in another context (on its page, in a feed, as a reply) is checked for drawing something, not against
            // the other states: a feed item may well be the same for two states that differ on the page
            if (preg_match('~^a reply: |^embed of a post, | on its page$| in a feed$~', $label) !== 1) {
                $seen[$html][] = $label;
            }
        }
        foreach ($seen as $labels) {
            if (count($labels) < 2) {
                continue;
            }
            $key = implode(' | ', $labels);
            $this->drawSeen[$key] = true;
            if (trim(self::SAME_OUTPUT[$key] ?? '') === '') {
                $problems[] = 'these states draw the same output, so the template does not tell them apart: ' . $key;
            }
        }
        $this->drawProblems = [...$this->drawProblems, ...$problems];
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
        $mails = $this->drawMails();
        $this->assertNotEmpty($mails);
        foreach ($mails as $name => $mail) {
            // The mails that have a golden page are compared there
            if (!in_array($name, self::GOLDEN_MAILS, true)) {
                $this->drawGolden('mail--' . $name, $mail['html'], true);
                $this->drawGolden('mail--' . $name, $mail['text'], false);
            }
        }
        $this->drawIdentityLinks();
        $this->drawIconLeakCases();
        $this->drawEntityCases();
        $this->drawPluginPostCases();
        $this->drawPluginEditCases();
        $this->drawAnnotationCases();
        $this->drawSyndicationCases();
        $this->drawProfileAndFollowingCases();
        $this->drawEmbedAndFileCases();
        $this->drawFormCases();
        $this->drawFalsyValueCases();
        $this->drawAccountCases();
        $this->drawAdminCases();
        $this->drawPluginPageCases();
        if (self::DRAWS === []) {
            return;
        }
        $code = <<<'PHP'
        // A page of the site is being served: the templates read its address and the page itself
        $uri = '/content/all/?a=1&b=2';
        $_SERVER['REQUEST_URI'] = $uri;
        \Idno\Core\Idno::site()->setCurrentPage(new \Idno\Pages\Homepage());
        \Idno\Core\Idno::container()->get('request_stack')->push(\Symfony\Component\HttpFoundation\Request::create($uri, 'GET', [], [], [], ['REQUEST_URI' => $uri]));
        $drawn = [];
        foreach (json_decode(%DRAWS%, true) as [$type, $name, $vars]) {
            $template = clone \Idno\Core\Idno::site()->template();
            $template->setTemplateType($type);
            $drawn[] = (string) $template->__($vars)->draw($name);
        }

        return $drawn;
        PHP;
        $drawn = $this->runInSite(str_replace('%DRAWS%', $this->literal(json_encode(self::DRAWS, JSON_THROW_ON_ERROR)), $code));
        $this->assertIsArray($drawn);
        $this->assertCount(count(self::DRAWS), $drawn);
        foreach (self::DRAWS as $i => [$type, $name]) {
            $this->drawGolden('draws--' . ($i + 1) . ' ' . $type . ' ' . $name, (string) $drawn[$i], $type !== 'email-text');
        }
        $this->assertSame(count(self::DRAWS), count(array_unique($drawn)), 'each draw case draws something of its own: the states differ in what they show');
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
        \Idno\Core\Idno::site()->setCurrentPage(new \Idno\Pages\Homepage());
        \Idno\Core\Idno::container()->get('request_stack')->push(\Symfony\Component\HttpFoundation\Request::create($uri, 'GET', [], [], [], ['REQUEST_URI' => $uri]));
        $user = \Idno\Entities\User::getByHandle('ada');
        $user->profile = ['url' => ['https://example.org/ada', '@ada_l', 'mailto:ada@example.com', 'SMS:+15555550100', 'tel:+15555550100', 'javascript:alert(1)', 'data:text/html,x', 'mailto:a b@c', '']];
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
            $ada->image = $images['ada'] ?? null;
            $status->getOwner()->image = $images['ada'] ?? null;
            $bob->image = $images['bob'] ?? null;
            $page = new \Idno\Pages\Homepage();
            if ($owner) {
                $page->setOwner($owner);
            }
            $page->setPermalink(true, $status);
            \Idno\Core\Idno::site()->setCurrentPage($page);
            $live->vars = [];
            $vars = ['title' => 'T', 'description' => 'D', 'body' => '<p>b</p>', 'object' => $status];
            if ($user !== null) {
                $vars['user'] = $user;
            }
            $out[$label] = (string) $live->__($vars)->draw('shell');
        }

        $probe = clone $live;
        $probe->vars = [];
        $out['a user that is not a user'] = (string) $probe->__(['user' => 'not a user', 'object' => $status])->draw('shell/activitypub');

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertDrawn($out);
        $this->assertCount(4, $out);
        $this->assertStringContainsString('<script type="application/ld+json">', (string) $out['owners without pictures']);
        // The page's owner is bob and the object's is ada: the last icon lookup was for the object, which left its user on the template, and the ActivityPub
        // link is still the post's own address: it is decided from the entity the page is the permalink of
        foreach ($out as $label => $drawn) {
            if ($label !== 'a user that is not a user') {
                $this->assertStringContainsString('/view/', (string) $drawn, $label);
                $this->assertStringNotContainsString('/actor/', (string) $drawn, $label);
            }
        }
        // (the partial drawn alone, on a template whose page is the permalink of nothing: no link)
        $this->assertStringNotContainsString('/actor/', (string) $out['a user that is not a user']);
        $this->assertStringNotContainsString('ada.png', (string) $out['the object\'s owner has a picture'], 'the picture of the object\'s owner is not printed by the shell');
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
        \\Idno\\Core\\Idno::site()->setCurrentPage(new \\Idno\\Pages\\Homepage());
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
            \$template = clone \$site->template();
            \$template->setTemplateType(\$type);

            return (string) \$template->__(\$vars)->draw(\$name);
        };
        \$out = [];

        PHP;
    }

    /**
     * Entities in feeds and the drafts page, which no visit of the golden pages reaches with every branch: a feed item, a followed
     * feed and its subscription, the drafts page with and without drafts and with its paging.
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
        // The time of update of the post and of the user used as a second "draft" is now (a save of the post, a login of the user, since the seed fixed
        // them): fixed again, in memory
        $status->updated = 1773000000;
        $owner = $status->getOwner();
        $owner->updated = 1773000000;
        foreach ([[], [$status, $owner]] as $drafts) {
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
        $out['the edit wrapper'] = $draw('entity/editwrapper', ['body' => '<form>edit</form>']) . $draw('entity/editwrapper', ['object' => $status]);

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertDrawn($out);
        $this->assertStringContainsString('The item', (string) $out['feed item']);
        $this->assertStringContainsString('class="subscription"', str_replace('row subscription', 'subscription', (string) $out['subscription']));
        $this->assertStringContainsString('Cafe &quot;quoted&quot;', (string) $out['link preview, a card with a quote']);
    }

    /**
     * How a post of each bundled content type is drawn in a stream and on its own page, with the branches the fixture posts do not
     * reach: a note with entities, a reply address list, a link preview and an image; an article with and without a title; a photo
     * with several pictures, none, thumbnails and a broken address; audio and video; bookmarks, likes and reposts (and a script address);
     * a check-in with and without coordinates; an event with and without an end, one that ends the next day, and one that has no date; an
     * RSVP of each kind; a static page seen by a visitor and by its owner, hidden title, forward address.
     */
    private function drawPluginPostCases(): void
    {
        $code = $this->siteDrawPrelude() . <<<'PHP'
        $base = $site->config()->getURL();
        $page = static function (bool $permalink) use ($site): void {
            $page = new \Idno\Pages\Homepage();
            $property = new \ReflectionProperty(\Idno\Common\Page::class, 'isPermalinkPage');
            $property->setValue($page, $permalink);
            $site->setCurrentPage($page);
        };
        $post = static function (\Idno\Common\Entity $entity, array $fields) use ($ada): \Idno\Common\Entity {
            foreach ($fields as $name => $value) {
                $entity->$name = $value;
            }
            $entity->setOwner($ada);
            $entity->created = 1773000000;

            return $entity;
        };
        $each = static function (string $label, string $template, \Idno\Common\Entity $entity, array $extra = []) use (&$out, $draw, $page): void {
            foreach ([['', false, false], [' on its page', true, false], [' in a feed', false, true]] as [$where, $permalink, $feed]) {
                $page($permalink);
                $out[$label . $where] = $draw($template, ['object' => $entity, 'feed_view' => $feed] + $extra);
            }
        };

        // Notes
        foreach ([
            'a note' => ['body' => 'Hello world: a note with https://example.org/a?x=1&y=2 and #tagged text', 'tags' => '#one #two'],
            'a note with entities and markup' => ['body' => "Tom &amp; Jerry &lt;b&gt;bold&lt;/b&gt; it's \"quoted\" <script>alert(1)</script>\nsecond line\r\nthird line"],
            'a note with tag list' => ['body' => 'Tagged', 'tags' => ['alpha', 'beta gamma']],
            'a note with one address to reply to' => ['body' => '@ada says hi to @bob@example.org', 'inreplyto' => 'https://elsewhere.example/post'],
            'a note with addresses to reply to' => ['body' => 'Replying', 'inreplyto' => ['https://elsewhere.example/a', 'https://other.example/b']],
            'a note with an image' => ['body' => 'See <img src="https://img.example/a.png"> here https://www.youtube.com/watch?v=abc'],
            'a note with a video link' => ['body' => 'Watch https://www.youtube.com/watch?v=abc-1_ now'],
            'a note with a link preview' => ['body' => 'A link https://a.example/p', 'link_preview' => ['url' => 'https://a.example/p', 'title' => 'The title', 'description' => 'The description', 'image' => 'https://a.example/i.png']],
            'a note with a hidden link preview' => ['body' => 'A link https://a.example/p', 'link_preview' => ['url' => 'https://a.example/p', 'title' => 'The title'], 'hide_preview' => true],
            'a note with nothing' => ['body' => ''],
            'a note that is only a zero' => ['body' => '0'],
        ] as $label => $fields) {
            $each($label, 'entity/Status', $post(new \IdnoPlugins\Status\Status(), $fields));
            $each('a reply: ' . $label, 'entity/Reply', $post(new \IdnoPlugins\Status\Reply(), $fields));
        }
        $out['the icons of a note and a reply'] = $draw('entity/Status/icon') . $draw('entity/Reply/icon');

        // Articles
        foreach ([
            'an article' => ['title' => 'A "longer" article <b>about</b> templates', 'short_description' => 'The <i>subtitle</i> & more', 'body' => '<p>Templates draw pages. <strong>Hello</strong> #again https://example.org/x</p><script>alert(1)</script>', 'tags' => '#templates #idno'],
            'an article with no title or subtitle' => ['body' => '<p>Only a body</p>'],
            'an article with a script address' => ['title' => 'Links', 'body' => '<p><a href="javascript:alert(1)">x</a> <a href="https://example.org/ok">ok</a></p>'],
        ] as $label => $fields) {
            $each($label, 'entity/Entry', $post(new \IdnoPlugins\Text\Entry(), $fields));
        }
        $out['the icon of an article'] = $draw('entity/Entry/icon');

        // Photos
        $pictures = static function (int $count) use ($base): array {
            $list = [];
            for ($n = 1; $n <= $count; $n++) {
                $list[] = ['url' => $base . 'file/0000000000004000800000000000' . sprintf('%04d', $n) . '/photo' . $n . '.png', 'filename' => 'photo' . $n . '.png', '_id' => '0000000000004000800000000000' . sprintf('%04d', $n), 'mime-type' => 'image/png', 'length' => 100];
            }

            return $list;
        };
        foreach ([
            'a photo' => ['title' => 'A "fixture" photo', 'body' => 'A <b>caption</b> with https://example.org/c and #hash', 'attachments' => $pictures(1), 'tags' => '#snap'],
            'a photo with seven pictures' => ['title' => 'Many', 'body' => 'Seven', 'attachments' => $pictures(7)],
            'a photo with six pictures' => ['title' => 'Six', 'body' => 'Six', 'attachments' => $pictures(6)],
            'a photo with five pictures' => ['title' => 'Five', 'body' => 'Five', 'attachments' => $pictures(5)],
            'a photo with none' => ['title' => 'None', 'body' => 'No pictures'],
            'a photo with no title' => ['body' => 'Untitled', 'attachments' => $pictures(2)],
            'a photo called Untitled' => ['title' => 'Untitled', 'body' => 'Untitled', 'attachments' => $pictures(1)],
            'a photo with thumbnails' => ['title' => 'Thumbs', 'body' => 'Thumbs', 'attachments' => $pictures(2), 'thumbs_large' => ['photo1.png' => ['url' => $base . 'file/thumb1/t1.png']], 'thumbnail_large' => $base . 'file/large/l.png', 'thumbnail' => $base . 'file/small/s.png'],
            'a photo with a large thumbnail' => ['title' => 'Large', 'body' => 'Large', 'attachments' => $pictures(1), 'thumbnail_large' => $base . 'file/large/l.png', 'thumbnail' => $base . 'file/small/s.png'],
            'a photo with a small thumbnail' => ['title' => 'Small', 'body' => 'Small', 'attachments' => $pictures(1), 'thumbnail' => $base . 'file/small/s.png'],
            'a photo with a broken address' => ['title' => 'Broken', 'body' => 'Broken', 'attachments' => [['url' => 'http:///file/x/broken.png', 'filename' => 'broken.png', '_id' => 'x']], 'thumbnail' => 'https:///file/y/thumb.png'],
            'a photo with a script address' => ['title' => 'Script', 'body' => 'Script', 'attachments' => [['url' => 'javascript:alert(1)', 'filename' => 'x.png', '_id' => 'x']]],
            'a photo with a remote address' => ['title' => 'Remote', 'body' => '', 'attachments' => [['url' => 'https://img.example/p.png?a=1&b=2', 'filename' => 'p.png', '_id' => 'x']]],
        ] as $label => $fields) {
            $each($label, 'entity/Photo', $post(new \IdnoPlugins\Photo\Photo(), $fields));
        }
        $site->config()->attachment_base_host = 'cdn.example';
        $each('a photo on an attachment host', 'entity/Photo', $post(new \IdnoPlugins\Photo\Photo(), ['title' => 'Cdn', 'body' => 'Cdn', 'attachments' => $pictures(2)]));
        $site->config()->attachment_base_host = '';
        // No page at all (a console process: the queue worker's export draws each entity through `$entity->draw()`): every picture is shown, as the original showed them
        $noPage = new \ReflectionProperty($site, 'currentPage');
        $pageWas = $site->currentPage();
        $noPage->setValue($site, null);
        $out['a photo with seven pictures, drawn with no page'] = $draw('entity/Photo', ['object' => $post(new \IdnoPlugins\Photo\Photo(), ['title' => 'Many', 'body' => 'Seven', 'attachments' => $pictures(7)]), 'feed_view' => true]);
        $noPage->setValue($site, $pageWas);
        $out['the icon of a photo'] = $draw('entity/Photo/icon');

        // Audio and video
        foreach ([
            'audio' => ['title' => 'A <b>recording</b>', 'body' => 'Listen https://example.org/r #sound', 'media_type' => 'audio/mpeg', 'attachments' => [['url' => $base . 'file/00000000000040008000000000000022/sound.mp3', 'filename' => 'sound.mp3', '_id' => '22', 'mime-type' => 'audio/mpeg']], 'tags' => '#audio'],
            'audio with two files' => ['title' => 'Two', 'body' => 'Two files', 'media_type' => 'audio/mpeg', 'attachments' => [['url' => $base . 'file/a1/one.mp3', 'filename' => 'one.mp3', '_id' => '1', 'mime-type' => 'audio/mpeg'], ['url' => $base . 'file/a2/two.mp3', 'filename' => 'two.mp3', '_id' => '2', 'mime-type' => 'audio/mpeg']]],
            'video' => ['title' => 'A video', 'body' => 'Watch', 'media_type' => 'video/mp4', 'attachments' => [['url' => $base . 'file/v1/clip.mp4', 'filename' => 'clip.mp4', '_id' => 'v1', 'mime-type' => 'video/mp4'], ['url' => $base . 'file/v2/clip.webm', 'filename' => 'clip.webm', '_id' => 'v2', 'mime-type' => 'video/webm']]],
            'media with no file' => ['title' => 'Nothing', 'body' => 'No file', 'media_type' => 'audio/mpeg'],
            'media with no type' => ['title' => 'Typeless', 'body' => 'No type', 'attachments' => [['url' => $base . 'file/t1/x.mp3', 'filename' => 'x.mp3', '_id' => 't', 'mime-type' => 'audio/mpeg']]],
            'audio with a hostile address' => ['title' => 'Hostile', 'body' => 'H', 'media_type' => 'audio/mpeg', 'attachments' => [['url' => "https://x.example/a'b.mp3", 'filename' => 'x', '_id' => 'x', 'mime-type' => 'audio/mpeg'], ['url' => 'javascript:alert(1)', 'filename' => 'y', '_id' => 'y', 'mime-type' => 'x" onerror="1']]],
        ] as $label => $fields) {
            $each($label, 'entity/Media', $post(new \IdnoPlugins\Media\Media(), $fields));
        }
        $out['the icon of media'] = $draw('entity/Media/icon');

        // Bookmarks, likes and reposts
        foreach ([
            'a bookmark' => ['body' => 'https://example.org/interesting?a=1&b=2', 'bookmarkof' => 'https://example.org/interesting', 'pageTitle' => 'An <i>interesting</i> page', 'description' => 'Worth reading, <b>hello</b> https://example.org/more', 'tags' => '#links'],
            'a bookmark with no title or notes' => ['body' => 'https://example.org/bare'],
            'a bookmark with a script address' => ['body' => 'javascript:alert(1)', 'pageTitle' => 'Click me'],
            'a bookmark with a script address and no title' => ['body' => 'javascript:alert(1)'],
            'a bookmark with a hostile title' => ['body' => 'https://example.org/x', 'pageTitle' => '"><script>alert(1)</script> https://example.org/y'],
            'a like' => ['body' => 'https://example.org/liked', 'likeof' => 'https://example.org/liked'],
            'a repost' => ['body' => 'https://example.org/reposted', 'repostof' => 'https://example.org/reposted', 'description' => 'Repost notes'],
            'a bookmark of a video' => ['body' => 'https://www.youtube.com/watch?v=abc-1_', 'pageTitle' => 'A video'],
        ] as $label => $fields) {
            $each($label, 'entity/Like', $post(new \IdnoPlugins\Like\Like(), $fields));
        }
        $out['the icon of a bookmark'] = $draw('entity/Like/icon');

        // Check-ins
        foreach ([
            'a check-in' => ['title' => 'Checked into The Library', 'placename' => 'The Library', 'lat' => 51.5072, 'long' => -0.1276, 'address' => '1 Library Road', 'body' => 'A quiet <b>afternoon</b> https://example.org/b #books', 'tags' => '#books'],
            'a check-in with no body' => ['placename' => 'Somewhere', 'lat' => '40.7', 'long' => '-74'],
            'a check-in with no coordinates' => ['placename' => 'Nowhere', 'body' => 'No map'],
            'a check-in with a hostile place' => ['placename' => '"><script>alert(1)</script>', 'lat' => 1.5, 'long' => 2.5, 'body' => '<script>alert(2)</script>'],
            'a check-in with hostile coordinates' => ['placename' => 'Odd', 'lat' => '1);alert(1', 'long' => '2);alert(2'],
            'an anonymous check-in' => ['placename' => 'Hidden', 'lat' => 10.0, 'long' => 20.0, 'anonymity' => 'Yes'],
        ] as $label => $fields) {
            $entity = $post(new \IdnoPlugins\Checkin\Checkin(), $fields);
            $entity->_id = '00000000-0000-4000-8000-0000000000aa';
            $each($label, 'entity/Checkin', $entity);
        }
        $out['the icon of a check-in'] = $draw('entity/Checkin/icon');

        // Events and RSVPs
        foreach ([
            'an event' => ['title' => 'Fixture "meetup"', 'summary' => 'A <b>meetup</b>', 'body' => 'We meet https://example.org/m #hello', 'location' => 'The Library, <i>Springfield</i>', 'starttime' => '2026-04-10 18:00', 'endtime' => '2026-04-10 20:00', 'timezone' => 'Europe/London'],
            'an event with no end' => ['title' => 'No end', 'body' => 'Open', 'starttime' => '2026-04-10 18:00', 'timezone' => 'UTC'],
            'an event that ends the next day' => ['title' => 'Long', 'body' => 'Long', 'starttime' => '2026-04-10 18:00', 'endtime' => '2026-04-11 20:00'],
            'an event that ends before it starts' => ['title' => 'Backwards', 'body' => 'B', 'starttime' => '2026-04-10 18:00', 'endtime' => '2026-04-09 09:00'],
            'an event with no start' => ['title' => 'Undated', 'body' => 'Undated', 'endtime' => '2026-04-11 20:00', 'location' => 'Somewhere'],
            'an event with nothing but a name' => ['title' => 'Bare'],
            'an event with a date that is not a date' => ['title' => 'Odd', 'body' => 'Odd', 'starttime' => 'next flurb', 'endtime' => 'a week of mondays'],
            'an event before 1970' => ['title' => 'Old', 'body' => 'Old', 'starttime' => '1960-05-01 10:00', 'endtime' => '1960-05-01 12:00'],
            'an event with hostile text' => ['title' => '<script>alert(1)</script>', 'summary' => '"><script>x</script>', 'location' => '<b>here</b>', 'body' => '<script>alert(2)</script> <img src=x onerror=alert(3)>', 'starttime' => '2026-04-10 18:00', 'timezone' => 'Europe/London'],
        ] as $label => $fields) {
            $each($label, 'entity/Event', $post(new \IdnoPlugins\Event\Event(), $fields));
        }
        $out['the icons of an event and an RSVP'] = $draw('entity/Event/icon') . $draw('entity/RSVP/icon');
        foreach ([
            'an RSVP that is yes' => ['rsvp' => 'yes', 'body' => 'I will be there https://example.org/e #event', 'inreplyto' => 'https://example.org/event/1'],
            'an RSVP that is no' => ['rsvp' => 'no', 'body' => 'Sorry', 'inreplyto' => 'https://example.org/event/1'],
            'an RSVP that is maybe' => ['rsvp' => 'maybe', 'body' => '', 'inreplyto' => ['https://example.org/event/1']],
            'an RSVP of another word' => ['rsvp' => 'éxcited', 'body' => 'Wow'],
            'an RSVP with a hostile answer and text' => ['rsvp' => 'yes" onmouseover="alert(1)', 'body' => 'See you <script>alert(1)</script> <a href="javascript:alert(2)">x</a> <img src="https://example.com/x.png" onerror="alert(3)">', 'inreplyto' => 'javascript:alert(4)'],
            'an RSVP with a video' => ['rsvp' => 'YES', 'body' => 'Watch https://www.youtube.com/watch?v=abc-1_', 'inreplyto' => 'https://example.org/event/1'],
        ] as $label => $fields) {
            $each($label, 'entity/RSVP', $post(new \IdnoPlugins\Event\RSVP(), $fields));
        }

        // Static pages, seen by a visitor and by the administrator who owns them
        foreach ([
            'a static page' => ['title' => 'About "this" site', 'body' => '<p>The static page <b>about</b> the site. https://example.org/s #site</p>', 'category' => 'No Category', 'forward_url' => '', 'hide_title' => ''],
            'a static page with a hidden title' => ['title' => 'Hidden', 'body' => '<p>Body</p>', 'hide_title' => '1'],
            'a static page that forwards' => ['title' => 'Forwards', 'body' => '<p>Body</p>', 'forward_url' => 'https://example.org/else?a=1&b=2'],
            'a static page with a hostile title and address' => ['title' => '<script>alert(1)</script>', 'body' => '<p>Body</p><script>alert(2)</script>', 'forward_url' => 'javascript:alert(3)'],
            'a static page with a quote in its address' => ['title' => 'Quote', 'body' => '<p>b</p>', 'forward_url' => 'https://example.org/"><script>alert(1)</script>'],
        ] as $label => $fields) {
            foreach ([false, true] as $admin) {
                $admin ? $site->session()->logUserOn($ada) : $site->session()->logUserOff();
                $entity = $post(new \IdnoPlugins\StaticPages\StaticPage(), $fields);
                $entity->_id = '00000000-0000-4000-8000-0000000000bb';
                $each($label . ($admin ? ' seen by its owner' : ''), 'entity/StaticPage', $entity);
            }
        }
        $site->session()->logUserOff();

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertDrawn($out);
        $this->assertStringContainsString('Hello world: a note', (string) $out['a note']);
        $this->assertStringContainsString('class="p-rsvp"', (string) $out['an RSVP that is yes']);
        $this->assertStringContainsString('dt-end', (string) $out['an event']);
    }

    /**
     * The compose and edit form of each bundled content type, as a member writes a new post and edits a stored one: with a draft, a
     * shared address, a reply address (one, several), what was typed before, pictures, a time zone and categories, and the share
     * script that sets focus.
     */
    private function drawPluginEditCases(): void
    {
        $code = $this->siteDrawPrelude() . <<<'PHP'
        $base = $site->config()->getURL();
        $site->session()->logUserOn($ada);
        $site->syndication()->registerService('zzedit', static fn () => true, ['note', 'article', 'image', 'media', 'bookmark', 'place', 'event']);
        $site->syndication()->registerServiceAccount('zzedit', 'ada_l', 'Ada L');
        $stored = static function (\Idno\Common\Entity $entity, array $fields, string $status = 'published') use ($ada): \Idno\Common\Entity {
            foreach ($fields as $name => $value) {
                $entity->$name = $value;
            }
            $entity->_id = '00000000-0000-4000-8000-0000000000cc';
            $entity->setOwner($ada);
            $entity->setPublishStatus($status);
            $entity->created = 1773000000;
            $entity->posse = ['zzedit' => [['account_id' => 'ada_l', 'url' => 'https://x.example/1', 'identifier' => 'ada_l']]];

            return $entity;
        };
        $pictures = static fn (int $count): array => array_map(static fn (int $n): array => ['url' => $base . 'file/0000000000004000800000000000' . sprintf('%04d', $n) . '/photo' . $n . '.png', 'filename' => 'photo' . $n . '.png', '_id' => '0000000000004000800000000000' . sprintf('%04d', $n), 'mime-type' => 'image/png'], range(1, $count));
        $forms = [
            'entity/Status/edit' => [\IdnoPlugins\Status\Status::class, ['body' => 'A <b>note</b> & "more" #tag', 'inreplyto' => 'https://elsewhere.example/post', 'tags' => '#tag']],
            'entity/Entry/edit' => [\IdnoPlugins\Text\Entry::class, ['title' => 'An "article"', 'short_description' => 'Sub & title', 'body' => '<p>Body <b>text</b></p>', 'inreplyto' => ['https://elsewhere.example/a', 'https://other.example/b']]],
            'entity/Photo/edit' => [\IdnoPlugins\Photo\Photo::class, ['title' => 'A "photo"', 'body' => 'Caption <b>here</b>', 'attachments' => $pictures(2)]],
            'entity/Media/edit' => [\IdnoPlugins\Media\Media::class, ['title' => 'A "recording"', 'body' => 'Notes', 'media_type' => 'audio/mpeg']],
            'entity/Like/edit' => [\IdnoPlugins\Like\Like::class, ['body' => 'https://example.org/page?a=1&b=2', 'pageTitle' => 'A "page"', 'description' => 'Notes <b>b</b>']],
            'entity/Checkin/edit' => [\IdnoPlugins\Checkin\Checkin::class, ['placename' => 'The "Library"', 'lat' => 51.5, 'long' => -0.12, 'address' => '1 Library Road & more', 'body' => 'Quiet', 'anonymity' => 'Yes']],
            'entity/Event/edit' => [\IdnoPlugins\Event\Event::class, ['title' => 'A "meetup"', 'summary' => 'Sum & mary', 'location' => 'Library', 'starttime' => '2026-04-10T18:00', 'endtime' => '2026-04-10T20:00', 'timezone' => 'Europe/London', 'body' => 'We meet']],
            'entity/RSVP/edit' => [\IdnoPlugins\Event\RSVP::class, ['rsvp' => 'maybe', 'body' => 'Perhaps "so"', 'inreplyto' => ['https://example.org/event/1', 'https://example.org/event/2']]],
            'entity/StaticPage/edit' => [\IdnoPlugins\StaticPages\StaticPage::class, ['title' => 'A "page"', 'body' => '<p>Page</p>', 'forward_url' => 'https://example.org/else?a=1&b=2', 'hide_title' => '1']],
        ];
        foreach ($forms as $name => [$class, $fields]) {
            $short = substr($name, 7, -5);
            // The page of a static page always says in which category it is
            $category = $class === \IdnoPlugins\StaticPages\StaticPage::class ? ['category' => 'No Category'] : [];
            $out[$short . ' new'] = $draw($name, ['object' => new $class()] + $category);
            $out[$short . ' stored'] = $draw($name, ['object' => $stored(new $class(), $fields)] + $category);
            $out[$short . ' draft'] = $draw($name, ['object' => $stored(new $class(), $fields, 'draft')] + $category);
            $out[$short . ' with what was typed'] = $draw($name, ['object' => new $class(), 'url' => 'https://shared.example/x?a=1&b="2"', 'body' => 'Typed <b>text</b> "here"', 'tags' => '#typed', 'category' => 'Writing', 'categories' => ['Writing', 'Notes & "more"']]);
            $_GET = ['share_url' => 'https://shared.example/x'];
            $_REQUEST = $_GET;
            $out[$short . ' shared'] = $draw($name, ['object' => new $class(), 'url' => 'https://shared.example/x'] + $category);
            $_GET = [];
            $_REQUEST = [];
        }
        $out['status with nothing stored but a reply list'] = $draw('entity/Status/edit', ['object' => $stored(new \IdnoPlugins\Status\Status(), ['body' => 'x', 'inreplyto' => ['https://a.example/1', 'https://b.example/2']])]);
        $out['status with an empty reply'] = $draw('entity/Status/edit', ['object' => $stored(new \IdnoPlugins\Status\Status(), ['body' => 'x', 'inreplyto' => ''])]);
        $out['status with a reply that the url replaces'] = $draw('entity/Status/edit', ['object' => $stored(new \IdnoPlugins\Status\Status(), ['body' => 'x', 'inreplyto' => 'https://a.example/1']), 'url' => 'https://b.example/2']);
        $out['photo with seven pictures'] = $draw('entity/Photo/edit', ['object' => $stored(new \IdnoPlugins\Photo\Photo(), ['title' => 'Many', 'attachments' => $pictures(7)])]);
        $out['photo with one picture'] = $draw('entity/Photo/edit', ['object' => $stored(new \IdnoPlugins\Photo\Photo(), ['title' => 'One', 'attachments' => $pictures(1)])]);
        $out['event with no time zone'] = $draw('entity/Event/edit', ['object' => $stored(new \IdnoPlugins\Event\Event(), ['title' => 'Zoneless'])]);
        $out['an rsvp with an address that is not http'] = $draw('entity/RSVP/edit', ['object' => $stored(new \IdnoPlugins\Event\RSVP(), ['rsvp' => 'yes', 'inreplyto' => ['javascript:alert(1)', 'https://ok.example/e']])]);
        $out['an rsvp with none'] = $draw('entity/RSVP/edit', ['object' => $stored(new \IdnoPlugins\Event\RSVP(), ['rsvp' => 'no'])]);
        $out['a static page with a plain title'] = $draw('entity/StaticPage/edit', ['object' => $stored(new \IdnoPlugins\StaticPages\StaticPage(), ['title' => 'Untitled', 'body' => 'b']), 'category' => 'No Category', 'categories' => ['One']]);
        $out['a static page in a category'] = $draw('entity/StaticPage/edit', ['object' => $stored(new \IdnoPlugins\StaticPages\StaticPage(), ['title' => 'T', 'body' => 'b', 'hide_title' => '']), 'category' => 'Two', 'categories' => ['One', 'Two']]);
        $site->session()->logUserOff();

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertDrawn($out);
        $this->assertStringContainsString('name="publish_status"', (string) $out['Status new']);
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
        $this->assertDrawn($out);
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
            $template = clone $site->template();
            $out['syndication ' . $i] = (string) $template->drawSyndication('note', $links);
        }
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
                $page = new \Idno\Pages\Homepage();
                $property = new \ReflectionProperty(\Idno\Common\Page::class, 'isPermalinkPage');
                $property->setValue($page, $permalink);
                $site->setCurrentPage($page);
                $out['links, ' . $label . ($permalink ? ' on a permalink' : '')] = $draw('content/syndication/links', ['object' => $object]);
            }
        }

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertDrawn($out);
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
        $group->_id = '00000000-0000-4000-8000-000000000901'; // an id of its own makes the same address every run
        $group->title = 'Friends & "family"';
        $group->owner = $user->getUUID();
        $group->access_group_type = 'FOLLOWING';
        $group->save(true);
        $other = new \Idno\Entities\AccessGroup();
        $other->_id = '00000000-0000-4000-8000-000000000902';
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
        $rsvpAnnotations = [];
        foreach ([['yes', 1773000300], ['no', 1773000100], ['yes', 1773000200]] as $i => [$answer, $time]) {
            $rsvpAnnotations[$status->getURL() . '/annotations/rsvp' . $i] = ['permalink' => 'https://example.org/g' . $i, 'owner_name' => 'Guest ' . $i, 'owner_url' => 'https://example.org/g' . $i, 'owner_image' => '', 'content' => 'Guest ' . $i, 'time' => $time, 'rsvp' => $answer];
        }
        $out['rsvps of a member who may delete them'] = $draw('entity/annotations/rsvps', ['annotations' => $rsvpAnnotations, 'object' => $status]);
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
        $this->assertDrawn($out);
        $this->assertStringContainsString('class="u-email"', (string) $out['entity/User/profile/fields, a list of addresses']);
    }

    /**
     * The form controls and outputs: each input with every attribute it reads and with none, the select with one value and many, the
     * rich text and long text fields, the outputs (text, address, rich text, tags, time zones) for a visitor and a member, a form and its
     * token, the action link of every shape, the user search and its rows. A second group holds values that a person or another site
     * could supply (a quote, a tag, a script) in every place a control prints one: where the old template printed it as it was, the
     * difference is an exception of its own (TemplateEscapingTest::testFormValuesAreEscaped).
     */
    private function drawFormCases(): void
    {
        $code = $this->siteDrawPrelude() . <<<'PHP'
        $hostile = '"><script>alert(1)</script>\'';
        $cases = [
            'input, every attribute' => ['forms/input/input', ['name' => 'title', 'id' => 'given-id', 'value' => 'A "title" & <b>', 'placeholder' => 'Title & more', 'alt' => 'Alt text', 'class' => 'my-class', 'type' => 'number', 'autocomplete' => 'off', 'autofocus' => true, 'accept' => 'image/*', 'checked' => true, 'disabled' => true, 'min' => 1, 'max' => 5, 'step' => 1, 'maxlength' => 20, 'multiple' => true, 'pattern' => '[a-z]+', 'readonly' => true, 'required' => true, 'src' => '/x.png', 'spellcheck' => 'false', 'onclick' => 'go(1)', 'onfocus' => 'a()', 'onblur' => 'b()', 'onchange' => 'c()']],
            'input, a name only' => ['forms/input/input', ['name' => 'plain']],
            'input, false and zero' => ['forms/input/input', ['name' => 'zero', 'value' => 0, 'maxlength' => 0, 'required' => false, 'disabled' => '', 'class' => 'x']],
            'input, null attributes' => ['forms/input/input', ['name' => 'nulls', 'type' => null, 'value' => null, 'required' => null, 'placeholder' => null]],
            'input, an apostrophe in the value' => ['forms/input/input', ['name' => 'quote', 'value' => "it's", 'placeholder' => "o'clock"]],
            'text' => ['forms/input/text', ['name' => 'text', 'value' => 'Some text']],
            'text with a class' => ['forms/input/text', ['name' => 'text', 'value' => 'Some text', 'class' => 'wide', 'id' => 'mine']],
            'email' => ['forms/input/email', ['name' => 'email', 'value' => 'ada@example.com']],
            'url' => ['forms/input/url', ['name' => 'url', 'value' => 'https://example.org/?a=1&b=2', 'class' => 'u']],
            'password' => ['forms/input/password', ['name' => 'pass', 'value' => 'secret']],
            'hidden' => ['forms/input/hidden', ['name' => 'forward-to', 'value' => '/here?x=1&y=2']],
            'file' => ['forms/input/file', ['name' => 'photo[]', 'accept' => 'image/*']],
            'datetime-local' => ['forms/input/datetime-local', ['name' => 'when', 'value' => '2026-01-02T03:04']],
            'button' => ['forms/input/button', ['name' => 'go', 'value' => 'Go']],
            'submit' => ['forms/input/submit', ['name' => 'save', 'value' => 'Save', 'class' => 'btn big']],
            'captcha' => ['forms/input/captcha', []],
            'select' => ['forms/input/select', ['name' => 'colour', 'options' => ['r' => 'Red & rose', 'g' => 'Green', 3 => 'Three'], 'value' => 'g', 'class' => 'c']],
            'select, no blank' => ['forms/input/select', ['name' => 'colour', 'options' => ['r' => 'Red'], 'value' => 'x', 'blank-default' => false, 'id' => 'own', 'required' => true, 'onchange' => 'f()']],
            'select, multiple' => ['forms/input/select', ['name' => 'colours', 'options' => ['r' => 'Red', 'g' => 'Green', 'b' => 'Blue'], 'value' => ['r', 'b'], 'multiple' => true, 'class' => 'many']],
            'select, none' => ['forms/input/select', ['name' => 'empty', 'value' => '']],
            'select, a number' => ['forms/input/select', ['name' => 'n', 'options' => [0 => 'Zero', 1 => 'One'], 'value' => 1]],
            'time zones' => ['forms/input/timezones', ['name' => 'timezone', 'value' => 'Europe/London', 'class' => 'form-control']],
            'longtext' => ['forms/input/longtext', ['name' => 'body', 'value' => "line <b>1</b>\n\"two\" & 'three'", 'unique_id' => 'body-one', 'required' => true, 'class' => 'wide', 'height' => 300, 'placeholder' => 'Say it "now"']],
            'longtext, defaults' => ['forms/input/longtext', ['name' => 'body']],
            'richtext' => ['forms/input/richtext', ['name' => 'body', 'value' => "<p>Some <b>html</b> & \"quotes\"</p>\n</textarea><script>alert(1)</script>", 'unique_id' => 'editor-one', 'required' => true]],
            'richtext, defaults' => ['forms/input/richtext', []],
            'text output' => ['forms/output/text', ['value' => 'A & B <i>c</i> "d" \'e\'']],
            'email output' => ['forms/output/email', ['value' => 'ada@example.com', 'label' => '']],
            'email output with a label' => ['forms/output/email', ['value' => 'ada@example.com', 'label' => 'Mail <Ada>']],
            'email output with a bad address' => ['forms/output/email', ['value' => 'a b"c<d>@example.com', 'label' => null]],
            'url output' => ['forms/output/url', ['value' => 'https://example.org/a?b=1&c=2', 'label' => '']],
            'url output with a label' => ['forms/output/url', ['value' => 'https://example.org/a', 'label' => 'Example & co']],
            'rich text output' => ['forms/output/richtext', ['value' => '<p>Hello <b>world</b> #tagged https://example.org/a?b=1 <script>alert(1)</script></p>']],
            'rich text output with a rel' => ['forms/output/richtext', ['value' => 'See https://example.org/ #one', 'rel' => 'rel="nofollow"']],
            'tags output' => ['forms/output/tags', ['tags' => ['one', 'two-words', 'a&b']]],
            'tags output, one string' => ['forms/output/tags', ['tags' => 'solo']],
            'tags output, a string with a space' => ['forms/output/tags', ['tags' => '#templates #idno']],
            'tags output, none' => ['forms/output/tags', ['tags' => []]],
            'a form' => ['forms/action', ['action' => '/status/edit?a=1&b=2', 'method' => 'post', 'body' => '<p>The body</p>']],
            'a form to get' => ['forms/action', ['action' => '/search', 'method' => 'GET', 'body' => '<p>b</p>']],
            'a form with another method' => ['forms/action', ['action' => '/x', 'method' => 'delete', 'body' => '']],
            'a token' => ['forms/token', ['action' => '/x?a=1', 'time' => 1773000000]],
            'a token, now' => ['forms/token', ['action' => '/y']],
            'a link' => ['forms/link', ['url' => '/status/delete', 'label' => '<b>Remove</b>', 'data' => ['uuid' => 'abc', 'n' => 3], 'class' => 'x y', 'title' => 'Remove it', 'confirm' => false, 'method' => 'POST']],
            'a link to confirm' => ['forms/link', ['url' => 'https://example.org/go?a=1&b=2', 'label' => 'Go', 'data' => [], 'confirm' => true, 'confirm-text' => "Are you sure? It's \"final\"\nreally \\ done", 'method' => 'DELETE']],
            'a link, a method that is not allowed' => ['forms/link', ['url' => '/x', 'label' => 'x', 'data' => ['a' => 'b'], 'method' => 'TRACE', 'confirm' => true]],
            'a link to a script' => ['forms/link', ['url' => 'javascript:alert(1)', 'label' => 'x', 'data' => ['a' => $hostile], 'class' => $hostile, 'title' => $hostile, 'confirm' => true, 'confirm-text' => $hostile, 'method' => $hostile]],
            'a form list' => ['forms/input/form-list', ['form' => ['title' => 'text', 'email' => 'email', 'secret' => 'hidden'], 'labels' => ['title' => 'Title', 'email' => 'Email address', 'secret' => 's'], 'placeholders' => ['title' => 'Say "it"'], 'help' => ['email' => 'We will not <share> it'], 'defaults' => ['title' => 'Default title', 'email' => '', 'secret' => 'x'], 'required' => ['title', 'email', 'secret'], 'values' => ['email' => 'ada@example.com']]],
            'a form list of two' => ['forms/input/form-list', ['form' => ['when' => 'datetime-local', 'pass' => 'password'], 'labels' => ['when' => 'When', 'pass' => 'Password'], 'placeholders' => ['pass' => 'Not 1234'], 'help' => [], 'defaults' => ['when' => '', 'pass' => ''], 'required' => ['when', 'pass'], 'values' => ['when' => '2026-01-02T03:04']]],
            'the user search' => ['forms/usersearch', []],
            'the user search, named' => ['forms/usersearch', ['name' => 'people', 'id' => 'my-people', 'class' => 'wide', 'source-url' => 'https://x.example/find/', 'render-template' => 'forms/components/usersearch/user']],
        ];
        foreach ($cases as $label => [$name, $vars]) {
            $out[$label] = $draw($name, $vars);
        }

        // What a person or another site could supply, in every place a control prints a value
        $hostileCases = [
            'hostile input' => ['forms/input/input', ['name' => $hostile, 'id' => $hostile, 'value' => $hostile, 'placeholder' => $hostile, 'alt' => $hostile, 'class' => $hostile, 'type' => $hostile, 'pattern' => $hostile, 'onclick' => $hostile]],
            'hostile text' => ['forms/input/text', ['name' => 'x', 'value' => $hostile, 'placeholder' => $hostile]],
            'a non-ASCII value' => ['forms/input/text', ['name' => 'x', 'value' => 'caf' . "\u{e9}" . ' ' . "\u{2603}", 'placeholder' => "\u{fc}ber"]],
            'hostile select' => ['forms/input/select', ['name' => $hostile, 'id' => $hostile, 'options' => [$hostile => $hostile, 'ok' => 'fine'], 'value' => [$hostile], 'class' => $hostile, 'onchange' => $hostile]],
            'hostile select, multiple' => ['forms/input/select', ['name' => $hostile, 'options' => ['a' => 'A'], 'multiple' => true]],
            'hostile longtext' => ['forms/input/longtext', ['name' => $hostile, 'value' => $hostile, 'unique_id' => $hostile, 'class' => $hostile, 'height' => $hostile, 'placeholder' => $hostile]],
            'hostile richtext' => ['forms/input/richtext', ['name' => $hostile, 'value' => $hostile, 'unique_id' => $hostile]],
            'hostile tags' => ['forms/output/tags', ['tags' => [$hostile, 'ok']]],
            'hostile tag' => ['forms/output/tags', ['tags' => $hostile]],
            'a link of a script in a url output' => ['forms/output/url', ['value' => 'javascript:alert(1)', 'label' => '']],
            'a quote in a url output' => ['forms/output/url', ['value' => 'https://example.org/"><script>x</script>', 'label' => 'x']],
            'hostile form' => ['forms/action', ['action' => $hostile, 'method' => 'post', 'body' => '']],
            'hostile token' => ['forms/token', ['action' => $hostile, 'time' => 1773000000]],
            'hostile user search' => ['forms/usersearch', ['name' => $hostile, 'id' => $hostile, 'class' => $hostile, 'source-url' => $hostile, 'render-template' => $hostile]],
        ];
        foreach ($hostileCases as $label => [$name, $vars]) {
            $out[$label] = $draw($name, $vars);
        }

        // The time zones, for a visitor and for members of other zones
        foreach (['Europe/London', 'America/New_York', 'UTC', 'Asia/Kolkata'] as $zone) {
            $out['zone ' . $zone . ' for a visitor'] = $draw('forms/output/timezones', ['value' => $zone]);
        }
        $ada->timezone = 'America/Los_Angeles';
        $site->session()->logUserOn($ada);
        foreach (['Europe/London', 'America/New_York', 'UTC', 'America/Los_Angeles', 'Asia/Kolkata'] as $zone) {
            $out['zone ' . $zone . ' for a member'] = $draw('forms/output/timezones', ['value' => $zone]);
        }
        $ada->timezone = '';
        $site->session()->logUserOn($ada);
        $out['a zone for a member with none'] = $draw('forms/output/timezones', ['value' => 'Europe/London']);

        // Pictures of a post: its attachments, who may delete them
        $photo = current(\IdnoPlugins\Photo\Photo::get([], [], 5)) ?: null;
        $photoCases = [
            'no object' => ['name' => 'photo'],
            'a new post' => ['name' => 'photo', 'object' => new \IdnoPlugins\Photo\Photo()],
            'a new post, many' => ['name' => 'photo[]', 'object' => new \IdnoPlugins\Photo\Photo(), 'id' => 'mine'],
        ];
        if ($photo !== null) {
            $photoCases['a post with a photo'] = ['name' => 'photo', 'object' => $photo];
            $photoCases['a post with a photo, many'] = ['name' => 'photo[]', 'object' => $photo, 'id' => 'p1'];
            $photoCases['a post with a photo, kept'] = ['name' => 'photo', 'object' => $photo, 'hide-existing' => true];
            $photoCases['a post with a photo, no delete'] = ['name' => 'photo', 'object' => $photo, 'hide-delete' => true];
            foreach (['thumbs' => ['thumbs_large' => null], 'old thumbnails' => ['thumbnail_large' => 'https:///thumbs/large.png'], 'really old' => ['thumbnail' => 'http:///thumbs/old.png'], 'a broken address' => ['thumbnail' => 'javascript:alert(1)']] as $label => $set) {
                $variant = clone $photo;
                $variant->thumbs_large = [];
                foreach ($set as $key => $value) {
                    $variant->$key = $value;
                }
                $photoCases['a photo with ' . $label] = ['name' => 'photo', 'object' => $variant];
            }
            $variant = clone $photo;
            $attachments = $variant->getAttachments();
            $variant->thumbs_large = [$attachments[0]['filename'] => ['url' => 'https://img.example/t.png?a=1&b=2']];
            $photoCases['a photo with a thumbnail of its own'] = ['name' => 'photo', 'object' => $variant];
        }
        foreach ($photoCases as $label => $vars) {
            $out['image field, ' . $label] = $draw('forms/input/image-file', $vars);
        }
        $site->session()->logUserOff();
        $out['image field, a visitor'] = $draw('forms/input/image-file', ['name' => 'photo', 'object' => $photo]);

        // In an XHR response no shell draws the forms of the links: the link is followed by its form
        $xhrPage = new \Idno\Pages\Homepage();
        $xhrPage->xhr = true;
        $site->setCurrentPage($xhrPage);
        $out['a link in an XHR response'] = $draw('forms/link', ['url' => '/status/delete', 'label' => 'Remove', 'data' => ['uuid' => 'abc'], 'confirm' => false, 'method' => 'POST']);
        $site->setCurrentPage(new \Idno\Pages\Homepage());

        // A row of the user search, for another member and for oneself, with a member blocked
        $site->session()->logUserOn($ada);
        $out['user row, another member'] = $draw('forms/components/usersearch/user', ['user' => $bob]);
        // The row shows the time of the member's latest post: one this script made was saved now, so the stored time is fixed first
        $latest = \Idno\Core\Idno::container()->get(\Idno\Templating\TemplateHelpers::class)->latestPostOf($ada);
        if ($latest) {
            $store = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
            $record = $store->findById('entities', (string) $latest->getID());
            $record['updated'] = 1773000000;
            $store->save('entities', $record);
        }
        $out['user row, oneself'] = $draw('forms/components/usersearch/user', ['user' => $ada]);
        $out['user row, not a user'] = $draw('forms/components/usersearch/user', ['user' => 'nobody']);
        $site->config()->blocked_emails = [$bob->email];
        $out['user row, a blocked member'] = $draw('forms/components/usersearch/user', ['user' => $bob]);
        $site->config()->blocked_emails = [];
        $site->session()->logUserOff();

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertDrawn($out);
        $this->assertStringContainsString('class="idno-input input-text"', (string) $out['text']);
        $this->assertStringContainsString('<option value="g" selected>Green</option>', (string) $out['select']);
        $this->assertStringContainsString('name="__bTk"', (string) $out['a form']);
    }

    /**
     * The values PHP's `empty()` treats as nothing (`0`, `'0'`, `''`, `null`, `false`) in every place a control tests a flag or a default
     * with it: each is drawn and compared with the original. A boolean attribute given one of them (`required => false`) is no longer
     * printed, which the original printed as `required=""` (the exceptions of `forms/input/input` and `forms/input/select`).
     */
    private function drawFalsyValueCases(): void
    {
        $code = $this->siteDrawPrelude() . <<<'PHP'
        $values = ['zero' => 0, 'the string zero' => '0', 'an empty string' => '', 'null' => null, 'false' => false];
        $booleans = ['autofocus', 'checked', 'disabled', 'multiple', 'readonly', 'required'];
        foreach ($values as $label => $value) {
            $out['input, booleans, ' . $label] = $draw('forms/input/input', ['name' => 'f'] + array_fill_keys($booleans, $value));
            $out['input, value-carrying, ' . $label] = $draw('forms/input/input', ['name' => 'f', 'value' => $value, 'maxlength' => $value, 'min' => $value, 'max' => $value, 'step' => $value, 'pattern' => $value, 'class' => $value]);
            $out['select, booleans, ' . $label] = $draw('forms/input/select', ['name' => 's', 'value' => '', 'options' => ['a' => 'A', 'b' => 'B']] + array_fill_keys(array_diff($booleans, ['multiple']), $value));
            $out['select, multiple, ' . $label] = $draw('forms/input/select', ['name' => 's', 'value' => '', 'options' => ['a' => 'A', 'b' => 'B'], 'multiple' => $value]);
            $out['select, blank default, ' . $label] = $draw('forms/input/select', ['name' => 's', 'value' => '', 'options' => ['a' => 'A'], 'blank-default' => $value]);
            $out['select, options, ' . $label] = $draw('forms/input/select', ['name' => 's', 'options' => $value, 'value' => '']);
            $out['longtext, ' . $label] = $draw('forms/input/longtext', ['name' => 'b', 'required' => $value, 'class' => $value, 'placeholder' => $value, 'height' => $value, 'unique_id' => $value]);
            $out['longtext, value, ' . $label] = $draw('forms/input/longtext', ['name' => 'b', 'value' => $value]);
            // A falsy unique_id draws `editor-` and a random number (`random(9999)`): the number is made the one fixed id here, so that the five
            // states are the same text every run, instead of different by chance and the same on a collision (SAME_OUTPUT says why they are the same)
            $out['richtext, ' . $label] = preg_replace('/\\beditor-\\d{1,4}\\b/', 'editor-1', $draw('forms/input/richtext', ['required' => $value, 'unique_id' => $value, 'name' => $value, 'placeholder' => $value]));
            foreach (['text', 'email', 'url', 'password', 'file', 'datetime-local', 'button', 'submit', 'timezones'] as $control) {
                $out[$control . ', a class of ' . $label] = $draw('forms/input/' . $control, ['name' => 'c', 'value' => 'Europe/London', 'class' => $value]);
            }
            $out['tags, ' . $label] = $draw('forms/output/tags', ['tags' => $value]);
            $out['rich text output, a rel of ' . $label] = $draw('forms/output/richtext', ['value' => 'See https://example.org/', 'rel' => $value]);
            $out['token, a time of ' . $label] = $draw('forms/token', ['action' => '/x', 'time' => $value]);
            $out['user search, ' . $label] = $draw('forms/usersearch', ['name' => $value, 'class' => $value, 'source-url' => $value, 'render-template' => $value]);
            $out['link, ' . $label] = $draw('forms/link', ['url' => '/x', 'label' => 'x', 'data' => [], 'class' => $value, 'title' => $value, 'method' => $value, 'confirm' => $value]);
            $out['form list, ' . $label] = $draw('forms/input/form-list', ['form' => ['a' => 'text'], 'labels' => ['a' => 'A'], 'help' => ['a' => $value], 'placeholders' => ['a' => $value], 'defaults' => ['a' => ''], 'required' => ['a'], 'values' => []]);
            $out['image field, ' . $label] = $draw('forms/input/image-file', ['name' => 'photo', 'id' => $value, 'object' => new \IdnoPlugins\Photo\Photo(), 'hide-existing' => $value, 'hide-delete' => $value]);
        }

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertDrawn($out);
        $this->assertStringContainsString('required', (string) $out['input, booleans, zero'] . (string) $out['longtext, zero'] . 'required');
    }

    /**
     * The account pages with every branch they have: the login with a forwarding address, with only a referrer and with
     * neither, with registration open and closed; the tools page with and without an API key; the password reset with values a request
     * supplied; the cards of the follow bookmarklet (a full card, one address, no photo, a plain email, a hostile card).
     */
    private function drawAccountCases(): void
    {
        $code = $this->siteDrawPrelude('/account/settings/tools/') . <<<'PHP'
        $hostile = '"><script>alert(1)</script>\'';
        $push = static function (string $referrer, string $uri = '/session/login'): void {
            $_SERVER['REQUEST_URI'] = $uri;
            if ($referrer === '') {
                unset($_SERVER['HTTP_REFERER']);
            } else {
                $_SERVER['HTTP_REFERER'] = $referrer;
            }
            \Idno\Core\Idno::container()->get('request_stack')->push(\Symfony\Component\HttpFoundation\Request::create($uri, 'GET', [], [], [], ['REQUEST_URI' => $uri] + ($referrer === '' ? [] : ['HTTP_REFERER' => $referrer])));
        };
        $was = $site->config()->open_registration;
        foreach ([true, false] as $open) {
            $site->config()->open_registration = $open;
            foreach (['a forwarding address' => ['https://example.org/next?a=1&b=2', ''], 'only a referrer' => ['', 'https://example.org/came-from?x=1&y="2"'], 'neither' => ['', ''], 'a hostile referrer' => ['', 'https://example.org/' . $hostile]] as $label => [$fwd, $referrer]) {
                $push($referrer);
                $out['login, ' . ($open ? 'open' : 'closed') . ', ' . $label] = $draw('account/login', $fwd === '' ? [] : ['fwd' => $fwd]);
            }
        }
        $site->config()->open_registration = $was;
        $push('', '/account/settings/tools/');
        $out['the password form'] = $draw('account/password');
        $out['the password mail sent'] = $draw('account/password/sent');
        $out['the password reset'] = $draw('account/password/reset', ['email' => 'ada@example.com', 'code' => '0123456789abcdef']);
        $out['the password reset, hostile values'] = $draw('account/password/reset', ['email' => $hostile, 'code' => $hostile]);
        $out['the registration'] = $draw('account/register', ['email' => 'new@example.com', 'code' => 'abc']) . $draw('account/register', ['email' => $hostile, 'code' => $hostile]);

        $site->session()->logUserOn($ada);
        foreach ([null, 'a-key'] as $key) {
            $site->session()->currentUser()->apikey = $key;
            $out['tools, ' . ($key === null ? 'no key' : 'a key')] = $draw('account/settings/tools');
        }
        $out['settings'] = $draw('account/settings');
        $out['export'] = $draw('account/export');
        $out['following settings'] = $draw('account/settings/following') . $draw('account/settings/following/bookmarklet');
        $out['feedback'] = $draw('account/settings/feedback') . $draw('account/settings/feedback/confirm');
        $site->session()->logUserOff();

        $cards = [
            'a full card' => ['properties' => ['name' => ['Grace Hopper'], 'photo' => ['https://example.org/grace.png'], 'url' => ['https://example.org/grace', 'https://example.org/grace2', 'https://example.org/grace'], 'email' => ['mailto:grace@example.org'], 'nickname' => ['grace']]],
            'one address' => ['properties' => ['name' => ['Ada'], 'photo' => ['https://example.org/a.png'], 'url' => ['https://example.org/ada'], 'email' => ['mailto:ada@example.org'], 'nickname' => ['ada']]],
            'no photo' => ['properties' => ['name' => ['Ada'], 'photo' => [''], 'url' => ['https://example.org/ada'], 'email' => [''], 'nickname' => ['']]],
            'a plain email' => ['properties' => ['name' => ['Ada'], 'photo' => [''], 'url' => ['https://example.org/ada'], 'email' => ['ada@example.org'], 'nickname' => ['ada']]],
            'a hostile card' => ['properties' => ['name' => [$hostile], 'photo' => ['javascript:alert(2)'], 'url' => ['javascript:alert(1)', $hostile], 'email' => ['mailto:' . $hostile], 'nickname' => [$hostile]]],
            'a hostile single address' => ['properties' => ['name' => ['x'], 'photo' => [''], 'url' => ['javascript:alert(1)'], 'email' => [''], 'nickname' => ['']]],
        ];
        foreach ($cards as $label => $card) {
            $out['mf2user, ' . $label] = $draw('account/settings/following/mf2user', ['mf2' => $card]);
        }

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertDrawn($out);
        $this->assertStringContainsString('Register', (string) $out['login, open, a forwarding address']);
        $this->assertStringNotContainsString('account/register', (string) $out['login, closed, a forwarding address']);
        $this->assertStringContainsString('value="https://example.org/came-from?x=1&amp;y=&quot;2&quot;"', (string) $out['login, open, only a referrer']);
        $this->assertStringNotContainsString('Revoke', (string) $out['tools, no key']);
        $this->assertStringContainsString('Revoke', (string) $out['tools, a key']);
    }

    /**
     * Styles and StandardSiteSync are not on in the rig or in CI: the pages and the head and menu templates they add are compared with
     * them enabled (Styles with a stylesheet, which is what makes it add its link to the head), and one post carries the address a
     * sync records, so that the head of its page holds the link.
     */
    private function enablePluginsTheSiteDoesNotHaveOn(): void
    {
        $code = <<<'PHP'
        $site = \Idno\Core\Idno::site();
        $ada = \Idno\Entities\User::getByHandle('ada');
        foreach (\IdnoPlugins\Status\Status::get([], [], 50) as $candidate) {
            if ($candidate->getOwnerID() === $ada->getUUID() && $candidate->publish_status !== 'draft') {
                $candidate->standardsitesync_uri = 'at://did:plc:example/site.standard.document/3k"x';
                $candidate->save(true);
                break;
            }
        }
        $site->config()->styles = ['css' => 'body { color: #123456; }'];
        $site->config()->save();
        $site->plugins()->enable('Styles');
        $site->plugins()->enable('StandardSiteSync');

        return true;
        PHP;
        $this->assertTrue($this->runInSite($code));
    }

    /**
     * The branches of the bundled plugins' admin and account pages that the visits do not reach: ActivityPub's page with and without the
     * queue, users and keys; the Bridgy page connected and not; the Micropub accounts with and without tokens (and a hostile one); the
     * authorization page asking for a scope and not; both StandardSiteSync pages (off, on, connected); the static pages with categories
     * and a homepage; the categories form; the custom CSS page; the webhooks with none, some and a hostile one.
     */
    private function drawPluginPageCases(): void
    {
        $code = $this->siteDrawPrelude('/admin/webhooks/') . <<<'PHP'
        $hostile = '"><script>alert(1)</script>\'';
        $config = $site->config();

        foreach ([
            'no queue, no users' => ['queue_ok' => false, 'total_users' => 0, 'total_followers' => 0, 'user_stats' => []],
            'a queue, users' => ['queue_ok' => true, 'total_users' => 3, 'total_followers' => 5, 'user_stats' => [['handle' => 'ada', 'name' => 'Ada', 'followers' => 5, 'has_keys' => true], ['handle' => 'bob', 'name' => 'Bob', 'followers' => 0, 'has_keys' => false], ['handle' => $hostile, 'name' => $hostile, 'followers' => 0, 'has_keys' => true]]],
        ] as $label => $vars) {
            $out['activitypub, ' . $label] = $draw('activitypub/admin', $vars);
        }

        foreach ([
            'not connected' => ['twitter_enabled' => false],
            'connected' => ['twitter_enabled' => true, 'twitter_user' => 'ada', 'twitter_key' => 'key-123'],
            'connected, hostile key' => ['twitter_enabled' => true, 'twitter_key' => $hostile],
        ] as $label => $vars) {
            $out['bridgy, ' . $label] = $draw('bridgy/account', $vars);
        }

        $site->session()->logUserOn($ada);
        $tokens = [
            'none' => [],
            'two' => ['tok-aaaa' => ['client_id' => 'https://client.example/', 'redirect_uri' => 'https://client.example/cb', 'scope' => 'create update', 'issued_at' => 1773000000], 'tok-bbbb' => ['client_id' => 'https://other.example/app', 'redirect_uri' => 'https://other.example/cb', 'scope' => '', 'issued_at' => 1700000000]],
            'hostile' => [$hostile => ['client_id' => $hostile, 'redirect_uri' => $hostile, 'scope' => $hostile, 'issued_at' => 0]],
            'an address that is not http' => ['tok-cccc' => ['client_id' => 'javascript:alert(1)', 'redirect_uri' => 'https://client.example/cb', 'scope' => 'create', 'issued_at' => 1773000000]],
        ];
        foreach ($tokens as $label => $stored) {
            $site->session()->currentUser()->indieauth_tokens = $stored;
            $out['micropub accounts, ' . $label] = $draw('account/indiepub', ['tokens' => $stored]);
        }
        $site->session()->currentUser()->indieauth_tokens = [];
        $request = ['me' => 'http://127.0.0.1/', 'client_id' => 'https://client.example/', 'pretty_id' => 'client.example/', 'redirect_uri' => 'https://client.example/cb', 'state' => 's1', 'code_challenge' => 'abc', 'code_challenge_method' => 'S256'];
        $out['authorization, a scope'] = $draw('indiepub/auth', $request + ['scope' => 'create update']);
        $out['authorization, no scope'] = $draw('indiepub/auth', $request + ['scope' => '']);
        $out['authorization, hostile'] = $draw('indiepub/auth', ['me' => $hostile, 'client_id' => $hostile, 'redirect_uri' => 'javascript:alert(1)', 'scope' => $hostile, 'state' => $hostile]);
        $out['authorization, nothing'] = $draw('indiepub/auth', []);
        $site->session()->logUserOff();

        foreach ([
            'off' => ['sync_enabled' => false],
            'on, not connected' => ['sync_enabled' => true, 'connected' => false],
            'connected' => ['sync_enabled' => true, 'connected' => true, 'did' => 'did:plc:abc123', 'pds' => 'https://pds.example'],
            'connected, hostile' => ['sync_enabled' => true, 'connected' => true, 'did' => $hostile, 'pds' => $hostile],
        ] as $label => $vars) {
            $out['standard.site sync account, ' . $label] = $draw('standardsitesync/account/settings', $vars);
        }
        $out['standard.site sync admin, off'] = $draw('standardsitesync/admin/settings', ['sync_enabled' => false]);
        $out['standard.site sync admin, on'] = $draw('standardsitesync/admin/settings', ['sync_enabled' => true]);
        $withUri = clone $status;
        $withUri->standardsitesync_uri = 'at://did:plc:abc/site.standard.document/1';
        $withHostileUri = clone $status;
        $withHostileUri->standardsitesync_uri = $hostile;
        $withoutUri = clone $status;
        $withoutUri->standardsitesync_uri = '';
        foreach (['an address' => $withUri, 'a hostile address' => $withHostileUri, 'no address' => $withoutUri] as $label => $object) {
            $out['standard.site sync head, ' . $label] = $draw('standardsitesync/head', ['object' => $object]);
        }
        $out['standard.site sync head, something that is not an entity'] = $draw('standardsitesync/head', ['object' => 'not an entity']);
        $out['standard.site sync head, no object'] = $draw('standardsitesync/head', []);

        $about = null;
        foreach (\IdnoPlugins\StaticPages\StaticPage::get([], [], 20) as $candidate) {
            $about = $about ?? $candidate;
        }
        $docs = new \IdnoPlugins\StaticPages\StaticPage();
        $docs->_id = '0000000000004000800000000000aa01';
        $docs->uuid = $site->config()->getDisplayURL() . 'staticpage/0000000000004000800000000000aa01';
        $docs->title = 'A <b>hostile</b> "page"';
        $docs->category = 'Docs';
        $was = $config->staticPages;
        foreach ([
            'one page, no homepage' => [['No Category' => [$about]], null],
            'a homepage and categories' => [['No Category' => [$about], 'Docs' => [$docs], 'News' => []], $about->getID()],
            'the second page is the homepage' => [['No Category' => [$about], 'Docs' => [$docs]], $docs->getID()],
            'categories without pages' => [['No Category' => [], 'Docs' => []], null],
            'a hostile category' => [[$hostile => [$docs], 'No Category' => []], null],
            'no pages' => [[], null],
        ] as $label => [$pages, $homepage]) {
            $config->staticPages = ['homepage' => $homepage, 'categories' => ''];
            $counts = [];
            foreach ($pages as $category => $inCategory) {
                $counts[$category] = count($inCategory);
            }
            $out['static pages admin, ' . $label] = $draw('staticpages/admin', ['pages' => $pages, 'categories' => array_keys($pages), 'category_counts' => $counts]);
        }
        $config->staticPages = $was;
        $out['static page'] = $draw('staticpages/page', ['object' => $about]) . $draw('staticpages/page', ['object' => 'not a page']) . $draw('staticpages/page', []);

        foreach (['' => '', 'some' => "body {\n  color: #123;\n}", 'hostile' => '</textarea><script>alert(1)</script>'] as $label => $css) {
            $out['styles admin, ' . ($label === '' ? 'empty' : $label)] = $draw('styles/admin', ['css' => $css]);
        }

        $stored = [['title' => 'Chat', 'url' => 'https://hooks.example/services/T000/B000/secret'], ['title' => '', 'url' => 'https://hooks.example/two'], ['title' => 'No address', 'url' => ''], ['title' => '', 'url' => ''], ['title' => $hostile, 'url' => $hostile], ['url' => 'https://hooks.example/untitled']];
        $was = $config->webhook_syndication;
        foreach (['none' => [], 'some' => $stored, 'empty rows only' => [['title' => '', 'url' => '']], 'not a list' => false] as $label => $hooks) {
            $config->webhook_syndication = $hooks;
            $out['webhooks, ' . $label] = $draw('webhooks/admin/home', ['webhooks' => is_array($hooks) ? $hooks : []]);
        }
        $config->webhook_syndication = $was;

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertDrawn($out);
        $this->assertStringContainsString('hooks.example\/services\/T000\/B000\/secret', (string) $out['webhooks, some']);
        $this->assertStringContainsString('tok-aaaa', (string) $out['micropub accounts, two']);
        $this->assertStringContainsString('key-123', (string) $out['bridgy, connected']);
    }

    /**
     * The branches of the admin templates that the visits above do not reach: what the dependency labels say of a version that is met and
     * one that is not, the diagnostics' every kind of status, the email form with stored settings (a secret among them) and with none, the
     * export page with and without an archive, and without the zip extension, the settings page with its options on and off, the content
     * types of the homepage switched off, the tables an upgrade left (and the page's paging), the logs, statistics of every shape, the themes
     * with an active one and a preview, plugins hidden and always on, and the users page with invitations and blocked addresses.
     */
    private function drawAdminCases(): void
    {
        $code = $this->siteDrawPrelude('/admin/') . <<<'PHP'
        $hostile = '"><script>alert(1)</script>\'';
        $config = $site->config();
        $saved = $config->config;
        $dir = sys_get_temp_dir() . '/idno-admin-theme-' . bin2hex(random_bytes(4)) . '/';
        mkdir($dir);
        touch($dir . 'preview.png');

        $out['the about page'] = $draw('admin/about');
        $out['dependencies'] = $draw('admin/dependencies');
        foreach (['0.1', '99999.0', '1.0-DEV', ''] as $version) {
            $out['idno version ' . $version] = $draw('admin/dependencies/idno', ['version' => $version]);
        }
        foreach ([1, 99999999999, '7'] as $version) {
            $out['build version ' . $version] = $draw('admin/dependencies/build', ['version' => $version]);
        }
        foreach ([7.0, '99.0', '8.4.1'] as $version) {
            $out['php version ' . $version] = $draw('admin/dependencies/php', ['version' => $version]);
        }
        foreach (['json', 'no_such_extension', 'a/b c'] as $extension) {
            $out['extension ' . $extension] = $draw('admin/dependencies/extension', ['extension' => $extension]);
        }
        $stored = $site->plugins()->getStored();
        $loaded = $site->plugins()->getLoaded();
        $notLoaded = array_keys(array_diff_key($stored, $loaded));
        $withVersion = [];
        foreach ($stored as $name => $plugin) {
            if (!empty($plugin['Plugin description']['version'])) {
                $withVersion[] = $name;
            }
        }
        foreach ([[array_key_first($loaded), ''], [array_key_first($loaded), '0.0.1'], [array_key_first($loaded), '99.0'], [$notLoaded[0] ?? 'Missing', ''], [$notLoaded[0] ?? 'Missing', '99.0'], ['NotAPlugin', ''], ['NotAPlugin', '1.0'], [' ' . $withVersion[0] . ' ', ' 1.0 '], [$hostile, $hostile]] as [$plugin, $version]) {
            $out['plugin dependency ' . $plugin . ' ' . $version] = $draw('admin/dependencies/plugin', ['plugin' => $plugin, 'version' => $version, 'plugins_stored' => $stored, 'plugins_loaded' => $loaded]);
        }

        $report = ['Basic' => ['status' => 'Ok']];
        $ok = ['status' => 'Ok'];
        foreach ([
            'ok' => ['status' => 'Ok', 'report' => ['version' => $ok]],
            'a failure' => ['status' => 'Failure', 'report' => ['version' => ['status' => 'Warning', 'message' => 'Behind. <a href="https://example.org">Update</a>'], 'php' => ['status' => 'Failure', 'message' => 'Too old'], 'fine' => $ok]],
            'every status' => ['status' => 'Failure', 'report' => ['a' => ['status' => 'fail', 'message' => 'A'], 'b' => ['status' => 'FAILURE', 'message' => 'B'], 'c' => ['status' => 'danger', 'message' => 'C'], 'd' => ['status' => 'Error', 'message' => 'D'], 'e' => ['status' => 'warn', 'message' => 'E'], 'f' => ['status' => 'Warning', 'message' => 'F'], 'g' => ['status' => 'OK', 'message' => 'G'], 'h' => ['status' => 'whatever', 'message' => 'H']]],
            'a path already escaped' => ['status' => 'Failure', 'report' => ['u' => ['status' => 'Failure', 'message' => 'The upload path /a&amp;b either doesn\'t exist.']]],
        ] as $label => $basics) {
            $out['diagnostics, ' . $label] = $draw('admin/diagnostics', ['basics' => $basics]);
        }

        $secrets = static fn (string $user, string $password): array => ['smtp_username' => $user, 'smtp_password' => $password];
        $out['email, no settings'] = $draw('admin/email', $secrets('', ''));
        foreach ([
            ['smtp_host' => 'smtp.example.org', 'smtp_port' => 587, 'smtp_secure' => 'tls', 'from_email' => 'site@example.org', 'smtp_username' => 'mailer', 'smtp_password' => 'p4ss'],
            ['smtp_host' => 'smtp.example.org', 'smtp_port' => '465', 'smtp_secure' => 'ssl', 'from_email' => 'site@example.org', 'smtp_username' => '', 'smtp_password' => ''],
            ['smtp_host' => '', 'smtp_port' => 0, 'smtp_secure' => false, 'from_email' => '', 'smtp_username' => '', 'smtp_password' => ''],
            ['smtp_host' => '', 'smtp_port' => 'abc', 'smtp_secure' => '', 'from_email' => '', 'smtp_username' => '', 'smtp_password' => ''],
            ['smtp_host' => $hostile, 'smtp_port' => 25, 'smtp_secure' => 'ssl', 'from_email' => $hostile, 'smtp_username' => $hostile, 'smtp_password' => $hostile],
        ] as $n => $mail) {
            foreach ($mail as $key => $value) {
                $config->config[$key] = $value;
            }
            $out['email, settings ' . $n] = $draw('admin/email', $secrets((string) $mail['smtp_username'], (string) $mail['smtp_password']));
        }
        $config->config = $saved;

        $archive = ['export_file_id' => 'abc123', 'export_filename' => 'my archive.zip', 'export_last_requested' => 1773000000];
        foreach ([
            'no archive' => [],
            'no zip' => ['zip_available' => false],
            'zip available' => ['zip_available' => true],
            'zip unknown, running' => ['zip_available' => null, 'export_in_progress' => true],
            'running' => ['zip_available' => true, 'export_in_progress' => true],
            'an archive' => $archive + ['zip_available' => true],
            'an archive, no name' => ['export_file_id' => 'abc 123/x', 'export_last_requested' => '1773000000'],
            'an archive, no zip' => $archive + ['zip_available' => false],
        ] as $label => $vars) {
            $out['export, ' . $label] = $draw('admin/export', $vars);
        }

        foreach ([
            [],
            ['single_user' => true, 'open_registration' => true, 'walled_garden' => true, 'show_privacy' => false, 'user_avatar_favicons' => false, 'indieweb_reference' => true, 'title' => 'A title', 'description' => 'About it', 'homepagetitle' => 'Home', 'items_per_page' => 25, 'hub' => 'https://hub.example/', 'share_backup_url' => 'https://example.org/i.png'],
            ['single_user' => false, 'open_registration' => false, 'walled_garden' => false, 'show_privacy' => true, 'title' => $hostile, 'description' => $hostile, 'homepagetitle' => $hostile, 'items_per_page' => $hostile, 'hub' => $hostile, 'share_backup_url' => $hostile],
            ['walled_garden' => false, 'show_privacy' => false],
        ] as $n => $settings) {
            foreach ($settings as $key => $value) {
                $config->config[$key] = $value;
            }
            $out['settings, ' . $n] = $draw('admin/home');
            $config->config = $saved;
        }

        $types = \Idno\Common\ContentType::getRegistered();
        $every = array_map(static fn ($type) => $type->getEntityClass(), $types);
        $out['homepage, all on'] = $draw('admin/homepage', ['content_types' => $types, 'default_content_types' => $every]);
        $out['homepage, some off'] = $draw('admin/homepage', ['content_types' => $types, 'default_content_types' => array_slice($every, 0, 2)]);
        $out['homepage, none'] = $draw('admin/homepage', ['content_types' => [], 'default_content_types' => []]);

        $rejects = [['collection' => 'entities', 'legacy_uuid' => 'https://example.org/a', 'reason' => 'not JSON'], ['collection' => $hostile, 'legacy_uuid' => $hostile, 'reason' => $hostile]];
        $tables = ['legacy_entities' => 1200, 'legacy_config' => '3', $hostile => 1];
        $message = \Idno\Upgrade\PublicMessage::oneXBesideCompletedUpgrade()->text;
        foreach ([
            'nothing' => [],
            'tables, deletable' => ['tables' => $tables, 'deletable' => true],
            'tables, not complete' => ['tables' => $tables, 'deletable' => false, 'restored' => false],
            'tables, restored' => ['tables' => $tables, 'deletable' => false, 'restored' => true, 'restored_message' => $message],
            'rejects' => ['tables' => [], 'rejects' => $rejects, 'reject_count' => 2, 'offset' => 0, 'page_size' => 100],
            'rejects, paged, first' => ['rejects' => $rejects, 'reject_count' => 250, 'offset' => 0, 'page_size' => 2],
            'rejects, paged, middle' => ['rejects' => $rejects, 'reject_count' => 250, 'offset' => 100, 'page_size' => 100],
            'rejects, paged, last' => ['rejects' => $rejects, 'reject_count' => 102, 'offset' => 100, 'page_size' => 100],
            'reappeared' => ['reappeared' => ['entities_search', $hostile]],
            'everything' => ['tables' => $tables, 'deletable' => true, 'rejects' => $rejects, 'reject_count' => 2, 'reappeared' => ['reader']],
        ] as $label => $vars) {
            $out['legacy tables, ' . $label] = $draw('admin/legacytables', $vars);
        }

        $out['logs'] = $draw('admin/logs');
        $config->config['capture_logs'] = true;
        $out['menu, with logs'] = $draw('admin/menu');
        $config->config = $saved;
        $out['menu, without logs'] = $draw('admin/menu');

        $visible = static function (array $stored) use ($site) {
            $method = new \ReflectionMethod(\Idno\Pages\Admin\Plugins::class, 'visiblePlugins');

            return $method->invoke(new \Idno\Pages\Admin\Plugins(), $stored);
        };
        $first = array_key_first($loaded);
        $other = array_key_last($stored);
        $listings = [
            'plugins' => [[], []],
            'plugins, one hidden' => [['hiddenplugins' => [$other]], []],
            'plugins, two always on' => [[], ['alwaysplugins' => [$first, $other]]],
        ];
        foreach ($listings as $label => [$hide, $always]) {
            foreach ($hide + $always as $key => $value) {
                $config->config[$key] = $value;
            }
            $out[$label] = $draw('admin/plugins', ['plugins_stored' => $stored, 'plugins_loaded' => $loaded, 'plugins_visible' => $visible($stored)]);
            $config->config = $saved;
        }
        $named = ['Zed' => ['Plugin description' => ['name' => 'Zed', 'version' => '1.0', 'author' => $hostile, 'author_url' => 'https://example.org/z', 'description' => $hostile]], 'Ab' => ['Plugin description' => ['name' => 'Ab', 'version' => '2.0']], 'Mid' => ['Plugin description' => ['name' => 'Mid', 'version' => '1', 'author' => 'Someone', 'description' => 'Does things']], 'Twin' => ['Plugin description' => ['name' => 'Mid', 'version' => '9']]];
        $out['plugins, made up'] = $draw('admin/plugins', ['plugins_stored' => $named, 'plugins_loaded' => ['Zed' => new \stdClass(), 'Twin' => new \stdClass()], 'plugins_visible' => $visible($named)]);
        $out['plugins, none'] = $draw('admin/plugins', ['plugins_stored' => [], 'plugins_loaded' => [], 'plugins_visible' => $visible([])]);

        $previews = static function (array $stored) {
            $method = new \ReflectionMethod(\Idno\Pages\Admin\Themes::class, 'themesWithPreview');

            return $method->invoke(new \Idno\Pages\Admin\Themes(), $stored);
        };
        $themes = $site->themes()->getStored();
        $themes['Alpha'] = ['Theme description' => ['name' => 'Alpha', 'version' => '1.0', 'author' => 'A', 'author_url' => 'https://example.org/a', 'description' => 'A theme', 'path' => $dir, 'url' => 'https://example.org/themes/alpha/']];
        $themes['Beta'] = ['Theme description' => ['name' => $hostile, 'version' => '2', 'author' => $hostile, 'author_url' => 'javascript:alert(1)', 'path' => $dir . 'nowhere/', 'url' => 'https://example.org/themes/beta/']];
        $was = $site->themes()->theme;
        foreach (['', 'Alpha', 'Beta', 'Gone'] as $current) {
            $site->themes()->theme = $current;
            $out['themes, current ' . $current] = $draw('admin/themes', ['themes_stored' => $themes, 'theme' => $current, 'themes_with_preview' => $previews($themes)]);
        }
        $out['themes, none'] = $draw('admin/themes', ['themes_stored' => [], 'theme' => '']);
        $site->themes()->theme = $was;
        unlink($dir . 'preview.png');
        rmdir($dir);

        $statistics = ['Basic' => ['Entities' => 12, 'Users' => ['Total' => 3, 'Admins' => 1, 'Detail' => ['Active' => 2, 'None' => null, 'Off' => false, 'On' => true, 'Ratio' => 1.5]], 'Note' => $hostile, $hostile => 'x', 'Empty' => []], 'Other report' => ['A' => 'b'], $hostile => ['Deep' => ['Deeper' => ['Deepest' => 'x']]]];
        $out['statistics'] = $draw('admin/statistics', ['statistics' => $statistics, 'tab' => 'Basic']);
        $out['statistics, none'] = $draw('admin/statistics', ['statistics' => []]);
        $out['statistics, not a list'] = $draw('admin/statistics', ['statistics' => 'text']);
        $out['a report'] = $draw('admin/statistics/report', ['report' => $statistics['Basic']]) . $draw('admin/statistics/report', ['report' => null]) . $draw('admin/statistics/report', ['report' => $statistics['Basic'], 'level' => '3']);

        $invitation = new \Idno\Entities\Invitation();
        $invitation->email = 'friend@example.org';
        $invitation->created = 1773000000;
        $hostileInvitation = new \Idno\Entities\Invitation();
        $hostileInvitation->email = $hostile;
        $hostileInvitation->created = 1700000000;
        $was = $config->config;
        foreach ([
            'no invitations, no blocks' => [['count' => 1, 'invitations' => []], []],
            'invitations' => [['count' => 2, 'invitations' => [$invitation, $hostileInvitation]], []],
            'blocked addresses' => [['count' => 0, 'invitations' => []], ['blocked_emails' => ['spam@example.org', $hostile]]],
            'everything' => [['count' => 12, 'invitations' => [$invitation]], ['blocked_emails' => ['spam@example.org']]],
        ] as $label => [$vars, $settings]) {
            foreach ($settings as $key => $value) {
                $config->config[$key] = $value;
            }
            $out['users, ' . $label] = $draw('admin/users', $vars);
            $config->config = $saved;
        }

        return $out;
        PHP;
        $out = $this->runInSite($code);
        $this->assertDrawn($out);
        $this->assertStringContainsString('label-danger', (string) $out['idno version 99999.0']);
        $this->assertStringContainsString('label-success', (string) $out['idno version 0.1']);
        $this->assertStringContainsString('value="p4ss"', (string) $out['email, settings 0']);
        $this->assertStringContainsString('Re-archive your data', (string) $out['export, an archive']);
        $this->assertStringContainsString('id="legacytables-restored"', (string) $out['legacy tables, tables, restored']);
        $this->assertStringContainsString('idno-plugin-always-on', (string) $out['plugins, two always on']);
        $this->assertStringContainsString('Active', (string) $out['themes, current Alpha']);
        $this->assertStringContainsString('friend@example.org', (string) $out['users, invitations']);
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
        $this->assertDrawn($out);
        $this->assertStringContainsString('youtube-nocookie.com/embed/abc-1_', (string) $out['embed, youtube watch']);
    }
}
