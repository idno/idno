<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\DefaultTemplate;
use Idno\Templating\LocatedTemplate;
use Idno\Templating\PhpTemplateContext;
use Idno\Templating\PhpTemplateEngine;
use Idno\Templating\SlotRegistry;
use Idno\Templating\TemplateEngine;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\TwigPortCheck;

/**
 * The harness that proves a Twig port against its original, with the site's template object (a legacy class that needs the site): a
 * link registers its form, and an avatar leaves its user, through that object.
 */
final class TwigPortCheckSiteTemplateTest extends TestCase
{
    private string $project = '';

    protected function setUp(): void
    {
        TwigPortCheck::forget();
        $this->project = sys_get_temp_dir() . '/idno-port-check-site-' . bin2hex(random_bytes(6));
        mkdir($this->project . '/templates/default', 0777, true);
        file_put_contents($this->project . '/templates/default/probe.html.twig', 'twig source: the double renders, this is not read');
        $_SERVER['IDNO_TEST_TWIG_PORT_REPORT'] = $this->project . '/report.jsonl';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['IDNO_TEST_TWIG_PORT_REPORT'], $GLOBALS['port_check_probe']);
        foreach (glob($this->project . '/templates/default/*') ?: [] as $file) {
            unlink($file);
        }
        foreach ([$this->project . '/report.jsonl', $this->project . '/templates/default', $this->project . '/templates', $this->project] as $path) {
            is_dir($path) ? rmdir($path) : @unlink($path);
        }
    }

    /**
     * While the original renders, the site's template object is the copy the original draws on (what the original does to it stays with
     * the copy, and what the port did before is not seen by the original), and afterwards it is exactly as the port left it.
     */
    public function testTheOriginalRendersFromWhatWasRegisteredBeforeThePortAndLeavesTheSiteTemplateAsThePortLeftIt(): void
    {
        $live = new DefaultTemplate();
        $live->extendTemplateWithContent('slot', 'before;');
        $live->__(['page' => 'p']);
        $slots = static fn (DefaultTemplate $template): SlotRegistry => (new \ReflectionProperty(DefaultTemplate::class, 'slots'))->getValue($template);
        $seen = [];
        $GLOBALS['port_check_probe'] = static function (DefaultTemplate $context) use ($live, $slots, &$seen): void {
            $live->extendTemplateWithContent('slot', 'by-original;');
            $live->__(['leak' => 'by-original']);
            $seen = ['content' => $slots($context)->content('slot'), 'leak' => $context->vars['leak'] ?? null, 'page' => $context->vars['page'] ?? null];
        };
        file_put_contents($this->project . '/templates/default/probe.tpl.php', '<?php ($GLOBALS["port_check_probe"])($this);');
        $twig = new class ($live) implements TemplateEngine {
            public function __construct(private readonly DefaultTemplate $live)
            {
            }

            public function render(LocatedTemplate $template, mixed $vars, PhpTemplateContext $context): string
            {
                $this->live->extendTemplateWithContent('slot', 'by-port;');
                $this->live->__(['by_port' => true]);

                return '';
            }
        };
        $check = new TwigPortCheck($twig, new PhpTemplateEngine(), 'http://127.0.0.1:1/', $this->project, [], static fn (): object => $live);
        $located = new LocatedTemplate($this->project . '/templates/default/probe.html.twig', 'twig', 'default', $this->project, 'default/probe.html.twig');

        $check->render($located, ['page' => 'p'], $live);

        $this->assertSame('before;by-original;', $seen['content'], 'the original saw what was registered before the port rendered, and what it registered itself');
        $this->assertSame('by-original', $seen['leak'], 'what the original left on the site\'s template object is on its copy');
        $this->assertSame('p', $seen['page']);
        $this->assertSame('before;by-port;', $slots($live)->content('slot'), 'the site\'s template has what the port registered, and nothing the original did');
        $this->assertArrayNotHasKey('leak', $live->vars);
        $this->assertTrue($live->vars['by_port']);
        $this->assertSame('p', $live->vars['page']);
    }
}
