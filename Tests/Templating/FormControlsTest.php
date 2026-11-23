<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * What the form controls leave on the template object they are drawn on, and the counter that gives them ids. Pages and plugin
 * templates that are still `.tpl.php` files set a control's variables on their own template object with `__()` and draw the control;
 * the control takes them off again, so that what the page draws next does not receive them (a spinner took its `id`, an icon its
 * `class`).
 */
final class FormControlsTest extends RenderedSiteTestCase
{
    protected bool $compareGoldens = false;

    public function testAControlDrawnOnAPagesTemplateTakesItsVariablesOffAgain(): void
    {
        $this->seedSite();

        $left = $this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $left = [];
        $controls = [
            'forms/input/text' => ['name' => 'a', 'id' => 'b', 'value' => 'c', 'class' => 'd', 'placeholder' => 'e', 'required' => true, 'type' => 'text', 'onchange' => 'f()', 'accept' => 'g'],
            'forms/input/hidden' => ['name' => 'a', 'value' => 'c', 'class' => 'd'],
            'forms/input/file' => ['name' => 'a', 'accept' => 'image/*', 'class' => 'd'],
            'forms/input/select' => ['name' => 'a', 'options' => ['x' => 'X'], 'value' => 'x', 'class' => 'd', 'blank-default' => false, 'multiple' => true],
            'forms/input/timezones' => ['name' => 'a', 'value' => 'UTC', 'options' => ['x' => 'X']],
            'forms/input/longtext' => ['name' => 'a', 'value' => 'c', 'class' => 'd', 'unique_id' => 'u', 'height' => 3, 'placeholder' => 'p', 'required' => true],
            'forms/usersearch' => ['name' => 'a', 'id' => 'b', 'source-url' => '/s', 'render-template' => 'forms/components/usersearch/user'],
            'forms/output/tags' => ['tags' => ['one']],
        ];
        foreach ($controls as $name => $vars) {
            $template = clone $site->template();
            $template->setTemplateType('default');
            $template->vars = ['keep' => 'kept'];
            $template->__($vars)->draw($name);
            $left[$name] = $template->vars;
        }

        return $left;
        PHP);

        foreach ($left as $name => $variables) {
            $this->assertSame(['keep' => 'kept'], $variables, $name . ' leaves nothing it was given on the template it was drawn on');
        }
    }

    public function testTheControlsCountTheSitesOneCounterForTheIdsTheyGiveThemselves(): void
    {
        $this->seedSite();

        $drawn = $this->runInSite(<<<'PHP'
        global $input_id;
        $input_id = 40;
        $site = \Idno\Core\Idno::site();
        $draw = static function (string $name, array $vars) use ($site): string {
            $template = clone $site->template();
            $template->setTemplateType('default');

            return (string) $template->__($vars)->draw($name);
        };
        $out = [];
        $out[] = $draw('forms/input/text', ['name' => 'first']);
        $out[] = $draw('forms/input/select', ['name' => 'second']);
        $out[] = $draw('forms/input/text', ['name' => 'third', 'id' => 'given']);
        $out[] = $draw('forms/usersearch', ['name' => 'fourth']);
        $out[] = $draw('forms/input/hidden', ['name' => 'fifth']);
        $out[] = $input_id;

        return $out;
        PHP);

        $this->assertStringContainsString('id="first_41"', $drawn[0]);
        $this->assertStringContainsString('id="second_42"', $drawn[1]);
        $this->assertStringContainsString('id="given"', $drawn[2], 'a control that has an id does not count');
        $this->assertStringContainsString('id="fourth_43"', $drawn[3]);
        $this->assertStringContainsString('id="fifth_44"', $drawn[4]);
        $this->assertSame(44, $drawn[5], 'the legacy templates that are still drawn count on the same number');
    }
}
