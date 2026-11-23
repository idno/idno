<?php

namespace Tests\Core {

    use Idno\Core\Bonita\Templates;

    /**
     * A template variable must never decide which file is included.
     */
    class TemplateIncludeTest extends \Tests\IdnoTestCase
    {
        protected static $other;

        public static function setUpBeforeClass(): void
        {
            $dir = __DIR__ . '/fixtures/template_include';
            \Idno\Core\Bonita\Main::additionalPath($dir);
            self::$other = $dir . '/other.php';
        }

        function namesProvider()
        {
            return [['path'], ['k'], ['v'], ['vars'], ['t']];
        }

        /**
         * @dataProvider namesProvider
         */
        function testVariableNameCannotChangeIncludedFile($name)
        {
            $t = new Templates();
            $t->setTemplateType('default');
            // set through the array: __() would assign to the real $vars property for 'vars'
            $t->vars[$name] = self::$other;
            $out = $t->draw('tplinc/located');
            $this->assertStringStartsWith('LOCATED', $out);
            $this->assertStringNotContainsString('OTHER-FILE-INCLUDED', $out);
        }

        function testTemplateSeesCallersPathVariable()
        {
            $t = new Templates();
            $t->__(['path' => '/caller/value']);
            $this->assertEquals('LOCATED|path=/caller/value', trim($t->draw('tplinc/located')));
        }

        function testNestedDrawsKeepTheirOwnFile()
        {
            $t = new Templates();
            $t->__(['path' => self::$other]);
            $this->assertEquals('OUTER[LOCATED|path=' . self::$other . ']' . self::$other, trim($t->draw('tplinc/outer')));
        }

        function testThrowingTemplateDoesNotBreakLaterDraws()
        {
            $t = new Templates();
            $t->__(['path' => self::$other]);
            $level = ob_get_level();
            try {
                $t->draw('tplinc/throws');
                $this->fail('expected exception');
            } catch (\RuntimeException $e) {
                // the buffer a throwing template leaves open is not what is tested here
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
                $this->assertEquals('tplinc boom', $e->getMessage());
            }
            $out = $t->draw('tplinc/located');
            $this->assertStringStartsWith('LOCATED', $out);
        }
    }
}
