<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Templating\PageNormaliser;
use Tests\Templating\RenderedSiteTestCase;

/**
 * Idno 2026 is the look every site has: a site with no theme draws the web pages that the golden files of
 * Tests/Templating/fixtures/pages/twenty26 hold, a site whose stored `theme` is the name the look had as a theme
 * (`Twenty26`) or a retired one draws them too, and what a third-party plugin or theme brings keeps working.
 *
 * Each case is a private site (a real web server on a private database) with the same content in every run.
 */
final class BuiltInLookTest extends RenderedSiteTestCase
{
    /** @var list<string> directories this test made under the project, removed in tearDown() */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $directory) {
            self::removeDirectory($directory);
        }
        $this->made = [];
        parent::tearDown();
    }

    /**
     * The site's environment: a theme pinned in the environment for the cases that need one, read when the site starts.
     *
     * @return array<string, string>
     */
    protected function siteEnvironment(): array
    {
        $theme = match ($this->getName(false)) {
            'testAPinnedRetiredThemeIsRenderedButNeverSavedOrAnnounced' => 'Solo',
            'testAPinnedMissingThemeFallsBackToTheBuiltInLookWithoutError' => 'ZzNoSuchTheme',
            'testAThirdPartyThemeKeepsItsOverridesAndExtensions' => 'ZzFixtureTheme',
            'testAThemeExtendingTheCoreShellReplacesOneBlock' => 'ZzShellTheme',
            'testAThirdPartyTplPhpOverrideOfAPortedTemplateStillWins' => 'ZzFooterTheme',
            default => '',
        };
        if ($theme === 'ZzFixtureTheme') {
            $this->makeFixtureTheme();
        }
        if ($theme === 'ZzShellTheme') {
            // A theme that extends core's own shell and replaces one block of it
            $this->makeTheme($theme, [
                'templates/default/shell.html.twig' => "{% extends '@core/default/shell.html.twig' %}{% block nav %}THEME NAV{% endblock %}",
            ]);
        }
        if ($theme === 'ZzFooterTheme') {
            // A theme with a PHP override of a template core now draws from Twig: it is given the page's variables and the template object
            $this->makeTheme($theme, [
                'templates/default/shell/footer.tpl.php' => '<!--zz-footer title=<?= htmlspecialchars($vars[\'title\']) ?> class=<?= get_class($this) ?>-->',
            ]);
        }

        return $theme === '' ? [] : ['IDNO_THEME' => $theme];
    }

    public function testNoThemeRendersTheTwenty26Golden(): void
    {
        $paths = $this->seedSite();

        $this->page('twenty26/home.html', '');
        $this->page('twenty26/permalink-status.html', $paths['status']);
        $this->page('twenty26/permalink-article.html', $paths['article']);
        $this->page('twenty26/login.html', 'session/login');
        $this->assertNoDifferences();
    }

    public function testTwenty26SettingRendersTheSameAndIsNeverSaved(): void
    {
        $paths = $this->seedSite();
        $this->setStoredTheme('Twenty26');
        $cookie = $this->loginAsAdmin();

        $this->page('twenty26/home.html', '');
        $this->page('twenty26/permalink-status.html', $paths['status']);
        $logged = $this->page('logged-in/home.html', '', 200, 'text/html', $cookie);
        $this->assertNoDifferences();

        $this->assertStringNotContainsString('has been retired', (string) $logged?->body, 'the administrator is told nothing');
        $this->assertSame('Twenty26', $this->storedTheme(), 'the setting is resolved on load and never saved');
    }

    public function testARetiredThemeIsSavedAsTheBuiltInLookAndAnnouncedOnce(): void
    {
        $this->seedSite();
        $this->setStoredTheme('Solo');
        $visitor = $this->site()->request('GET', '');
        $this->assertSame('Solo', $this->storedTheme('unset'), 'a visitor never writes the settings');
        $this->assertStringNotContainsString('has been retired', $visitor->body);

        $cookie = $this->loginWithoutLookingAtAPage();
        $announcements = 0;
        foreach (['', 'admin/', 'account/settings/', ''] as $path) {
            $announcements += substr_count($this->site()->request('GET', $path, ['Cookie' => $cookie])->body, 'The Solo theme has been retired. Your site now uses Idno 2026.');
        }

        $this->assertSame(1, $announcements, 'the administrator is told once');
        $this->assertSame('', $this->storedTheme('unset'), 'the setting is saved as no theme, which is the built-in look');
    }

    public function testAPinnedRetiredThemeIsRenderedButNeverSavedOrAnnounced(): void
    {
        $paths = $this->seedSite();
        $before = $this->storedTheme('unset');
        $cookie = $this->loginWithoutLookingAtAPage();

        $bodies = '';
        foreach (['', 'admin/', ''] as $path) {
            $bodies .= $this->site()->request('GET', $path, ['Cookie' => $cookie])->body;
        }
        $this->page('twenty26/home.html', '');
        $this->page('twenty26/permalink-status.html', $paths['status']);
        $this->assertNoDifferences();

        $this->assertStringNotContainsString('has been retired', $bodies);
        $this->assertSame($before, $this->storedTheme('unset'), 'nothing was saved (what the pinned value made the seeding store stays)');
    }

    public function testAPinnedMissingThemeFallsBackToTheBuiltInLookWithoutError(): void
    {
        $paths = $this->seedSite();

        $this->page('twenty26/home.html', '');
        $this->page('twenty26/permalink-article.html', $paths['article']);
        $this->assertNoDifferences();
    }

    public function testUnderscoreTModernIsDefault(): void
    {
        $this->seedSite();

        $this->assertRendersAs('twenty26/home.html', '', '?_t=modern');
        $this->assertRendersAs('twenty26/login.html', 'session/login', 'session/login?_t=modern');
        $this->assertNoDifferences();
    }

    public function testUnderscoreTClassicIsRefused(): void
    {
        $this->seedSite();

        $this->assertRendersAs('twenty26/home.html', '', '?_t=classic');
        $this->assertRendersAs('twenty26/home.html', '', '?_t=CLASSIC');
        $this->assertRendersAs('twenty26/home.html', '', '?_t=Classic');
        $this->assertRendersAs('twenty26/home.html', '', '?_t=DEFAULT');
        $this->assertNoDifferences();
    }

    public function testUnderscoreTTraversalIsRefused(): void
    {
        $this->seedSite();

        foreach (['../classic', '..%2Fclassic', 'classic/../default', '%2e%2e/classic', 'classic%00', 'classic/', 'CLASSIC/', 'classic%2F', 'default/', '/classic', '%2Fclassic', 'rss/'] as $type) {
            $this->assertRendersAs('twenty26/home.html', '', '?_t=' . $type);
        }
        $this->assertNoDifferences();
    }

    /**
     * The mail types are set by the mail sender, never by a request: a plain-text mail is not escaped, so a web page drawn from it
     * would print text as markup.
     */
    public function testAMailTypeCannotBeRequested(): void
    {
        $this->seedSite();

        foreach (['email', 'email-text', 'EMAIL-TEXT'] as $type) {
            $this->assertRendersAs('twenty26/home.html', '', '?_t=' . $type);
        }
        $this->assertNoDifferences();
    }

    /**
     * The page at $path is the page at $requested, which carries a template type in its query: they differ only in where
     * the page names its own address (the JSON-LD `url`), which has the query in it. The page is compared with its golden.
     */
    private function assertRendersAs(string $golden, string $path, string $requested): void
    {
        $plain = $this->site()->request('GET', $path);
        $other = $this->site()->request('GET', $requested);
        $this->assertSame(200, $other->status, $requested);
        $this->assertStringStartsWith('text/html', strtolower((string) $other->header('Content-Type')), $requested);
        // The page names the query it was asked with: its own address and its pagination links carry `_t=<the type>`.
        $type = rawurldecode(substr($requested, (int) strpos($requested, '_t=') + 3));
        $stripped = $other->body;
        foreach (array_unique([substr($requested, (int) strpos($requested, '_t=') + 3), $type, rawurlencode($type), urlencode($type)]) as $form) {
            $stripped = (string) preg_replace('/(?<=[?&])_t=' . preg_quote($form, '/') . '(?:&amp;|&)?/', '', $stripped);
        }
        $stripped = (string) preg_replace('/\?(?=["\'])/', '', $stripped);
        $this->assertSame(PageNormaliser::normalise($plain->body, $this->baseUrl(), true), PageNormaliser::normalise($stripped, $this->baseUrl(), true), $requested . ' is not the page at ' . $path);
        $this->golden($golden, $stripped, true);
    }

    /**
     * A third-party plugin written for the Twenty26 theme keeps its `templates/modern/` files for one release: they win on
     * web pages, and the first one drawn in a request logs one deprecation line that names it.
     */
    public function testAThirdPartyModernTemplateStillWinsAndLogsOneDeprecation(): void
    {
        $this->seedSite();
        $plugin = dirname(__DIR__, 2) . '/IdnoPlugins/ZzModernProbe';
        $this->made[] = $plugin;
        mkdir($plugin . '/templates/modern/shell', 0777, true);
        file_put_contents($plugin . '/plugin.ini', "[Plugin description]\nname = 'Zz modern probe'\nversion = '1.0'\n");
        file_put_contents($plugin . '/Main.php', "<?php\nnamespace IdnoPlugins\\ZzModernProbe {\n    class Main extends \\Idno\\Common\\Plugin\n    {\n    }\n}\n");
        file_put_contents($plugin . '/templates/modern/shell/pagination.tpl.php', '<!--zz-modern-pagination-->');
        $this->assertTrue($this->runInSite('return \\Idno\\Core\\Idno::site()->plugins()->enable("ZzModernProbe");'));

        $before = substr_count($this->serverLogContents(), 'modern/shell/pagination.tpl.php');
        $first = $this->site()->request('GET', '?offset=10');
        $second = $this->site()->request('GET', '?offset=10');
        $lines = substr_count($this->serverLogContents(), 'modern/shell/pagination.tpl.php') - $before;

        $this->assertSame(200, $first->status);
        $this->assertStringContainsString('<!--zz-modern-pagination-->', $first->body, 'the modern file wins over core\'s default one');
        $this->assertStringContainsString('<!--zz-modern-pagination-->', $second->body);
        $this->assertSame(2, $lines, 'one line for each of the two requests, not one for each draw');
        $this->assertMatchesRegularExpression('/Deprecated: .*IdnoPlugins\/ZzModernProbe\/templates\/modern\/shell\/pagination\.tpl\.php/', $this->serverLogContents());

        $rss = $this->site()->request('GET', '?_t=rss&offset=10');
        $this->assertStringNotContainsString('zz-modern', $rss->body, 'a feed never draws a modern file');
    }

    /**
     * A theme from Themes/ keeps working: its `default/` override wins over core's file, and what its `theme.ini` puts under
     * `[extensions]` is drawn after the template it extends.
     */
    public function testAThirdPartyThemeKeepsItsOverridesAndExtensions(): void
    {
        $this->seedSite();

        $response = $this->site()->request('GET', '?offset=10');

        $this->assertSame(200, $response->status, substr($response->body, 0, 400) . $this->serverLog());
        $this->assertStringContainsString('<!--zz-theme-pagination-->', $response->body, 'the theme\'s default/shell/pagination replaces core\'s');
        $this->assertStringContainsString('<!--zz-theme-extension-->', $response->body, 'its [extensions] are drawn');
        $this->assertStringContainsString('class="idno-', $response->body, 'the rest of the page is the built-in look');
    }

    /**
     * A theme can extend core's own shell (`@core/default/shell.html.twig`) and replace one block of it: here the navigation. The
     * head, the content and the footer are core's.
     */
    public function testAThemeExtendingTheCoreShellReplacesOneBlock(): void
    {
        $this->seedSite();

        $response = $this->site()->request('GET', '');

        $this->assertSame(200, $response->status, substr($response->body, 0, 400) . $this->serverLog());
        $this->assertStringContainsString('THEME NAV', $response->body);
        $this->assertStringNotContainsString('idno-nav-sidebar', $response->body, 'core\'s navigation is replaced');
        $this->assertStringContainsString('<div class="idno-feed h-feed">', $response->body, 'the content is core\'s');
        $this->assertStringContainsString('Hello world', $response->body, 'and so is the page\'s body');
        $this->assertStringContainsString('<meta charset="utf-8">', $response->body, 'the head is core\'s');
        $this->assertStringContainsString('<div class="blank-footer">', $response->body, 'the footer is core\'s');
        $this->assertStringContainsString('js/idno-2026.min.js', $response->body);
    }

    /**
     * A `.tpl.php` file of a theme or plugin beats core's Twig file of the same name and type, with the variables of the page and the
     * template object it always had.
     */
    public function testAThirdPartyTplPhpOverrideOfAPortedTemplateStillWins(): void
    {
        $this->seedSite();

        $response = $this->site()->request('GET', '');

        $this->assertSame(200, $response->status, substr($response->body, 0, 400) . $this->serverLog());
        $this->assertSame(1, preg_match('#<title[^>]*>(.*?)</title>#s', $response->body, $title), 'the page has a title');
        $this->assertStringContainsString('<!--zz-footer title=' . $title[1] . ' class=Idno\\Core\\DefaultTemplate-->', $response->body, 'the override is drawn, with the page\'s title and the template object');
        $this->assertStringNotContainsString('blank-footer', $response->body, 'core\'s footer is not drawn');
    }

    /**
     * What plugins register for a ported shell partial keeps its place, the one `TemplateRenderer::draw()` gives every template: the
     * prepends, then the extensions, then the template itself, then the added content; and a replacement is drawn in place of the template.
     */
    public function testInjectionsOnAPortedShellPartialKeepTheirPlace(): void
    {
        $this->seedSite();
        $plugin = dirname(__DIR__, 2) . '/IdnoPlugins/ZzInjectProbe';
        $this->made[] = $plugin;
        mkdir($plugin . '/templates/default/zzinject', 0777, true);
        file_put_contents($plugin . '/plugin.ini', "[Plugin description]\nname = 'Zz inject probe'\nversion = '1.0'\n");
        file_put_contents($plugin . '/Main.php', <<<'PHP'
        <?php
        namespace IdnoPlugins\ZzInjectProbe {
            class Main extends \Idno\Common\Plugin
            {
                function registerPages()
                {
                    $template = \Idno\Core\Idno::site()->template();
                    $template->prependTemplate('shell/footer', 'zzinject/prepend');
                    $template->extendTemplate('shell/footer', 'zzinject/extend');
                    $template->extendTemplateWithContent('shell/footer', '<!--zz-content-->');
                    $template->replaceTemplate('shell/head', 'zzinject/replacement');
                }
            }
        }
        PHP);
        foreach (['prepend' => '<!--zz-prepend-->', 'extend' => '<!--zz-extend-->', 'replacement' => '<!--zz-replaced-head-->'] as $name => $markup) {
            file_put_contents($plugin . '/templates/default/zzinject/' . $name . '.tpl.php', $markup);
        }
        $this->assertTrue($this->runInSite('return \\Idno\\Core\\Idno::site()->plugins()->enable("ZzInjectProbe");'));

        $response = $this->site()->request('GET', '');

        $this->assertSame(200, $response->status, substr($response->body, 0, 400) . $this->serverLog());
        $positions = [];
        foreach (['<!--zz-prepend-->', '<!--zz-extend-->', '<div class="blank-footer">', '<!--zz-content-->'] as $marker) {
            $position = strpos($response->body, $marker);
            $this->assertNotFalse($position, $marker . ' is drawn');
            $positions[] = $position;
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'the order is the prepend, the extension, core\'s footer, the added content');
        $this->assertStringContainsString('<!--zz-replaced-head-->', $response->body);
        $this->assertStringNotContainsString('initial-scale=1" />', $response->body, 'core\'s head partial is replaced');
        $this->assertStringContainsString('<meta charset="utf-8">', $response->body, 'the other head partials are drawn');
    }

    /**
     * The webmention's author, its image and its address are whatever the sender wrote. Whatever is stored, the page prints
     * no attribute the value could have opened and no `javascript:` address.
     */
    public function testAHostileWebmentionAuthorRendersInert(): void
    {
        $paths = $this->seedSite();
        $id = $this->literal((string) $paths['ids']['status']);
        $this->runInSite(<<<PHP
        \$status = \\Idno\\Common\\Entity::getByID($id);
        \$hostile = static fn (string \$kind): array => [
            'permalink' => 'http://evil.example/p"onmouseover="alert(1)/' . \$kind,
            'owner_name' => 'Mallory "><img src=x onerror=alert(2)>',
            'owner_url' => 'http://evil.example/u"onmouseover="alert(3)',
            'owner_image' => 'javascript:alert(4)',
            'content' => 'Hello <script>alert(5)</script>',
            'time' => 1772000000,
            'title' => 'T "onfocus="alert(6)',
        ];
        \$status->annotations = [
            'reply' => ['a1' => \$hostile('reply')],
            'like' => ['a2' => ['owner_image' => 'http://evil.example/i.png"onerror="alert(7)', 'owner_url' => 'javascript:alert(8)', 'permalink' => 'javascript:alert(9)'] + \$hostile('like')],
            'mention' => ['a3' => \$hostile('mention')],
            'share' => ['a4' => \$hostile('share')],
        ];
        return \$status->save(true);
        PHP);

        $html = $this->site()->request('GET', $paths['status']);
        $this->assertSame(200, $html->status);
        $this->assertStringContainsString('Mallory', $html->body, 'the annotations are drawn: the test is looking at them');
        $this->assertStringContainsString('Mallory &quot;&gt;&lt;img', $html->body, 'a name is text');
        $this->assertMarkupIsInert($html->body, 'the page', true);
        $this->assertStringNotContainsString('<script>alert(5)', $html->body);
        $this->assertStringNotContainsString('<img src=x', $html->body);

        // A feed and the JSON do not draw a post's annotations (the JSON carries them as stored data, which is the sender's text:
        // only what a template draws is markup). The `classic` templates that the other types fall back to do draw them, for the
        // places that still use them: the same hostile values are as inert there.
        $drawn = $this->runInSite(<<<PHP
        \$status = \\Idno\\Common\\Entity::getByID($id);
        \$out = [];
        foreach (['rss', 'json'] as \$type) {
            \$t = new \\Idno\\Core\\DefaultTemplate();
            \$t->setTemplateType(\$type);
            foreach (['replies' => 'reply', 'likes' => 'like', 'mentions' => 'mention', 'shares' => 'share'] as \$kind => \$subtype) {
                \$out[\$type . ':' . \$kind] = \$t->__(['object' => \$status, 'annotations' => \$status->annotations[\$subtype]])->draw('entity/annotations/' . \$kind);
            }
        }
        return \$out;
        PHP);
        foreach ($drawn as $label => $html) {
            $this->assertStringContainsString('Mallory', (string) $html, $label . ': the annotation is drawn: the check is looking at it');
            $this->assertMarkupIsInert((string) $html, 'the ' . $label . ' annotations');
            $this->assertStringNotContainsString('<script>alert(5)', (string) $html, $label);
            $this->assertStringNotContainsString('<img src=x', (string) $html, $label);
        }
    }

    /**
     * No element runs script by an `on...` attribute and no address starts with `javascript:`; a fragment has no script element
     * (a whole page has its own).
     */
    private function assertMarkupIsInert(string $html, string $where, bool $pageWithItsOwnScripts = false): void
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8"?>' . $html);
        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$pageWithItsOwnScripts) {
                $this->assertNotSame('script', strtolower($element->nodeName), $where . ' has a script element');
            }
            foreach ($element->attributes ?? [] as $attribute) {
                $name = strtolower($attribute->name);
                $this->assertDoesNotMatchRegularExpression('/^on/', $name, $where . ': <' . $element->nodeName . '> has the attribute ' . $attribute->name . ' that a hostile value opened');
                if (in_array($name, ['href', 'src', 'action', 'poster', 'srcset', 'data'], true)) {
                    $this->assertDoesNotMatchRegularExpression('/^\s*javascript:/i', $attribute->value, $where . ': <' . $element->nodeName . ' ' . $name . '>');
                }
            }
        }
    }

    /**
     * A link that submits a signed POST (the delete link of a post) is a link and a hidden form. A page registers the form with its
     * shell, which draws it at the end; an XHR request draws no shell, so there the form is printed after the link, and the link of
     * content inserted by a script is active (the form the link submits is in the response). Each link and its form share an id.
     */
    public function testALinkActionInAnXhrResponseCarriesItsForm(): void
    {
        $paths = $this->seedSite();
        $cookie = $this->loginAsAdmin();

        $xhr = $this->site()->request('GET', $paths['status'], ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest']);
        $this->assertSame(200, $xhr->status);

        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8"?>' . $xhr->body);
        $links = [];
        foreach ($document->getElementsByTagName('a') as $link) {
            if ($link->hasAttribute('data-form-id')) {
                $links[$link->getAttribute('data-form-id')] = $link;
            }
        }
        $this->assertNotEmpty($links, 'the response has an action link: the test is looking at it');
        $forms = [];
        foreach ($document->getElementsByTagName('form') as $form) {
            $forms[$form->getAttribute('id')] = $form;
        }
        foreach ($links as $id => $link) {
            $this->assertArrayHasKey($id, $forms, 'the form the link submits is in the response');
            $this->assertSame($link->getAttribute('href'), $forms[$id]->getAttribute('action'), 'and posts to the address of the link');
            $this->assertStringContainsString("document.getElementById('" . $id . "').submit()", $link->getAttribute('onclick'));
            $this->assertSame('post', strtolower($forms[$id]->getAttribute('method')));
            $names = array_map(static fn (\DOMElement $field): string => $field->getAttribute('name'), iterator_to_array($forms[$id]->getElementsByTagName('input')));
            $this->assertContains('__bTk', $names, 'and the form is signed');
            $this->assertSame(1, preg_match('#<a data-form-id="' . $id . '"[^>]*>(?:(?!</a>).)*</a>\s*<form action="[^"]*" style="display: none; margin: 0; padding: 0" id="' . $id . '"#s', $xhr->body), 'the form follows its link');
        }

        // A page draws the same form once, in its shell: where the link is not followed by it
        $page = $this->site()->request('GET', $paths['status'], ['Cookie' => $cookie]);
        $this->assertSame(200, $page->status);
        $this->assertSame(substr_count($page->body, 'data-form-id="'), substr_count($page->body, 'style="display: none; margin: 0; padding: 0" id="f'), 'a page has one form for each link');
        $this->assertSame(0, preg_match('#<a data-form-id="[^"]*"[^>]*>(?:(?!</a>).)*</a>\s*<form action="#s', $page->body), 'in the page the forms are the shell\'s, not printed beside their links');
    }

    /**
     * The admin menu is a Twig template that draws the entries bundled plugins add (the `admin/menu/items` extension point) between its
     * own links, in the order the plugins registered them: ActivityPub, StaticPages and Webhooks are always on in the test site, and Styles,
     * which is enabled here, registers after them.
     */
    public function testAdminMenuItemsOfBundledPluginsAreListed(): void
    {
        $this->seedSite();
        $cookie = $this->loginAsAdmin();

        $links = function () use ($cookie): array {
            $page = $this->site()->request('GET', 'admin/', ['Cookie' => $cookie]);
            $this->assertSame(200, $page->status);
            $this->assertSame(1, preg_match('#<nav class="idno-admin-nav">(.*?)</nav>#s', $page->body, $menu), 'the page has the admin menu');
            preg_match_all('#<a href="[^"]*?/admin/([a-z]*)/?"#', $menu[1], $hrefs);

            return $hrefs[1];
        };

        $before = $links();
        $this->assertSame(['', 'themes', 'plugins', 'users', 'email', 'activitypub', 'staticpages', 'webhooks', 'statistics', 'import', 'export'], $before);

        $this->runInSite(<<<'PHP'
        \Idno\Core\Idno::site()->plugins()->enable('Styles');

        return true;
        PHP);

        $this->assertSame(['', 'themes', 'plugins', 'users', 'email', 'activitypub', 'staticpages', 'webhooks', 'styles', 'statistics', 'import', 'export'], $links());
    }

    public function testDraftsPageIsServedFromCore(): void
    {
        $this->seedSite();
        $cookie = $this->loginAsAdmin();

        $visitor = $this->site()->request('GET', 'drafts/');
        $member = $this->site()->request('GET', 'drafts/', ['Cookie' => $cookie]);

        $this->assertNotSame(200, $visitor->status, 'a visitor has no drafts page');
        $this->assertStringNotContainsString('A draft note, not yet published.', $visitor->body);
        $this->assertSame(200, $member->status);
        $this->assertStringContainsString('A draft note, not yet published.', $member->body);
        $this->assertFalse(class_exists('Themes\\Twenty26\\Pages\\Drafts'));
    }

    public function testNoTemplateReferencesThemesTwenty26(): void
    {
        $root = dirname(__DIR__, 2);
        $directories = array_merge([$root . '/templates'], glob($root . '/IdnoPlugins/*/templates', GLOB_ONLYDIR) ?: []);
        $found = [];
        foreach ($directories as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && preg_match('#Themes/Twenty26|dist/modern#', (string) file_get_contents($file->getPathname())) === 1) {
                    $found[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $found);
        $this->assertDirectoryDoesNotExist($root . '/Themes/Twenty26');
        $this->assertFileExists($root . '/css/idno-2026.min.css');
        $this->assertFileExists($root . '/js/idno-2026.min.js');
    }

    public function testNoCoreOrBundledDirectoryHasAModernTemplateType(): void
    {
        $root = dirname(__DIR__, 2);
        $directories = array_merge([$root . '/templates/modern'], glob($root . '/IdnoPlugins/*/templates/modern', GLOB_ONLYDIR) ?: [], glob($root . '/Themes/*/templates/modern', GLOB_ONLYDIR) ?: []);
        $found = array_values(array_filter($directories, 'is_dir'));
        // Directories this suite makes for the third-party cases are not bundled ones.
        $found = array_values(array_filter($found, static fn (string $d): bool => !str_contains($d, '/Zz')));

        $this->assertSame([], array_map(static fn (string $d): string => substr($d, strlen($root) + 1), $found), 'a `modern` directory is for third-party code only');
    }

    /**
     * Logs the administrator in through the form and returns the cookie. Unlike loginAsAdmin() it requests no page after the
     * login, so a message the site adds for the administrator is still waiting in the session.
     */
    private function loginWithoutLookingAtAPage(): string
    {
        $form = $this->site()->request('GET', 'session/login');
        $cookie = $this->sessionCookie($form);
        $response = $this->site()->request('POST', 'session/login', ['Cookie' => $cookie], $this->formToken($form->body, 'session/login') + ['email' => self::ADMIN, 'password' => self::PASSWORD]);
        $this->assertSame(302, $response->status);

        return \Tests\Acceptance\Browser::cookieOr($response, $cookie) ?? $cookie;
    }

    private function setStoredTheme(string $theme): void
    {
        $name = $this->literal($theme);
        $this->runInSite('$c = \\Idno\\Core\\Idno::site()->config(); $c->config["theme"] = ' . $name . '; $c->theme = ' . $name . '; $c->save(); return true;');
    }

    /**
     * The `theme` of the stored settings, or $unset when there is none.
     */
    private function storedTheme(string $unset = ''): string
    {
        $theme = $this->runInSite('$r = \\Idno\\Core\\Idno::site()->db()->getAnyRecord("config"); return $r["theme"] ?? null;');

        return is_string($theme) ? $theme : $unset;
    }

    /**
     * Makes a theme under Themes/ (removed in tearDown()) with the given files: path below the theme => contents.
     *
     * @param array<string, string> $files
     */
    private function makeTheme(string $name, array $files): void
    {
        $theme = dirname(__DIR__, 2) . '/Themes/' . $name;
        if (in_array($theme, $this->made, true)) {
            return;
        }
        $this->made[] = $theme;
        mkdir($theme, 0777, true);
        file_put_contents($theme . '/theme.ini', "[Theme description]\nname = '" . $name . "'\nversion = '1.0'\nauthor = 'Test'\n");
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($theme . '/' . $path))) {
                mkdir(dirname($theme . '/' . $path), 0777, true);
            }
            file_put_contents($theme . '/' . $path, $contents);
        }
    }

    private function makeFixtureTheme(): void
    {
        $theme = dirname(__DIR__, 2) . '/Themes/ZzFixtureTheme';
        if (in_array($theme, $this->made, true)) {
            return;
        }
        $this->made[] = $theme;
        mkdir($theme . '/templates/default/shell', 0777, true);
        mkdir($theme . '/templates/default/zzfixture', 0777, true);
        file_put_contents($theme . '/theme.ini', "[Theme description]\nname = 'Zz fixture'\nversion = '1.0'\nauthor = 'Test'\n\n[extensions]\nshell/pagination = 'zzfixture/extension'\n");
        file_put_contents($theme . '/templates/default/shell/pagination.tpl.php', '<!--zz-theme-pagination-->');
        file_put_contents($theme . '/templates/default/zzfixture/extension.tpl.php', '<!--zz-theme-extension-->');
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
