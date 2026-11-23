<?php

namespace Tests\Core;

use Idno\Core\Idno;

/**
 * Actions::createLink() draws forms/link: a link that submits a hidden form. Its address,
 * class, title and confirmation text are printed escaped (no current caller passes a value
 * that a member or a remote site controls, the settings page that passes the request's own
 * address aside); its label is markup by design and is printed as given.
 */
class CreateLinkOutputTest extends \Tests\IdnoTestCase
{

    private $previousTemplateType;
    private $previousPage;

    public function setUp(): void
    {
        $this->previousTemplateType = Idno::site()->template()->getTemplateType();
        $this->previousPage = Idno::site()->currentPage();
        // forms/link looks at the current page
        Idno::site()->setCurrentPage(new \Idno\Pages\Homepage());
    }

    public function tearDown(): void
    {
        Idno::site()->template()->setTemplateType($this->previousTemplateType);
        Idno::site()->setCurrentPage($this->previousPage ?: new \Idno\Pages\Homepage());
        Idno::site()->template()->extendTemplateWithContent('shell/form-data', '');
    }

    public static function lookProvider(): array
    {
        return ['no theme' => ['default'], 'Twenty26' => ['modern']];
    }

    private function link(string $look, string $url, string $label, array $options): string
    {
        \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/Themes/Twenty26');
        Idno::site()->template()->setTemplateType($look);

        return Idno::site()->actions()->createLink($url, $label, ['a' => 'b'], $options);
    }

    private function parse(string $html): \DOMDocument
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return $doc;
    }

    /**
     * @dataProvider lookProvider
     */
    public function testHostileClassTitleAndConfirmationTextStayInTheirAttributes($look)
    {
        $evil = 'x" onmouseover="alert(1)" data-x="';
        $html = $this->link($look, 'https://example.com/delete/1', 'Delete', [
            'method'       => 'POST',
            'class'        => $evil,
            'title'        => $evil,
            'confirm'      => true,
            'confirm-text' => "It's \"risky\"</script><img src=x onerror=alert(2)>\n",
        ]);

        $anchors = $this->parse($html)->getElementsByTagName('a');
        $this->assertSame(1, $anchors->length);
        $a = $anchors->item(0);
        $names = [];
        foreach ($a->attributes as $attribute) {
            $names[] = $attribute->nodeName;
        }
        sort($names);
        $this->assertSame(['class', 'data-form-id', 'href', 'onclick', 'title'], $names, 'Unexpected attributes in: ' . $html);
        $this->assertSame($evil, $a->getAttribute('class'));
        $this->assertSame($evil, $a->getAttribute('title'));
        $this->assertSame(0, $this->parse($html)->getElementsByTagName('img')->length);

        // The confirmation text is one JavaScript string: the handler parses as it should
        $this->assertMatchesRegularExpression('/^if \(confirm\("(?:[^"\\\\]|\\\\.)*"\)\) \{ /', $a->getAttribute('onclick'));
    }

    /**
     * @dataProvider lookProvider
     */
    public function testHostileAddressIsEscapedOrRefused($look)
    {
        foreach (['https://example.com/a"onmouseover="alert(1)', 'javascript:alert(1)', 'https://example.com/"><img src=x onerror=alert(1)>'] as $url) {
            $html = $this->link($look, $url, 'Go', ['method' => 'POST']);
            $doc = $this->parse($html);

            $this->assertSame(0, $doc->getElementsByTagName('img')->length, "Element injected by $url");
            $a = $doc->getElementsByTagName('a')->item(0);
            foreach ($a->attributes as $attribute) {
                $this->assertStringStartsNotWith('on' . 'mouseover', $attribute->nodeName);
            }
            $this->assertStringNotContainsStringIgnoringCase('javascript:', $a->getAttribute('href'));
            $form = $doc->getElementsByTagName('form')->item(0);
            if ($form) {
                $this->assertStringNotContainsStringIgnoringCase('javascript:', $form->getAttribute('action'));
            }
        }
    }

    /**
     * @dataProvider lookProvider
     */
    public function testOrdinaryLinkWorksAndLabelIsMarkup($look)
    {
        $html = $this->link($look, 'https://example.com/delete/1?a=1&b=2', '<i class="fa fa-trash-o"></i> Delete', [
            'method'       => 'POST',
            'class'        => 'edit edit-delete',
            'title'        => 'Delete this',
            'confirm'      => true,
            'confirm-text' => 'Are you sure?',
        ]);

        $a = $this->parse($html)->getElementsByTagName('a')->item(0);
        $this->assertSame('https://example.com/delete/1?a=1&b=2', $a->getAttribute('href'));
        $this->assertSame('edit edit-delete', $a->getAttribute('class'));
        $this->assertSame('Delete this', $a->getAttribute('title'));
        $this->assertStringContainsString('confirm("Are you sure?")', $a->getAttribute('onclick'));
        $this->assertSame(1, $a->getElementsByTagName('i')->length, 'The label is markup.');
    }
}
