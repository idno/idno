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
            default => '',
        };
        if ($theme === 'ZzFixtureTheme') {
            $this->makeFixtureTheme();
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

        foreach (['../classic', '..%2Fclassic', 'classic/../default', '%2e%2e/classic', 'classic%00'] as $type) {
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

        // What a feed and the JSON draw come from the `classic` templates: the hostile values are as inert there.
        $json = $this->site()->request('GET', $paths['status'] . '?_t=json');
        $this->assertSame(200, $json->status);
        $data = json_decode($json->body, true);
        $this->assertIsArray($data, 'the JSON of the post');
        $this->assertStringContainsString('Mallory', $json->body, 'the annotations are in it');
        // The `annotation` and `annotations` of the JSON are the stored data (the sender's text, as a name or a title is): only what a template drew is markup
        unset($data['annotation'], $data['annotations'], $data['object']['annotations']);
        array_walk_recursive($data, function ($value, $key) {
            if (is_string($value) && str_contains($value, '<')) {
                $this->assertMarkupIsInert($value, 'the JSON value ' . $key);
            }
        });

        foreach (['', $paths['status']] as $path) {
            $rss = $this->site()->request('GET', $path . '?_t=rss');
            $this->assertSame(200, $rss->status, $path);
            $xml = new \DOMDocument();
            $this->assertTrue(@$xml->loadXML($rss->body), 'the feed is well-formed XML: ' . substr($rss->body, 0, 200));
            foreach ($xml->getElementsByTagName('*') as $element) {
                foreach ($element->attributes ?? [] as $attribute) {
                    $this->assertDoesNotMatchRegularExpression('/^on/i', $attribute->name, 'the feed');
                    $this->assertDoesNotMatchRegularExpression('/^\s*javascript:/i', $attribute->value, 'the feed');
                }
                if (!$element->hasChildNodes() || $element->getElementsByTagName('*')->length > 0) {
                    continue;
                }
                if (str_contains($element->textContent, '<')) {
                    $this->assertMarkupIsInert($element->textContent, 'the feed element ' . $element->nodeName);
                }
            }
            $this->assertStringNotContainsString('<script>alert(5)', $rss->body);
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
        $this->assertTrue(class_exists(\Idno\Pages\Drafts::class));
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
