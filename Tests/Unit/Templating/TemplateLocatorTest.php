<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\TemplateCascade;
use Idno\Templating\TemplateLocator;
use PHPUnit\Framework\TestCase;

final class TemplateLocatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/idno-locator-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function base(string $name, string ...$templates): string
    {
        foreach ($templates as $template) {
            $path = $this->root . '/' . $name . '/templates/' . $template . '.tpl.php';
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            file_put_contents($path, $name . ':' . $template);
        }
        if ($templates === []) {
            mkdir($this->root . '/' . $name, 0700, true);
        }

        return $this->root . '/' . $name;
    }

    public function testTypeIsTriedBeforeBase(): void
    {
        $core = $this->base('core', 'alt/typed');
        $theme = $this->base('theme', 'default/typed');
        $cascade = new TemplateCascade($core);
        $cascade->addBase($theme);

        $located = (new TemplateLocator($cascade))->locate('typed', 'alt');

        $this->assertNotNull($located);
        $this->assertSame($core . '/templates/alt/typed.tpl.php', $located->path);
        $this->assertSame('php', $located->engine);
        $this->assertSame('alt', $located->type);
        $this->assertSame($core, $located->base);
        $this->assertSame('alt/typed.tpl.php', $located->logicalName);
    }

    public function testDefaultIsTheFallbackType(): void
    {
        $core = $this->base('core', 'default/x');
        $locator = new TemplateLocator(new TemplateCascade($core));

        $this->assertSame('default', $locator->locate('x', 'alt')?->type);
        $this->assertNull($locator->locate('x', 'alt', false), 'unless the fallback is off');
        $this->assertSame('default', $locator->locate('x', 'default', false)?->type);
    }

    public function testLaterAddedBaseIsSearchedFirst(): void
    {
        $core = $this->base('core', 'default/x');
        $a = $this->base('a', 'default/x');
        $b = $this->base('b', 'default/x');
        $cascade = new TemplateCascade($core);
        $cascade->addBase($a);
        $cascade->addBase($b);

        $this->assertSame($b, (new TemplateLocator($cascade))->locate('x', 'default')?->base);
        $this->assertSame([$b, $a, $core], $cascade->bases());
    }

    public function testCoreIsAlwaysLast(): void
    {
        $core = $this->base('core', 'default/x');
        $a = $this->base('a', 'default/y');
        $cascade = new TemplateCascade($core);
        $cascade->addBase($core);
        $cascade->addBase($a);
        $cascade->addBase($a);
        $cascade->addBase($this->root . '/no-such-directory');
        $cascade->addBase('');

        $this->assertSame([$a, $core], $cascade->bases(), 'a directory is listed once; core and a missing one are not added');
        $this->assertSame($core, $cascade->coreDir());
        $this->assertSame($core, (new TemplateLocator($cascade))->locate('x', 'default')?->base);
    }

    private function twigBase(string $name, string ...$templates): string
    {
        foreach ($templates as $template) {
            $path = $this->root . '/' . $name . '/templates/' . $template;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            file_put_contents($path, $name . ':' . $template);
        }

        return $this->root . '/' . $name;
    }

    public function testTwigIsFoundForDefaultEmailAndEmailTextOnly(): void
    {
        $core = $this->twigBase('core', 'default/x.html.twig', 'email/x.html.twig', 'email-text/x.txt.twig', 'rss/x.html.twig', 'json/x.html.twig', 'jsonp/x.html.twig', 'jsonfeed/x.html.twig', 'activitypub/x.html.twig', 'kml/x.html.twig', 'rss/x.tpl.php', 'default/y.txt.twig', 'email/y.txt.twig', 'email-text/y.html.twig');
        $locator = new TemplateLocator(new TemplateCascade($core));

        $found = $locator->locate('x', 'default');
        $this->assertSame('twig', $found?->engine);
        $this->assertSame($core . '/templates/default/x.html.twig', $found?->path);
        $this->assertSame('default/x.html.twig', $found?->logicalName);
        $this->assertSame('email/x.html.twig', $locator->locate('x', 'email')?->logicalName);
        $this->assertSame('email-text/x.txt.twig', $locator->locate('x', 'email-text')?->logicalName);
        $this->assertSame('php', $locator->locate('x', 'rss')?->engine, 'a feed type has .tpl.php files only');
        $this->assertSame('rss/x.tpl.php', $locator->locate('x', 'rss')?->logicalName);
        foreach (['json', 'jsonp', 'jsonfeed', 'activitypub', 'kml'] as $type) {
            $located = $locator->locate('x', $type);
            $this->assertSame('default/x.html.twig', $located?->logicalName, $type . ' ignores its own Twig file and falls back to default\'s');
            $this->assertNull($locator->locate('x', $type, false), $type . ' alone has no x');
        }
        $this->assertNull($locator->locate('y', 'default'), 'default has .html.twig files only');
        $this->assertNull($locator->locate('y', 'email'), 'email has .html.twig files only');
        $this->assertSame('default/y.txt.twig', $locator->locate('y', 'email-text')?->logicalName, 'email-text draws .txt.twig files of every directory of its chain, and never an .html.twig one');
    }

    /**
     * A plain-text mail autoescapes nothing, so it must never draw a Twig file that autoescapes HTML: whatever directory of the
     * chain (its own type, `classic`, `default`) a file is in, `email-text` draws `.txt.twig` or `.tpl.php` and never `.html.twig`.
     */
    public function testEmailTextNeverDrawsAnHtmlTwigFile(): void
    {
        $core = $this->twigBase('core', 'default/h.html.twig', 'classic/h2.html.twig', 'email-text/h3.html.twig', 'default/t.txt.twig', 'classic/t2.txt.twig', 'default/p.html.twig', 'default/p.tpl.php');
        $locator = new TemplateLocator(new TemplateCascade($core));

        $this->assertNull($locator->locate('h', 'email-text'), 'default\'s html.twig is not a plain-text template');
        $this->assertNull($locator->locate('h2', 'email-text'), 'nor classic\'s');
        $this->assertNull($locator->locate('h3', 'email-text'), 'nor its own');
        $this->assertSame('default/t.txt.twig', $locator->locate('t', 'email-text')?->logicalName, 'a .txt.twig of the fallback directory is');
        $this->assertSame('classic/t2.txt.twig', $locator->locate('t2', 'email-text')?->logicalName);
        $this->assertSame('default/p.tpl.php', $locator->locate('p', 'email-text')?->logicalName, 'a .tpl.php file is found when only the HTML Twig file would have been');
        $this->assertSame('default/h.html.twig', $locator->locate('h', 'email')?->logicalName, 'an HTML mail draws it');
        $this->assertSame('default/h.html.twig', $locator->locate('h', 'default')?->logicalName);
    }

    public function testTheChainReachesClassicBeforeDefaultForTheOtherTypes(): void
    {
        $core = $this->base('core', 'classic/a', 'default/a', 'default/b', 'classic/c', 'rss/d', 'classic/d', 'default/d');
        $locator = new TemplateLocator(new TemplateCascade($core));

        $this->assertSame('classic/a.tpl.php', $locator->locate('a', 'rss')?->logicalName, 'classic before default');
        $this->assertSame('default/a.tpl.php', $locator->locate('a', 'default')?->logicalName, 'a web page never draws classic');
        $this->assertSame('default/b.tpl.php', $locator->locate('b', 'json')?->logicalName);
        $this->assertSame('classic/c.tpl.php', $locator->locate('c', 'kml')?->logicalName);
        $this->assertNull($locator->locate('c', 'default'));
        $this->assertSame('rss/d.tpl.php', $locator->locate('d', 'rss')?->logicalName, 'its own type first');
        $this->assertNull($locator->locate('c', 'rss', false), 'no fallback tries the type alone');
    }

    public function testAModernFileWinsForAWebPageOnly(): void
    {
        $core = $this->base('core', 'default/x', 'default/y');
        $plugin = $this->base('plugin', 'modern/x');
        $cascade = new TemplateCascade($core);
        $cascade->addBase($plugin);
        $locator = new TemplateLocator($cascade);

        $this->assertSame('modern/x.tpl.php', $locator->locate('x', 'default')?->logicalName);
        $this->assertSame('modern/x.tpl.php', $locator->locate('x', 'modern')?->logicalName);
        $this->assertSame('default/y.tpl.php', $locator->locate('y', 'default')?->logicalName);
        $this->assertSame('default/x.tpl.php', $locator->locate('x', 'rss')?->logicalName, 'a feed never looks in modern');
    }

    public function testTheOrderIsTypeThenDirectoryThenFormat(): void
    {
        $core = $this->twigBase('core', 'default/a.html.twig', 'default/b.html.twig', 'email/c.html.twig', 'default/c.html.twig');
        $theme = $this->twigBase('theme', 'default/a.tpl.php', 'default/d.tpl.php', 'default/d.html.twig');
        $cascade = new TemplateCascade($core);
        $cascade->addBase($theme);
        $locator = new TemplateLocator($cascade);

        $this->assertSame($theme, $locator->locate('a', 'default')?->base, 'a theme\'s .tpl.php wins over core\'s Twig file');
        $this->assertSame('php', $locator->locate('a', 'default')?->engine);
        $this->assertSame('twig', $locator->locate('b', 'default')?->engine);
        $this->assertSame('email', $locator->locate('c', 'email')?->type, 'the type before the directory');
        $this->assertSame('twig', $locator->locate('d', 'default')?->engine, 'within one directory Twig comes first');
        $this->assertSame($theme . '/templates/default/d.html.twig', $locator->locate('d', 'default')?->path);
    }

    public function testAnAnswerIsRememberedUntilADirectoryIsAdded(): void
    {
        $core = $this->base('core', 'default/x');
        $cascade = new TemplateCascade($core);
        $locator = new TemplateLocator($cascade);
        $this->assertSame($core, $locator->locate('x', 'default')?->base);
        $this->assertNull($locator->locate('later', 'default'));

        // The files of a directory do not change while a request is served: a name found or not stays so...
        file_put_contents($core . '/templates/default/later.tpl.php', 'late');
        unlink($core . '/templates/default/x.tpl.php');
        $this->assertSame($core, $locator->locate('x', 'default')?->base);
        $this->assertNull($locator->locate('later', 'default'));

        // ...until the cascade changes
        $cascade->addBase($this->base('theme', 'default/y'));
        $this->assertNull($locator->locate('x', 'default'));
        $this->assertSame($core, $locator->locate('later', 'default')?->base);
    }

    public function testMissingIsNull(): void
    {
        $locator = new TemplateLocator(new TemplateCascade($this->base('core', 'default/x')));

        $this->assertNull($locator->locate('nope', 'default'));
        $this->assertNull($locator->locate('../x', 'default'), 'a name that is not safe');
        $this->assertNull($locator->locate('x', '../default'), 'nor a type');
        $this->assertNull($locator->locate('', 'default'));
    }

    public function testTypeExists(): void
    {
        $core = $this->base('core', 'default/x');
        $theme = $this->base('theme', 'modern/y');
        $cascade = new TemplateCascade($core);
        $locator = new TemplateLocator($cascade);
        $this->assertFalse($locator->typeExists('modern'));

        $cascade->addBase($theme);

        $this->assertTrue($locator->typeExists('modern'));
        $this->assertTrue($locator->typeExists('default'));
        $this->assertFalse($locator->typeExists('nope'));
        $this->assertFalse($locator->typeExists('../x'));
        $this->assertFalse($locator->typeExists(''));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function slashNames(): array
    {
        return ['leading slash' => ['/x'], 'doubled slash' => ['a//b']];
    }

    /**
     * @dataProvider slashNames
     */
    public function testANameWithALeadingOrDoubledSlashIsAPhpNameOnly(string $name): void
    {
        $twigOnly = $this->twigBase('twigonly', 'default/' . $name . '.html.twig');
        $phpOnly = $this->twigBase('phponly', 'default/' . $name . '.tpl.php');
        $both = $this->twigBase('both', 'default/' . $name . '.html.twig', 'default/' . $name . '.tpl.php');
        $this->assertFileExists($twigOnly . '/templates/default/' . $name . '.html.twig', 'the file system resolves the slashes');

        $this->assertNull((new TemplateLocator(new TemplateCascade($twigOnly)))->locate($name, 'default'), 'a Twig file of such a name is not found, as for any name the loader would refuse');
        $this->assertSame('php', (new TemplateLocator(new TemplateCascade($phpOnly)))->locate($name, 'default')?->engine);
        $this->assertSame('php', (new TemplateLocator(new TemplateCascade($both)))->locate($name, 'default')?->engine, 'the .tpl.php file is drawn');
    }

    public function testTheMemoIsBoundedAndKeysTheFallback(): void
    {
        $core = $this->base('core', 'default/x');
        $locator = new TemplateLocator(new TemplateCascade($core), 3);

        $this->assertSame('default', $locator->locate('x', 'alt')?->type);
        $this->assertNull($locator->locate('x', 'alt', false), 'the answer with the fallback off is not the one with it on');
        $this->assertSame('default', $locator->locate('x', 'alt')?->type);

        $locator->locate('n1', 'default');
        $locator->locate('n2', 'default');
        $locator->locate('n3', 'default');
        $memo = (new \ReflectionProperty($locator, 'remembered'))->getValue($locator);
        $this->assertLessThanOrEqual(3, count($memo), 'past the limit the answers are forgotten');
        file_put_contents($core . '/templates/default/n1.tpl.php', 'late');
        $this->assertNotNull($locator->locate('n1', 'default'), 'a forgotten negative answer is looked up again');
    }
}
