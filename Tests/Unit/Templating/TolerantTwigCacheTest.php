<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\TolerantTwigCache;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Queue\CollectingLogger;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class TolerantTwigCacheTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/idno-tolerant-cache-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function environment(string $directory, CollectingLogger $logger, string $salt = ''): Environment
    {
        // a compiled class stays defined for the life of the process: a template of another source is a fresh compile
        return new Environment(
            new ArrayLoader(['a.html.twig' => 'A {{ x }}' . $salt, 'b.html.twig' => 'B {{ x }}' . $salt]),
            ['cache' => new TolerantTwigCache($directory, static fn () => $logger), 'auto_reload' => true]
        );
    }

    public function testAnUnwritableDirectoryRendersAndWarnsOnce(): void
    {
        // a directory below a plain file can be made by nobody, root included
        file_put_contents($this->dir . '/file', 'x');
        $directory = $this->dir . '/file/twig';
        $logger = new CollectingLogger();
        $twig = $this->environment($directory, $logger, '[unwritable]');

        $this->assertSame('A 1[unwritable]', $twig->render('a.html.twig', ['x' => 1]));
        $this->assertSame('B 2[unwritable]', $twig->render('b.html.twig', ['x' => 2]));

        $this->assertSame([['warning', 'Twig could not write its compiled template cache: ' . $directory]], $logger->lines);
    }

    public function testAWritableDirectoryIsWrittenAndNeverWarnedAbout(): void
    {
        $logger = new CollectingLogger();
        $twig = $this->environment($this->dir . '/cache', $logger, '[written]');

        $this->assertSame('A 1[written]', $twig->render('a.html.twig', ['x' => 1]));

        $this->assertSame([], $logger->lines);
        $this->assertNotSame([], glob($this->dir . '/cache/*/*.php') ?: []);
    }

    public function testALoggerThatIsNeverNeededIsNeverFetched(): void
    {
        $fetched = 0;
        $cache = new TolerantTwigCache($this->dir . '/cache', static function () use (&$fetched) {
            ++$fetched;

            return new CollectingLogger();
        });
        (new Environment(new ArrayLoader(['a.html.twig' => 'A[never]']), ['cache' => $cache]))->render('a.html.twig');

        $this->assertSame(0, $fetched);
    }
}
