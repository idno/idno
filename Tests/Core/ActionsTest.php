<?php

namespace Tests\Core {

    use Idno\Core\Actions;
    use Idno\Core\Idno;

    /**
     * What drawing an action link or form leaves behind: nothing on the site's template object (their variables, a
     * link's `label` and `data` or a form's `body`, would otherwise be seen by whatever the page draws next).
     * signForm() leaves nothing either: no `csrf` list (the JSON output would print it).
     */
    class ActionsTest extends \Tests\IdnoTestCase
    {
        private $previousPage;

        protected function setUp(): void
        {
            parent::setUp();
            $this->previousPage = Idno::site()->currentPage();
            Idno::site()->setCurrentPage(new \Idno\Pages\Homepage()); // the link template asks whether the page is an XHR one
        }

        protected function tearDown(): void
        {
            Idno::site()->setCurrentPage($this->previousPage ?: new \Idno\Pages\Homepage());
            parent::tearDown();
        }

        public function testCreateLinkLeavesTheSiteTemplatesVariablesAlone(): void
        {
            $template = Idno::site()->template();
            $before = $template->vars;

            $link = (new Actions())->createLink('/status/delete', 'Remove this', ['uuid' => 'abc'], ['class' => 'x']);
            $form = (new Actions())->createForm('/status/delete', '<p>the body</p>');

            $this->assertStringContainsString('Remove this', $link, 'the link was drawn');
            $this->assertStringContainsString('the body', $form, 'the form was drawn');
            $this->assertSame($before, $template->vars, 'and neither left a variable on the site\'s template');
        }

        /**
         * The rule: a helper publishes on the site's template object only the keys that are part of a page's JSON output
         * (`items_per_page` for the pagination, `services`, `content_type` and `posseLinks` for the syndication control), and
         * what it, `createLink()` and `createForm()` use while drawing stays on the copy.
         */
        public function testTheHelpersPublishOnlyTheKeysTheJsonOutputHasAndNothingElse(): void
        {
            $template = Idno::site()->template();
            $before = array_keys($template->vars);

            $pagination = $template->drawPagination(100, 10, ['control-id' => 'c1', 'source-url' => '/x']);
            $syndication = $template->drawSyndication('note', []);
            (new Actions())->createLink('/status/delete', 'Remove', ['uuid' => 'abc'], ['class' => 'x', 'title' => 'T']);
            (new Actions())->createForm('/status/delete', '<p>b</p>');

            $this->assertNotSame('', $pagination, 'the pagination was drawn');
            $this->assertIsString($syndication);
            $added = array_values(array_diff(array_keys($template->vars), $before));
            sort($added);
            $this->assertSame(['content_type', 'items_per_page', 'posseLinks', 'services'], $added);
            $this->assertSame(10, $template->vars['items_per_page']);
            $this->assertSame('note', $template->vars['content_type']);
            foreach (['url', 'label', 'class', 'data', 'action', 'method', 'body', 'count', 'offset', 'title', 'confirm'] as $leaked) {
                $this->assertArrayNotHasKey($leaked, $template->vars, $leaked . ' stays on the copy');
            }
            unset($template->vars['items_per_page'], $template->vars['content_type'], $template->vars['posseLinks'], $template->vars['services']);
        }
    }
}
