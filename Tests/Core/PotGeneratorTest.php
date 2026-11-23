<?php

namespace Tests\Core;

use Idno\Core\PotGenerator;
use PHPUnit\Framework\TestCase;

class PotGeneratorTest extends TestCase
{

    private PotGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new PotGenerator();
    }

    public function testExtractBasicSingleQuotedString()
    {
        $code = '<?php $language->_(\'Hello World\'); ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertArrayHasKey('Hello World', $strings);
        $this->assertEquals('test.php', $strings['Hello World'][0]['file']);
    }

    public function testExtractBasicDoubleQuotedString()
    {
        $code = '<?php $language->_("Hello World"); ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertArrayHasKey('Hello World', $strings);
    }

    public function testExtractEscapedSingleQuotes()
    {
        $code = "<?php \$language->_('Don\\'t worry'); ?>";
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertArrayHasKey("Don't worry", $strings);
    }

    public function testExtractEscapedDoubleQuotes()
    {
        $code = '<?php $language->_("She said \\"hello\\""); ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertArrayHasKey('She said "hello"', $strings);
    }

    public function testExtractWithSubstitutions()
    {
        $code = '<?php $language->_(\'Hello %s\', [$name]); ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertArrayHasKey('Hello %s', $strings);
    }

    public function testExtractEscMethod()
    {
        $code = '<?php $language->esc_(\'Escaped string\'); ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertArrayHasKey('Escaped string', $strings);
    }

    public function testExtractFullCallChain()
    {
        $code = '<?php \Idno\Core\Idno::site()->language()->_(\'Full chain string\'); ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertArrayHasKey('Full chain string', $strings);
    }

    public function testExtractFullCallChainEsc()
    {
        $code = '<?php \Idno\Core\Idno::site()->language()->esc_(\'Escaped chain\'); ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertArrayHasKey('Escaped chain', $strings);
    }

    public function testExtractMultipleStringsInOneFile()
    {
        $code = '<?php
            $language->_(\'First string\');
            $language->_(\'Second string\');
            $language->esc_(\'Third string\');
        ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertCount(3, $strings);
        $this->assertArrayHasKey('First string', $strings);
        $this->assertArrayHasKey('Second string', $strings);
        $this->assertArrayHasKey('Third string', $strings);
    }

    public function testDeduplicateAcrossFiles()
    {
        $code1 = '<?php $language->_(\'Duplicate\'); ?>';
        $code2 = '<?php $language->_(\'Duplicate\'); ?>';

        $this->generator->extractFromSource($code1, 'file1.php');
        $this->generator->extractFromSource($code2, 'file2.php');

        $strings = $this->generator->getStrings();
        $this->assertCount(1, $strings);
        $this->assertArrayHasKey('Duplicate', $strings);
        $this->assertCount(2, $strings['Duplicate']);
        $this->assertEquals('file1.php', $strings['Duplicate'][0]['file']);
        $this->assertEquals('file2.php', $strings['Duplicate'][1]['file']);
    }

    public function testDoesNotExtractNonMethodCalls()
    {
        // A standalone function _() should not be extracted (only method calls ->_())
        $code = '<?php _(\'Not a method call\'); ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertEmpty($strings);
    }

    public function testDoesNotExtractVariableArguments()
    {
        $code = '<?php $language->_($variable); ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertEmpty($strings);
    }

    public function testDoesNotExtractFromComments()
    {
        $code = '<?php
            // $language->_(\'In a comment\');
            /* $language->_(\'In a block comment\'); */
        ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertEmpty($strings);
    }

    public function testDoesNotExtractWrongMethodName()
    {
        $code = '<?php $obj->translate(\'Not extracted\'); ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertEmpty($strings);
    }

    public function testLineNumberIsCorrect()
    {
        $code = "<?php\n\n\n\$language->_('Line four');\n";
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertArrayHasKey('Line four', $strings);
        $this->assertEquals(4, $strings['Line four'][0]['line']);
    }

    public function testEmptySourceProducesNoStrings()
    {
        $this->generator->extractFromSource('', 'empty.php');
        $this->assertEmpty($this->generator->getStrings());
    }

    public function testNonPhpContentProducesNoStrings()
    {
        $code = '<html><body>Hello</body></html>';
        $this->generator->extractFromSource($code, 'test.html');
        $this->assertEmpty($this->generator->getStrings());
    }

    public function testReset()
    {
        $code = '<?php $language->_(\'string\'); ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $this->assertNotEmpty($this->generator->getStrings());

        $this->generator->reset();
        $this->assertEmpty($this->generator->getStrings());
    }

    public function testGeneratePotFormat()
    {
        $code = '<?php $language->_(\'Test string\'); ?>';
        $this->generator->extractFromSource($code, './test.php');

        $pot = $this->generator->generatePot('testdomain');

        // Check header
        $this->assertStringContainsString('Project-Id-Version: testdomain', $pot);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $pot);
        $this->assertStringContainsString('MIME-Version: 1.0', $pot);

        // Check entry
        $this->assertStringContainsString('#: ./test.php:', $pot);
        $this->assertStringContainsString('msgid "Test string"', $pot);
        $this->assertStringContainsString('msgstr ""', $pot);
    }

    public function testGeneratePotEscapesQuotes()
    {
        $code = '<?php $language->_("String with \\"quotes\\""); ?>';
        $this->generator->extractFromSource($code, 'test.php');

        $pot = $this->generator->generatePot('test');

        $this->assertStringContainsString('msgid "String with \\"quotes\\""', $pot);
    }

    public function testGeneratePotEscapesNewlines()
    {
        $code = '<?php $language->_("Line one\nLine two"); ?>';
        $this->generator->extractFromSource($code, 'test.php');

        $pot = $this->generator->generatePot('test');

        $this->assertStringContainsString('msgid "Line one\\nLine two"', $pot);
    }

    public function testGeneratePotWithoutHeader()
    {
        $code = '<?php $language->_(\'Simple\'); ?>';
        $this->generator->extractFromSource($code, './test.php');

        $pot = $this->generator->generatePotWithoutHeader();

        $this->assertStringNotContainsString('Project-Id-Version', $pot);
        $this->assertStringContainsString('#: ./test.php:', $pot);
        $this->assertStringContainsString('msgid "Simple"', $pot);
        $this->assertStringContainsString('msgstr ""', $pot);
    }

    public function testExtractFromFile()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pot_test_');
        file_put_contents($tmpFile, '<?php $language->_(\'From file\'); ?>');

        $this->generator->extractFromFile($tmpFile, dirname($tmpFile));
        $strings = $this->generator->getStrings();

        unlink($tmpFile);

        $this->assertArrayHasKey('From file', $strings);
    }

    public function testExtractFromDirectory()
    {
        $tmpDir = sys_get_temp_dir() . '/pot_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/one.php', '<?php $language->_(\'String one\'); ?>');
        file_put_contents($tmpDir . '/two.php', '<?php $language->_(\'String two\'); ?>');
        file_put_contents($tmpDir . '/readme.txt', 'Not a PHP file');

        $this->generator->extractFromDirectory($tmpDir);
        $strings = $this->generator->getStrings();

        unlink($tmpDir . '/one.php');
        unlink($tmpDir . '/two.php');
        unlink($tmpDir . '/readme.txt');
        rmdir($tmpDir);

        $this->assertCount(2, $strings);
        $this->assertArrayHasKey('String one', $strings);
        $this->assertArrayHasKey('String two', $strings);
    }

    public function testExtractFromDirectoryRecursive()
    {
        $tmpDir = sys_get_temp_dir() . '/pot_test_' . uniqid();
        mkdir($tmpDir);
        mkdir($tmpDir . '/sub');
        file_put_contents($tmpDir . '/top.php', '<?php $language->_(\'Top level\'); ?>');
        file_put_contents($tmpDir . '/sub/nested.php', '<?php $language->_(\'Nested\'); ?>');

        $this->generator->extractFromDirectory($tmpDir);
        $strings = $this->generator->getStrings();

        unlink($tmpDir . '/top.php');
        unlink($tmpDir . '/sub/nested.php');
        rmdir($tmpDir . '/sub');
        rmdir($tmpDir);

        $this->assertCount(2, $strings);
        $this->assertArrayHasKey('Top level', $strings);
        $this->assertArrayHasKey('Nested', $strings);
    }

    public function testRelativePathInFileReferences()
    {
        $tmpDir = sys_get_temp_dir() . '/pot_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/test.php', '<?php $language->_(\'Relative\'); ?>');

        $this->generator->extractFromFile($tmpDir . '/test.php', $tmpDir);
        $strings = $this->generator->getStrings();

        unlink($tmpDir . '/test.php');
        rmdir($tmpDir);

        $this->assertArrayHasKey('Relative', $strings);
        $this->assertEquals('./test.php', $strings['Relative'][0]['file']);
    }

    public function testHandlesRealWorldPattern()
    {
        // Actual pattern from the Idno codebase
        $code = '<?php
            \Idno\Core\Idno::site()->session()->addErrorMessage(
                \Idno\Core\Idno::site()->language()->_(
                    "Something went wrong."
                )
            );
        ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertArrayHasKey('Something went wrong.', $strings);
    }

    public function testHandlesSprintfPatternWithMultipleArgs()
    {
        $code = '<?php
            \Idno\Core\Idno::site()->language()->_(
                "Plugin %s doesn\'t have a version",
                [get_class($this)]
            );
        ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertArrayHasKey("Plugin %s doesn't have a version", $strings);
    }

    public function testHandlesTemplateContext()
    {
        // Pattern used in .tpl.php template files
        $code = '<?php echo \Idno\Core\Idno::site()->language()->_(\'Skip to main content\'); ?>';
        $this->generator->extractFromSource($code, 'template.tpl.php');
        $strings = $this->generator->getStrings();

        $this->assertArrayHasKey('Skip to main content', $strings);
    }

    public function testHandlesAdjacentCalls()
    {
        $code = '<?php
            $a = $language->_(\'First\');
            $b = $language->_(\'Second\');
        ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertCount(2, $strings);
    }

    public function testStringWithBackslashes()
    {
        $code = '<?php $language->_(\'Path: C:\\\\Users\\\\test\'); ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertArrayHasKey('Path: C:\\Users\\test', $strings);
    }

    public function testStringWithHtmlContent()
    {
        $code = '<?php $language->_(\'Click <a href="url">here</a>\'); ?>';
        $this->generator->extractFromSource($code, 'test.php');
        $strings = $this->generator->getStrings();

        $this->assertArrayHasKey('Click <a href="url">here</a>', $strings);
    }

    public function testPotOutputIsDeterministic()
    {
        $code1 = '<?php $language->_(\'B string\'); ?>';
        $code2 = '<?php $language->_(\'A string\'); ?>';

        $this->generator->extractFromSource($code1, './b.php');
        $this->generator->extractFromSource($code2, './a.php');

        $pot1 = $this->generator->generatePot('test');

        $this->generator->reset();

        $this->generator->extractFromSource($code2, './a.php');
        $this->generator->extractFromSource($code1, './b.php');

        $pot2 = $this->generator->generatePot('test');

        // Both should produce the same output regardless of insertion order
        $this->assertEquals($pot1, $pot2);
    }

    public function testExtractFromMissingFile()
    {
        // Should not throw an exception
        $this->generator->extractFromFile('/nonexistent/file.php');
        $this->assertEmpty($this->generator->getStrings());
    }

    public function testExtractFromEmptyFile()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pot_test_');
        file_put_contents($tmpFile, '');

        $this->generator->extractFromFile($tmpFile);
        unlink($tmpFile);

        $this->assertEmpty($this->generator->getStrings());
    }

    public function testTwigTranslationCallsAreExtracted(): void
    {
        $twig = "<p>{{ t('Hello World') }}</p>\n"
            . "{{ t_html(\"Posted by <a href=\\\"%s\\\">%s</a>\", [url, name]) }}\n"
            . "{{ t(\n    'Split over'\n    , [a]\n) }}\n"
            . "{{ t(variable) }} {{ t('con' ~ 'cat') }} {{ t(\"interp #{x} olated\") }}\n"
            . "{{ item.t('Method call') }} {{ 'Filtered'|t('x') }} {# {{ t('In a comment') }} #}\n"
            . "{{ other('Not a translation') }} {{ 'a string with t(\'Inside a string\')' }}\n"
            . "{% if x %}{{ t('Inside a tag') }}{% endif %}\n";

        $this->generator->extractFromTwigSource($twig, 'x.html.twig');
        $strings = $this->generator->getStrings();

        $this->assertSame(['Hello World', 'Posted by <a href="%s">%s</a>', 'Split over', 'Inside a tag'], array_keys($strings));
        $this->assertSame(1, $strings['Hello World'][0]['line']);
        $this->assertSame(4, $strings['Split over'][0]['line'], 'the line of the string');
        $this->assertSame('x.html.twig', $strings['Hello World'][0]['file']);
    }

    public function testATwigFileThatIsNotATemplateAddsNothing(): void
    {
        $this->generator->extractFromTwigSource("{{ t('Broken", 'broken.html.twig');

        $this->assertSame([], $this->generator->getStrings());
    }

    public function testTwigFilesAreReadFromADirectoryAndTheirReferencesAreRelative(): void
    {
        $directory = sys_get_temp_dir() . '/pot_test_' . uniqid();
        mkdir($directory . '/sub', 0777, true);
        file_put_contents($directory . '/sub/page.html.twig', "{{ t('From twig') }}");
        file_put_contents($directory . '/page.tpl.php', '<?php $this->_(\'From php\'); ?>');
        file_put_contents($directory . '/mail.txt.twig', "{{ t_html('From text twig') }}");

        $this->generator->extractFromDirectory($directory);
        $strings = $this->generator->getStrings();

        unlink($directory . '/sub/page.html.twig');
        unlink($directory . '/page.tpl.php');
        unlink($directory . '/mail.txt.twig');
        rmdir($directory . '/sub');
        rmdir($directory);

        $this->assertSame(['From text twig', 'From php', 'From twig'], array_keys($strings));
        $this->assertSame('./sub/page.html.twig', $strings['From twig'][0]['file']);
    }
}
