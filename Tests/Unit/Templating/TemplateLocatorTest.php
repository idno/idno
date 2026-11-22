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
}
