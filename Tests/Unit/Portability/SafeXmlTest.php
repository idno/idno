<?php

declare(strict_types=1);

namespace Tests\Unit\Portability;

use Idno\Portability\SafeXml;
use PHPUnit\Framework\TestCase;

final class SafeXmlTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/idno-safexml-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function file(string $contents): string
    {
        $path = $this->directory . '/' . bin2hex(random_bytes(4)) . '.xml';
        file_put_contents($path, $contents);

        return $path;
    }

    public function testLoadsAPlainFile(): void
    {
        $xml = SafeXml::load($this->file('<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Café 🌊</title><item><title><![CDATA[A <b>post</b>]]></title></item></channel></rss>'), 1024);

        $this->assertSame('rss', $xml->getName());
        $this->assertSame('Café 🌊', (string) $xml->channel->title);
        $this->assertSame('A <b>post</b>', (string) $xml->channel->item->title, 'CDATA is read as text');
    }

    public function testALeadingCommentAndProcessingInstructionAreNoProblem(): void
    {
        $xml = SafeXml::load($this->file("\xEF\xBB\xBF<?xml version=\"1.0\"?>\n<!-- generator=\"WordPress/6.5\" -->\n<?xml-stylesheet href=\"x.css\"?>\n<a><b>1</b></a>"), 1024);

        $this->assertSame('1', (string) $xml->b);
    }

    public function testRefusesADoctype(): void
    {
        $path = $this->file('<?xml version="1.0"?><!DOCTYPE rss SYSTEM "http://example.invalid/rss.dtd"><rss><channel/></rss>');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The file declares a document type, which is not accepted.');
        SafeXml::load($path, 1024);
    }

    public function testRefusesADoctypeThatFollowsAComment(): void
    {
        $path = $this->file('<?xml version="1.0"?><!-- harmless --><!DOCTYPE rss [<!ENTITY a "b">]><rss><channel>&a;</channel></rss>');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('document type');
        SafeXml::load($path, 4096);
    }

    public function testRefusesAnEntityDeclaration(): void
    {
        // The expansion bomb: each entity is ten of the one before, so &e; is a billion "lol"s.
        $entities = '<!ENTITY a "lol">';
        $previous = 'a';
        foreach (['b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'] as $name) {
            $entities .= '<!ENTITY ' . $name . ' "' . str_repeat('&' . $previous . ';', 10) . '">';
            $previous = $name;
        }
        $path = $this->file('<?xml version="1.0"?><!DOCTYPE lolz [' . $entities . ']><rss><channel><title>&j;</title></channel></rss>');

        $before = memory_get_peak_usage(true);
        try {
            SafeXml::load($path, 1024 * 1024);
            $this->fail('A file that declares entities must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('document type', $e->getMessage());
        }
        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $before, 'refused before anything is expanded');
    }

    public function testRefusesAnExternalEntityWithoutReadingTheFile(): void
    {
        $secret = $this->file('the secret');
        $path = $this->file('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY secret SYSTEM "file://' . $secret . '">]><rss><channel><title>&secret;</title></channel></rss>');

        $this->expectException(\RuntimeException::class);
        SafeXml::load($path, 4096);
    }

    public function testAMentionOfADoctypeInsideAPostIsNoReasonToRefuseTheFile(): void
    {
        $xml = SafeXml::load($this->file('<?xml version="1.0"?><rss><channel><item><content><![CDATA[<!DOCTYPE html><!ENTITY>]]></content></item></channel></rss>'), 4096);

        $this->assertSame('<!DOCTYPE html><!ENTITY>', (string) $xml->channel->item->content);
    }

    public function testRefusesAFileOverTheLimit(): void
    {
        $path = $this->file('<?xml version="1.0"?><rss><channel>' . str_repeat('<item/>', 100) . '</channel></rss>');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('larger than the limit');
        SafeXml::load($path, filesize($path) - 1);
    }

    public function testAFileOfExactlyTheLimitIsRead(): void
    {
        $path = $this->file('<a/>');

        $this->assertSame('a', SafeXml::load($path, filesize($path))->getName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notXml(): array
    {
        return [
            'text' => ['These are my notes, not an export.'],
            'a tag that is never closed' => ['<?xml version="1.0"?><rss><channel>'],
            'two roots' => ['<a/><b/>'],
            'html with an unclosed tag' => ['<html><body><p>Hello</body></html>'],
            'UTF-16 text' => ["\xFF\xFE<\0a\0/\0>\0"],
        ];
    }

    /**
     * @dataProvider notXml
     */
    public function testRefusesAFileThatIsNotXml(string $contents): void
    {
        $this->expectException(\RuntimeException::class);
        SafeXml::load($this->file($contents), 4096);
    }

    public function testRefusesAnEmptyFileAndAMissingOne(): void
    {
        foreach ([$this->file(''), $this->directory . '/missing.xml'] as $path) {
            try {
                SafeXml::load($path, 4096);
                $this->fail('refused: ' . basename($path));
            } catch (\RuntimeException $e) {
                $this->assertStringNotContainsString($this->directory, $e->getMessage(), 'the message names no path');
            }
        }
    }

    public function testLibxmlsErrorHandlingIsPutBackAsItWas(): void
    {
        foreach ([true, false] as $state) {
            libxml_use_internal_errors($state);
            try {
                SafeXml::load($this->file('not xml'), 100);
            } catch (\RuntimeException) {
            }
            $this->assertSame($state, libxml_use_internal_errors(), 'after a refusal');
            SafeXml::load($this->file('<a/>'), 100);
            $this->assertSame($state, libxml_use_internal_errors(), 'after a success');
        }
        libxml_use_internal_errors(false);
    }
}
