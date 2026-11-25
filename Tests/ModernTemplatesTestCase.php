<?php

namespace Tests {

    use Idno\Core\Idno;

    /**
     * Base class for a plugin's test of its Twenty26 ("modern") templates.
     *
     * A template that is missing from a plugin's templates/modern directory is not an
     * error: the template of the default look is drawn instead, with Bootstrap and Font
     * Awesome markup that Twenty26 has no styles for and scripts that need jQuery, which
     * Twenty26 does not load. So each plugin checks that the templates it needs exist
     * and that what they draw is Twenty26 markup.
     */
    abstract class ModernTemplatesTestCase extends IdnoTestCase
    {

        /// Entities to remove after each test
        protected $toDelete = [];

        private $previousTemplateType;
        private $previousPage;
        private $previousUser;

        /// Marks of the default look that Twenty26 cannot draw
        const DEFAULT_LOOK_MARKS = ['col-md-', 'class="row', 'fa fa-', 'btn btn-', 'form-control', 'hideContentCreateForm', '$('];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            // Twenty26 only has templates of the "modern" type
            \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/Themes/Twenty26');
        }

        public function setUp(): void
        {
            // The plugin need not be enabled for its templates to be drawn here
            \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/IdnoPlugins/' . $this->plugin());
            $this->previousTemplateType = Idno::site()->template()->getTemplateType();
            $this->previousPage = Idno::site()->currentPage();
            $this->previousUser = $this->swapUser($this->admin());
            Idno::site()->setCurrentPage(new \Idno\Pages\Homepage());
        }

        public function tearDown(): void
        {
            Idno::site()->template()->setTemplateType($this->previousTemplateType);
            Idno::site()->setCurrentPage($this->previousPage ?: new \Idno\Pages\Homepage());
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
            // Leave the session as it was found: later tests depend on who is logged in
            $this->swapUser($this->previousUser);
        }

        /**
         * The name of the plugin under test, as its directory in IdnoPlugins.
         */
        abstract protected function plugin(): string;

        /**
         * The templates the plugin must have in templates/modern, without ".tpl.php".
         *
         * @return string[]
         */
        abstract protected function modernTemplates(): array;

        public function testThePluginHasItsModernTemplates()
        {
            $dir = Idno::site()->config()->path . '/IdnoPlugins/' . $this->plugin() . '/templates/modern/';
            foreach ($this->modernTemplates() as $template) {
                $this->assertFileExists(
                    $dir . $template . '.tpl.php',
                    $this->plugin() . ' has no Twenty26 template "' . $template . '", so the default look\'s is drawn in its place.'
                );
            }
        }

        /**
         * Draw a template in the Twenty26 look.
         */
        protected function drawModern(string $template, array $vars = []): string
        {
            $t = Idno::site()->template();
            $t->setTemplateType('modern');
            $html = (string) $t->__($vars)->draw($template);
            foreach (array_keys($vars) as $name) {
                unset($t->vars[$name]);
            }

            return $html;
        }

        /**
         * Draw a template in the default look (no theme).
         */
        protected function drawDefault(string $template, array $vars = []): string
        {
            $t = Idno::site()->template();
            $t->setTemplateType('default');
            $html = (string) $t->__($vars)->draw($template);
            foreach (array_keys($vars) as $name) {
                unset($t->vars[$name]);
            }

            return $html;
        }

        /**
         * Fail if the markup carries marks of the default look.
         */
        protected function assertIsTwenty26Markup(string $html, string $what): void
        {
            $this->assertNotSame('', trim($html), $what . ' drew nothing.');
            foreach (self::DEFAULT_LOOK_MARKS as $mark) {
                $this->assertStringNotContainsString($mark, $html, $what . ' has "' . $mark . '", which belongs to the default look.');
            }
        }

        /**
         * Fail unless the markup of a compose form has the shared parts of one.
         */
        protected function assertIsComposeForm(string $html, string $what): void
        {
            // The form itself: the shared footer drawn after it (entity/edit/footer) is
            // the default look's in Twenty26 too, and is not the plugin's to change
            $end = strpos($html, '</form>');
            $this->assertNotFalse($end, $what . ' has no form.');
            $html = substr($html, 0, $end);

            $this->assertIsTwenty26Markup($html, $what);
            $this->assertStringContainsString('class="idno-editor"', $html, $what . ' is not in the editor card.');
            $this->assertStringContainsString('idno-editor-heading', $html, $what . ' has no heading.');
            $this->assertStringContainsString('idno-editor-actions', $html, $what . ' has no button row.');
            $this->assertStringContainsString('idno-btn idno-btn-primary', $html, $what . ' has no primary button.');
            $this->assertStringContainsString('name="__bTk"', $html, $what . ' has no form token.');
        }

        /**
         * Save an entity as the logged-in user and remember to delete it.
         */
        protected function keep(\Idno\Common\Entity $entity, string $status = 'published'): \Idno\Common\Entity
        {
            $entity->setAccess('PUBLIC');
            $entity->setPublishStatus($status);
            $this->assertNotEmpty($entity->save(true), 'The ' . get_class($entity) . ' was not saved.');
            $this->toDelete[] = $entity;

            return $entity;
        }
    }

}
