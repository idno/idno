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
     * The page bodies (and what they draw) that a non-HTML request can reach and that are drawn from a ported `default` file: each is a
     * template that, in its `.tpl.php` original, left nothing on the page's template (no `__()` on `$this`, no `$this->name =`), so
     * drawing it from Twig, on a copy, loses no key of the JSON of the page. A body that left something has a `classic` copy, which is
     * found first and so never appears here (`entity/wrapper`, `pages/home/blurb`, `entity/User/profile`). The account pages that a JSON request draws (`classic` copies exist for the menu, login, settings, tools and export) leave nothing either: the forms they draw (`forms/token`, the captcha) are drawn on the site's own object by `signForm()`, which is the same call as before. The admin pages that a JSON request draws leave nothing either, but for `dependencies` and `statistics/report`, which have `classic` copies of their originals (the `__()` calls of the originals leave `version`, `extension`, `report` and `level` on the page's template; the menu a body draws is the `classic` one, which leaves nothing). A later port that a page body reaches extends the pattern and the list.
     */
    private const PORTED_BODIES_THAT_LEAVE_NOTHING = [
        'templates/default/account/password.tpl.php',
        'templates/default/account/password/reset.tpl.php',
        'templates/default/account/password/sent.tpl.php',
        'templates/default/account/register.tpl.php',
        'templates/default/account/settings/feedback.tpl.php',
        'templates/default/account/settings/feedback/confirm.tpl.php',
        'templates/default/account/settings/following.tpl.php',
        'templates/default/account/settings/following/bookmarklet.tpl.php',
        'templates/default/account/settings/following/mf2user.tpl.php',
        'templates/default/admin/about.tpl.php',
        'templates/default/admin/dependencies/build.tpl.php',
        'templates/default/admin/dependencies/extension.tpl.php',
        'templates/default/admin/dependencies/idno.tpl.php',
        'templates/default/admin/dependencies/php.tpl.php',
        'templates/default/admin/dependencies/plugin.tpl.php',
        'templates/default/admin/diagnostics.tpl.php',
        'templates/default/admin/home/description.tpl.php',
        'templates/default/admin/homepage.tpl.php',
        'templates/default/admin/legacytables/body.tpl.php',
        'templates/default/drafts.tpl.php',
        'templates/default/entity/User/icon.tpl.php',
        'templates/default/entity/bookmarklet.tpl.php',
        'templates/default/entity/edit/footer.tpl.php',
        'templates/default/entity/edit/header.tpl.php',
        'templates/default/entity/edit/spinner.tpl.php',
        'templates/default/entity/editwrapper.tpl.php',
        'templates/default/entity/share.tpl.php',
        'templates/default/file/picker/donejs.tpl.php',
        'templates/default/following/add.tpl.php',
        'templates/default/following/confirm.tpl.php',
        'templates/default/following/home.tpl.php',
        'templates/default/forms/input/captcha.tpl.php',
        'templates/default/onboarding/begin.tpl.php',
        'templates/default/onboarding/connect.tpl.php',
        'templates/default/onboarding/profile.tpl.php',
        'templates/default/onboarding/register.tpl.php',
        'templates/default/pages/403.tpl.php',
        'templates/default/pages/404.tpl.php',
        'templates/default/pages/410.tpl.php',
        'templates/default/pages/500.tpl.php',
        'templates/default/pages/webmention.tpl.php',
        'templates/default/stream/home.tpl.php',
    ];

    public function testAPortedPageBodyANonHtmlTypeCanReachLeavesNothingOnThePageOrHasAClassicCopy(): void
    {
        $root = dirname(__DIR__, 3);
        $reached = [];
        foreach (self::reachable($root, true) as $files) {
            foreach ($files as $file) {
                if (preg_match('#^templates/default/(?:drafts|(?:entity|content|stream|pages|file|following|forms|account|onboarding|admin)/.*)\.tpl\.php$#', $file) === 1 && is_file($root . '/' . substr($file, 0, -8) . '.html.twig')) {
                    $reached[$file] = true;
                }
            }
        }
        $reached = array_keys($reached);
        sort($reached);

        $this->assertSame(self::PORTED_BODIES_THAT_LEAVE_NOTHING, $reached, 'a ported page body that a feed or JSON request draws: it needs a classic copy if its original left a variable on the page\'s template, or a place in the list if it left none');
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
     * @return list<string> the files a draw of $name resolves to for $type: those of the first type of the chain that has one
     */
    private static function resolve(string $projectDir, string $type, string $name): array
    {
        $bases = array_merge(glob($projectDir . '/IdnoPlugins/*', GLOB_ONLYDIR) ?: [], [$projectDir]);
        foreach (TypeChain::for($type) as $candidate) {
            $found = [];
            foreach ($bases as $base) {
                $file = $base . '/templates/' . $candidate . '/' . $name . '.tpl.php';
                if (is_file($file)) {
                    $found[] = substr($file, strlen($projectDir) + 1);
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
        if (preg_match_all('/\bdraw\(\s*[\'"]([A-Za-z0-9_\/-]+)[\'"]/', $php, $matches) > 0) {
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
        if ($withPrefixes && preg_match_all('/\bdraw\(\s*[\'"]([A-Za-z0-9_\/-]+\/)[\'"]\s*\./', $php, $matches) > 0) {
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
                    if ($file->isFile() && str_ends_with($file->getFilename(), '.tpl.php')) {
                        $names[] = substr($file->getPathname(), strlen($typeDirectory) + 1, -strlen('.tpl.php'));
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
