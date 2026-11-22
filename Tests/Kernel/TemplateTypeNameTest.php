<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\DefaultTemplate;
use Tests\IdnoTestCase;

/**
 * The name a template object keeps for its type: `default` for the web page in any letter case, and for `modern`, which is
 * the web page; `classic` is refused in any letter case.
 */
final class TemplateTypeNameTest extends IdnoTestCase
{
    /** @return array<string, array{string}> */
    public static function webPageNames(): array
    {
        return ['lower' => ['default'], 'upper' => ['DEFAULT'], 'mixed' => ['Default'], 'modern' => ['modern'], 'MODERN' => ['MODERN']];
    }

    /** @dataProvider webPageNames */
    public function testTheWebPageIsStoredAsDefaultInAnyLetterCase(string $name): void
    {
        $template = new DefaultTemplate();
        $template->setTemplateType('rss');

        $this->assertTrue($template->setTemplateType($name));
        $this->assertSame('default', $template->getTemplateType());
    }

    public function testClassicIsRefusedInAnyLetterCase(): void
    {
        $template = new DefaultTemplate();
        $template->setTemplateType('rss');

        foreach (['classic', 'CLASSIC', 'Classic'] as $name) {
            $this->assertFalse($template->setTemplateType($name), $name);
            $this->assertSame('rss', $template->getTemplateType(), $name);
        }
    }
}
