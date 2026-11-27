<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\TypeChain;
use PHPUnit\Framework\TestCase;
use Tests\Templating\FallbackTrace;

/**
 * Which template files the output that is not a web page (feeds, JSON, JSONP, JSON Feed, ActivityPub, KML) can draw, worked
 * out from the file system alone: the closure of the template names reachable from what each type draws first, resolved
 * with the type's own chain (its own directory, then `classic`, then `default`) over core and the bundled plugins.
 *
 * The rule it pins: such output never draws a file under a `default` directory, which is the web page look and is ported to
 * Twig, whose escaping is wrong for a feed. A `default` file that a feed draws has a copy in `classic`, found first.
 */
final class NonHtmlReachTest extends TestCase
{
    /** The types that have Twig files, and their extension (the locator's own list). */
    private const TWIG_TYPES = ['default' => 'html.twig', 'email' => 'html.twig', 'email-text' => 'txt.twig'];

    /** The names the helper methods of the template draw. */
    private const HELPER_NAMES = [
        'signForm' => 'forms/token',
        'createLink' => 'forms/link',
        'createForm' => 'forms/action',
        'drawSyndication' => 'content/syndication',
        'drawPagination' => 'shell/pagination',
    ];

    /**
     * Templates drawn by a name no draw call holds as a literal, each with where the name comes from. Only a name that really is drawn
     * belongs here: the user search page takes the template to draw from its `template` input, defaulting to this one, and
     * `forms/usersearch` hands it on as `render-template`.
     */
    private const DRAWN_BY_A_NAME_HELD_ELSEWHERE = [
        'forms/components/usersearch/user' => 'Idno/Pages/Search/User.php: getInput(\'template\', ...) default; forms/usersearch: render-template',
    ];

    /**
     * @param bool $withPageBodies also start from every name a PHP class outside the templates names in a string (a page's body,
     *                             a plugin's `extendTemplate()`), and let a name built by concatenation (`'forms/input/' . $type`)
     *                             stand for every template under it
     *
     * @return array<string, list<string>> type => the files (relative to the project) its draws resolve to, sorted
     */
    public static function reachable(string $projectDir, bool $withPageBodies = false): array
    {
        $pageNames = $withPageBodies ? array_merge(self::namedByPageClasses($projectDir), array_keys(self::DRAWN_BY_A_NAME_HELD_ELSEWHERE)) : [];
        $result = [];
        foreach (array_diff(FallbackTrace::TYPES, ['email', 'email-text']) as $type) {
            $files = [];
            $seen = [];
            $queue = array_merge(self::seeds($projectDir, $type), $pageNames);
            while ($queue !== []) {
                $name = array_shift($queue);
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                if (str_ends_with($name, '/')) {
                    foreach (self::namesUnder($projectDir, $name) as $under) {
                        $queue[] = $under;
                    }
                    continue;
                }
                foreach (self::resolve($projectDir, $type, $name) as $file) {
                    $files[$file] = true;
                    foreach (self::drawnNames((string) file_get_contents($projectDir . '/' . $file), true) as $next) {
                        $queue[] = $next;
                    }
                }
            }
            $list = array_keys($files);
            sort($list);
            $result[$type] = $list;
        }

        return $result;
    }

    /**
     * Every template name a page class (or any PHP class outside the templates) names in a literal draw call.
     *
     * @return list<string>
     */
    public static function namedByPageClasses(string $projectDir): array
    {
        $names = [];
        foreach (self::phpFilesOutsideTemplates($projectDir) as $file) {
            $php = (string) file_get_contents($file);
            foreach (self::drawnNames($php, true) as $name) {
                $names[$name] = true;
            }
            foreach (self::registeredNames($php) as $name) {
                $names[$name] = true;
            }
        }
        $list = array_map('strval', array_keys($names));
        sort($list);

        return $list;
    }

    public function testATemplateDrawnThroughThePageRendererIsNamed(): void
    {
        $drawn = new \ReflectionMethod(self::class, 'drawnNames');
        $php = "return \$this->renderer->page(\$context, ['title' => 'A', 'body' => \$this->renderer->template('pages/example', ['a' => 1])]);"
            . " \$site->template()->draw('pages/other'); \$x = \$t->template(); \$y = \$r->template(\$name, []);";

        $this->assertSame(['pages/other', 'pages/example'], $drawn->invoke(null, $php));
    }

    public function testEveryTemplateANonHtmlTypeReachesIsAPhpFileOutsideDefault(): void
    {
        $inDefault = [];
        foreach (self::reachable(dirname(__DIR__, 3)) as $type => $files) {
            foreach ($files as $file) {
                if (preg_match('#(^|/)templates/default/#', $file) === 1) {
                    $inDefault[$file][] = $type;
                }
            }
        }
        ksort($inDefault);

        $this->assertSame([], array_map(static fn (array $types): string => implode(', ', $types), $inDefault));
    }

    /**
     * The page bodies (and what they draw) that a non-HTML request can reach and that are drawn from a `default` Twig file. Such a file
     * publishes nothing on the page's template: `idno_include()` draws on a copy, so JSON, which prints every variable of the page's
     * template, never sees a key a Twig body set. A body whose pre-Twig version left a variable on the page (`__()`, `$this->name =`)
     * has a `classic` copy, which is found first and so never appears here (`entity/wrapper`, `pages/home/blurb`, `entity/User/profile`,
     * the account menu, login, settings, tools and export, the admin `dependencies` and `statistics/report`, the media, RSVP and static
     * page edit templates). What is listed is every other body such a request reaches, so that one added later is a decision: a
     * `classic` copy where JSON needs what it left, a place in this list where it leaves nothing.
     */
    private const PORTED_BODIES_THAT_LEAVE_NOTHING = [
        'IdnoPlugins/Checkin/templates/default/checkin/head.html.twig',
        'IdnoPlugins/Checkin/templates/default/entity/Checkin/icon.html.twig',
        'IdnoPlugins/Event/templates/default/entity/Event/icon.html.twig',
        'IdnoPlugins/Event/templates/default/entity/RSVP/icon.html.twig',
        'IdnoPlugins/IndiePub/templates/default/indiepub/auth.html.twig',
        'IdnoPlugins/IndiePub/templates/default/indiepub/shell/head.html.twig',
        'IdnoPlugins/Like/templates/default/entity/Like/icon.html.twig',
        'IdnoPlugins/Media/templates/default/entity/Media/icon.html.twig',
        'IdnoPlugins/Photo/templates/default/entity/Photo/icon.html.twig',
        'IdnoPlugins/StandardSiteSync/templates/default/standardsitesync/head.html.twig',
        'IdnoPlugins/StaticPages/templates/default/staticpages/page.html.twig',
        'IdnoPlugins/Status/templates/default/entity/Reply/icon.html.twig',
        'IdnoPlugins/Status/templates/default/entity/Status/icon.html.twig',
        'IdnoPlugins/Styles/templates/default/styles/shell/head.html.twig',
        'IdnoPlugins/Text/templates/default/entity/Entry/icon.html.twig',
        'templates/default/account/password.html.twig',
        'templates/default/account/password/reset.html.twig',
        'templates/default/account/password/sent.html.twig',
        'templates/default/account/register.html.twig',
        'templates/default/account/settings/feedback.html.twig',
        'templates/default/account/settings/feedback/confirm.html.twig',
        'templates/default/account/settings/following.html.twig',
        'templates/default/account/settings/following/bookmarklet.html.twig',
        'templates/default/account/settings/following/mf2user.html.twig',
        'templates/default/admin/about.html.twig',
        'templates/default/admin/dependencies/build.html.twig',
        'templates/default/admin/dependencies/extension.html.twig',
        'templates/default/admin/dependencies/idno.html.twig',
        'templates/default/admin/dependencies/php.html.twig',
        'templates/default/admin/dependencies/plugin.html.twig',
        'templates/default/admin/diagnostics.html.twig',
        'templates/default/admin/home/description.html.twig',
        'templates/default/admin/homepage.html.twig',
        'templates/default/admin/legacytables/body.html.twig',
        'templates/default/admin/welcome.html.twig',
        'templates/default/drafts.html.twig',
        'templates/default/entity/User/icon.html.twig',
        'templates/default/entity/bookmarklet.html.twig',
        'templates/default/entity/edit/footer.html.twig',
        'templates/default/entity/edit/header.html.twig',
        'templates/default/entity/edit/spinner.html.twig',
        'templates/default/entity/editwrapper.html.twig',
        'templates/default/entity/share.html.twig',
        'templates/default/file/picker/donejs.html.twig',
        'templates/default/forms/input/captcha.html.twig',
        'templates/default/pages/403.html.twig',
        'templates/default/pages/404.html.twig',
        'templates/default/pages/410.html.twig',
        'templates/default/pages/500.html.twig',
        'templates/default/pages/webmention.html.twig',
    ];

    public function testAPageBodyANonHtmlTypeCanReachIsAClassicFileOrAListedTwigFile(): void
    {
        $root = dirname(__DIR__, 3);
        $reached = [];
        foreach (self::reachable($root, true) as $files) {
            foreach ($files as $file) {
                if (preg_match('#^(?:templates/default/(?:drafts|(?:entity|content|stream|pages|file|forms|account|admin)/.*)|IdnoPlugins/[^/]+/templates/default/.*)\.html\.twig$#', $file) === 1) {
                    $reached[$file] = true;
                }
            }
        }
        $reached = array_keys($reached);
        sort($reached);

        $this->assertSame(self::PORTED_BODIES_THAT_LEAVE_NOTHING, $reached, 'a page body that a feed or JSON request draws from a Twig file: it needs a classic copy if JSON needs a variable the old template left on the page, or a place in the list if not');
    }

    public function testNoClassicFileIsUnreachable(): void
    {
        $root = dirname(__DIR__, 3);
        $reached = [];
        foreach (self::reachable($root, true) as $files) {
            foreach ($files as $file) {
                $reached[$file] = true;
            }
        }
        $unreachable = [];
        foreach (FallbackTrace::classic($root) as [$name, $file]) {
            if (!isset($reached[$file])) {
                $unreachable[] = $file;
            }
        }

        $this->assertSame([], $unreachable);
    }

    /**
     * @return list<string>
     */
    private static function seeds(string $projectDir, string $type): array
    {
        $seeds = ['entity/default'];
        $own = $projectDir . '/templates/' . $type;
        if (is_dir($own)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($own, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.tpl.php')) {
                    $seeds[] = substr($file->getPathname(), strlen($own) + 1, -strlen('.tpl.php'));
                }
            }
        }
        foreach (self::entityClasses($projectDir) as $short) {
            $seeds[] = 'entity/' . $short;
        }
        foreach (self::HELPER_NAMES as $name) {
            $seeds[] = $name;
        }

        return $seeds;
    }

    /**
     * The short names of the classes that carry `#[AsEntity]` under `Idno/` and `IdnoPlugins/`.
     *
     * @return list<string>
     */
    private static function entityClasses(string $projectDir): array
    {
        $short = [];
        foreach ([$projectDir . '/Idno', $projectDir . '/IdnoPlugins'] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/templates/')) {
                    continue;
                }
                $text = (string) file_get_contents($file->getPathname());
                if (preg_match('/^\s*#\[(?:\\\\?Idno\\\\Storage\\\\Attribute\\\\)?AsEntity\b/m', $text) === 1) {
                    $short[$file->getBasename('.php')] = true;
                }
            }
        }
        $list = array_keys($short);
        sort($list);

        return $list;
    }

    /**
     * @return list<string> the files a draw of $name resolves to for $type: those of the first type of the chain that has one (in a
     *                      directory of a type that has Twig files, the Twig file wins over a `.tpl.php` of the same name, as the locator has it)
     */
    private static function resolve(string $projectDir, string $type, string $name): array
    {
        $bases = array_merge(glob($projectDir . '/IdnoPlugins/*', GLOB_ONLYDIR) ?: [], [$projectDir]);
        foreach (TypeChain::for($type) as $candidate) {
            $found = [];
            foreach ($bases as $base) {
                $stem = $base . '/templates/' . $candidate . '/' . $name;
                $files = isset(self::TWIG_TYPES[$candidate]) ? [$stem . '.' . self::TWIG_TYPES[$candidate], $stem . '.tpl.php'] : [$stem . '.tpl.php'];
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $found[] = substr($file, strlen($projectDir) + 1);
                        break;
                    }
                }
            }
            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * The template names a PHP file draws by a literal: `draw('name')`, the helper methods, and the annotation names built by concatenation.
     *
     * @return list<string>
     */
    private static function drawnNames(string $php, bool $withPrefixes = false): array
    {
        $names = [];
        if (preg_match_all('/\b(?:draw|idno_include)\(\s*[\'"]([A-Za-z0-9_\/-]+)[\'"]/', $php, $matches) > 0) {
            foreach ($matches[1] as $name) {
                if (!str_ends_with($name, '/')) {
                    $names[] = $name;
                }
            }
        }
        // A template a page service draws through the page renderer: `$renderer->template('pages/x', [...])`, which is what its `body` is made of
        // (`$renderer->page($context, ['title' => ..., 'body' => $renderer->template('pages/x', [])])`)
        if (preg_match_all('/->template\(\s*[\'"]([A-Za-z0-9_\/-]+)[\'"]\s*,/', $php, $matches) > 0) {
            foreach ($matches[1] as $name) {
                if (!str_ends_with($name, '/')) {
                    $names[] = $name;
                }
            }
        }
        foreach (self::HELPER_NAMES as $helper => $name) {
            if (str_contains($php, $helper . '(')) {
                $names[] = $name;
            }
        }
        // A literal prefix of a draw that goes on by concatenation (`draw('content/syndication/icon/' . $service)`): every template under it
        if ($withPrefixes && preg_match_all('/\b(?:draw|idno_include)\(\s*[\'"]([A-Za-z0-9_\/-]+\/)[\'"]\s*[.~]/', $php, $matches) > 0) {
            foreach ($matches[1] as $prefix) {
                $names[] = $prefix;
            }
        }

        return $names;
    }

    /**
     * The template names a PHP file passes as string literals to the calls that register or choose templates: both names of
     * `extendTemplate()`, `prependTemplate()` and `replaceTemplate()`, the name of `extendTemplateWithContent()`, and the shell
     * `drawPage()` is given. A name held in a variable, or any other string, is not one.
     *
     * @return list<string>
     */
    private static function registeredNames(string $php): array
    {
        $names = [];
        if (preg_match_all('/\b(?:extendTemplate|prependTemplate|replaceTemplate)\(\s*[\'"]([A-Za-z0-9_\/-]+)[\'"]\s*,\s*[\'"]([A-Za-z0-9_\/-]+)[\'"]/', $php, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $names[] = $match[1];
                $names[] = $match[2];
            }
        }
        if (preg_match_all('/\b(?:extendTemplateWithContent|drawPage)\(\s*(?:(?:true|false|\$[A-Za-z_]+)\s*,\s*)?[\'"]([A-Za-z0-9_\/-]+)[\'"]/', $php, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The names of the `.tpl.php` templates of every type in core and the bundled plugins that start with $prefix.
     *
     * @return list<string>
     */
    private static function namesUnder(string $projectDir, string $prefix): array
    {
        $names = [];
        $bases = array_merge(glob($projectDir . '/IdnoPlugins/*', GLOB_ONLYDIR) ?: [], [$projectDir]);
        foreach ($bases as $base) {
            foreach (glob($base . '/templates/*', GLOB_ONLYDIR) ?: [] as $typeDirectory) {
                $directory = $typeDirectory . '/' . $prefix;
                if (!is_dir($directory)) {
                    continue;
                }
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    foreach (['.tpl.php', '.html.twig', '.txt.twig'] as $extension) {
                        if ($file->isFile() && str_ends_with($file->getFilename(), $extension)) {
                            $names[] = substr($file->getPathname(), strlen($typeDirectory) + 1, -strlen($extension));
                        }
                    }
                }
            }
        }

        return $names;
    }

    /**
     * @return \Generator<string>
     */
    private static function phpFilesOutsideTemplates(string $projectDir): \Generator
    {
        foreach ([$projectDir . '/Idno', $projectDir . '/IdnoPlugins'] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && !str_contains($file->getPathname(), '/templates/') && !str_contains($file->getPathname(), '/Tests/')) {
                    yield $file->getPathname();
                }
            }
        }
    }
}
