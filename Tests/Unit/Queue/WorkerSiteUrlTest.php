<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Idno\Queue\WorkerSiteUrl;
use PHPUnit\Framework\TestCase;

/**
 * The queue worker never guesses the site's address: it needs an explicit, absolute `url` in the boot
 * configuration (config.ini, config.json or IDNO_URL).
 */
final class WorkerSiteUrlTest extends TestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function acceptable(): array
    {
        return [
            'https' => ['https://example.com/'],
            'http' => ['http://example.com/'],
            'a port' => ['http://127.0.0.1:8089/'],
            'a subdirectory' => ['https://example.com/blog/'],
            'upper-case scheme' => ['HTTPS://example.com/'],
        ];
    }

    /**
     * @dataProvider acceptable
     */
    public function testAnExplicitAbsoluteUrlIsAccepted(mixed $url): void
    {
        $this->assertNull(WorkerSiteUrl::problem($url));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function refused(): array
    {
        return [
            'none' => [null],
            'empty' => [''],
            'blank' => ['   '],
            'no scheme' => ['example.com/'],
            'a scheme-less host and port' => ['//example.com/'],
            'relative root (what detection falls back to)' => ['/'],
            'relative path' => ['blog/'],
            'another scheme' => ['ftp://example.com/'],
            'no host' => ['https:///path'],
            'only a scheme' => ['https://'],
            'not a string' => [['https://example.com/']],
            'true' => [true],
        ];
    }

    /**
     * @dataProvider refused
     */
    public function testAMissingOrRelativeUrlIsRefusedWithTheTwoWaysToSetIt(mixed $url): void
    {
        $message = WorkerSiteUrl::problem($url);

        $this->assertIsString($message);
        $this->assertStringContainsString('url = "https://example.com/"', $message);
        $this->assertStringContainsString('configuration/config.ini', $message);
        $this->assertStringContainsString('IDNO_URL=https://example.com/', $message);
        $this->assertStringContainsString('the address visitors use', $message);
        $this->assertStringContainsString('scheme', $message);
    }
}
