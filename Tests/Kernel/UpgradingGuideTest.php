<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use PHPUnit\Framework\TestCase;

/**
 * What docs/developers/plugins/upgrading.md promises a plugin author: the before and after of its example draw the same markup, a
 * `.tpl.php` file drawn by a Twig template gets a copy of the template object, and a Twig file of a plugin can extend core's shell.
 */
final class UpgradingGuideTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/idno-guide-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/templates/default/guideexample', 0777, true);
        \Idno\Core\Bonita\Main::additionalPath($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/templates/default/guideexample/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory . '/templates/default/guideexample');
        @rmdir($this->directory . '/templates/default');
        @rmdir($this->directory . '/templates');
        @rmdir($this->directory);
    }

    /** The first indented block after the line that holds $marker */
    private function guideBlock(string $marker): string
    {
        $lines = explode("\n", (string) file_get_contents(dirname(__DIR__, 2) . '/docs/developers/plugins/upgrading.md'));
        $start = null;
        foreach ($lines as $index => $line) {
            if (str_contains($line, $marker)) {
                $start = $index;
                break;
            }
        }
        $this->assertNotNull($start, $marker . ' is in the guide');
        $block = [];
        for ($i = $start + 1; $i < count($lines); $i++) {
            if ($block === [] && !str_starts_with($lines[$i], '    ')) {
                continue;
            }
            if ($lines[$i] !== '' && !str_starts_with($lines[$i], '    ')) {
                break;
            }
            $block[] = substr($lines[$i], 4);
        }

        return rtrim(implode("\n", $block)) . "\n";
    }

    private function squash(string $html): string
    {
        return trim((string) preg_replace('~\s+~', ' ', (string) preg_replace('~>\s+<~', '><', $html)));
    }

    public function testTheGuidesBeforeAndAfterDrawTheSameMarkup(): void
    {
        file_put_contents($this->directory . '/templates/default/guideexample/before.tpl.php', $this->guideBlock('as a PHP template:'));
        file_put_contents($this->directory . '/templates/default/guideexample/after.html.twig', $this->guideBlock('and as a Twig template:'));

        $bodies = [
            'plain' => 'Tom &amp; Jerry <b>bold</b> see https://example.com/a?x=1&y=2 #tag @someone' . "\nsecond line",
            'with a picture' => 'Look <img src="https://example.com/p.png"> "quoted" & done',
        ];
        foreach ($bodies as $label => $body) {
            foreach ([[], ['tags' => 'one,two'], ['inreplyto' => 'https://example.org/post']] as $extra) {
                $object = new \Idno\Entities\GenericDataItem();
                $object->body = $body;
                foreach ($extra as $name => $value) {
                    $object->$name = $value;
                }
                $template = Idno::site()->template();
                $before = $template->__(['object' => $object])->draw('guideexample/before');
                $after = $template->__(['object' => $object])->draw('guideexample/after');

                $this->assertNotSame('', trim($before), $label);
                $this->assertStringContainsString('idno-entry-content', $after);
                $this->assertSame($this->squash($before), $this->squash($after), $label . ' ' . json_encode($extra));
            }
        }
    }

    public function testAPhpTemplateDrawnByTwigGetsACopyOfTheTemplateObject(): void
    {
        file_put_contents($this->directory . '/templates/default/guideexample/php.tpl.php', '<?php $this->__(["guide_leak" => "leaked"]); $this->extendTemplate("guideexample/target", "guideexample/extension"); echo "php";');
        file_put_contents($this->directory . '/templates/default/guideexample/outer.html.twig', "{{ idno_include('guideexample/php') }}|{{ guide_leak|default('nothing') }}");

        $template = Idno::site()->template();
        $this->assertSame('php|nothing', $template->draw('guideexample/outer'));
        $this->assertFalse(isset($template->guide_leak) && $template->guide_leak === 'leaked', '__() does not reach the site\'s template');
        $this->assertArrayNotHasKey('guideexample/target', $template->extensions, 'a registration made on the copy is lost with it');
    }

    /**
     * The page itself is drawn over HTTP in `BuiltInLookTest::testAThemeExtendingTheCoreShellReplacesOneBlock`; the cascade does not tell
     * a theme's folder from a plugin's, so here it is enough that a plugin's file extends core's shell and holds its own `nav` block.
     */
    public function testAPluginsTwigFileCanExtendCoresShellAndReplaceABlock(): void
    {
        file_put_contents($this->directory . '/templates/default/guideexample/page.html.twig', "{% extends '@core/default/shell.html.twig' %}{% block nav %}<nav>GUIDE NAV</nav>{% endblock %}");

        $twig = Idno::container()->get('idno.test.twig');
        $page = $twig->load('default/guideexample/page.html.twig');

        $this->assertTrue($page->hasBlock('nav'));
        $this->assertSame('<nav>GUIDE NAV</nav>', $page->renderBlock('nav'));
        foreach (['head', 'content', 'footer'] as $block) {
            $this->assertTrue($page->hasBlock($block), 'core\'s ' . $block . ' block is inherited');
        }
    }
}
