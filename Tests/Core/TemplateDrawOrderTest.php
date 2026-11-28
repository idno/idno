<?php

namespace Tests\Core {

    /**
     * The order in which a template is drawn with what plugins and themes registered for it.
     * Every plugin and theme is written against this order (a stylesheet added by extending
     * shell/head loads before the ones the shell's own template prints, for example), so a
     * change of it rearranges their pages; the docblocks of \Idno\Core\Template state it.
     */
    class TemplateDrawOrderTest extends \Tests\IdnoTestCase
    {
        public static function setUpBeforeClass(): void
        {
            \Idno\Core\Bonita\Main::additionalPath(__DIR__ . '/fixtures/template_order');
        }

        private function template()
        {
            // A copy of the site's template object: what is registered here stays here, and no
            // second template object is constructed (each one adds a listener to the site's events)
            $t = clone \Idno\Core\Idno::site()->template();
            $t->setTemplateType('default');

            return $t;
        }

        /**
         * An extension's output comes before the template it extends, a prepended template
         * before both, and pre-rendered content last.
         */
        function testExtensionsAreDrawnBeforeTheTemplateAndContentAfterIt()
        {
            $t = $this->template();
            $t->extendTemplateWithContent('tplorder/base', '[content]');
            $t->extendTemplate('tplorder/base', 'tplorder/first');
            $t->extendTemplate('tplorder/base', 'tplorder/second');
            $t->prependTemplate('tplorder/base', 'tplorder/prepend');

            $this->assertSame('[prepend][first][second][base][content]', $t->draw('tplorder/base'));
        }

        /**
         * A replaced template is still drawn with its extensions, after the replacement:
         * the ones for every template type (one put to the front first), then the ones
         * for the current type.
         */
        function testReplacedTemplateKeepsItsExtensions()
        {
            $t = $this->template();
            $t->extendTemplate('tplorder/base', 'tplorder/typed', false, 'default');
            $t->extendTemplate('tplorder/base', 'tplorder/first');
            $t->extendTemplate('tplorder/base', 'tplorder/front', true);
            $t->replaceTemplate('tplorder/base', 'tplorder/replacement');

            $this->assertSame('[replacement][front][first][typed]', $t->draw('tplorder/base'));
            // Asked not to honour replacements, the template itself is drawn, after its extensions
            $this->assertSame('[front][first][typed][base]', $t->draw('tplorder/base', true, false));
        }
    }
}
