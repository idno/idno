<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Portability\ArchiveExporter;
use Idno\Portability\BloggerImporter;
use Idno\Portability\FeedExporter;
use Idno\Portability\ImportedMedia;
use Idno\Portability\ImportMode;
use Idno\Portability\PortabilityJobs;
use Idno\Portability\SiteFacts;
use Idno\Portability\WordPressImporter;
use PHPUnit\Framework\TestCase;

/**
 * The import and export services of Idno\Portability, as the container wires them (config/services.php): they
 * exist, the remote client is fetched at each fetch, the queue's events reach the jobs, and the directory
 * keeps the new-code conventions (no Idno\Legacy, no Idno::site(), no superglobals).
 */
final class PortabilityServicesTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function services(): array
    {
        $classes = [ArchiveExporter::class, FeedExporter::class, WordPressImporter::class, BloggerImporter::class, ImportedMedia::class, PortabilityJobs::class, ImportMode::class, SiteFacts::class];

        return array_combine($classes, array_map(static fn (string $class): array => [$class], $classes));
    }

    /**
     * @dataProvider services
     */
    public function testTheContainerBuildsTheService(string $class): void
    {
        $this->assertInstanceOf($class, Idno::container()->get($class));
    }

    public function testTheModeIsOneSharedFlag(): void
    {
        $this->assertSame(Idno::container()->get(ImportMode::class), Idno::container()->get(ImportMode::class));
        $this->assertFalse(Idno::importInProgress());
        Idno::container()->get(ImportMode::class)->during(function (): void {
            $this->assertTrue(Idno::importInProgress());
            Idno::container()->get(ImportMode::class)->during(fn () => $this->assertTrue(Idno::importInProgress(), 'nested'));
            $this->assertTrue(Idno::importInProgress(), 'still on after the inner one ended');
        });
        $this->assertFalse(Idno::importInProgress());

        try {
            Idno::container()->get(ImportMode::class)->during(static function (): never {
                throw new \LogicException('an import that fails');
            });
        } catch (\LogicException) {
        }
        $this->assertFalse(Idno::importInProgress(), 'put back however the work ends');
    }

    public function testTheRemoteClientIsFetchedAtEachFetchAndNotKept(): void
    {
        $media = Idno::container()->get(ImportedMedia::class);
        $closure = (new \ReflectionProperty($media, 'remoteClient'))->getValue($media);

        $first = $closure();
        $second = $closure();

        $this->assertNotSame($first, $second, 'each fetch gets a new client (its address cache must not outlive it in a worker)');
        $this->assertInstanceOf(\Symfony\Contracts\HttpClient\HttpClientInterface::class, $first);
    }

    public function testTheQueuesEventsReachTheJobs(): void
    {
        // With no listener an event answers true. The jobs answer false for what they could not do (no user).
        $events = Idno::site()->events();

        $this->assertFalse($events->triggerEvent(PortabilityJobs::EXPORT_EVENT, ['user' => '']));
        $this->assertFalse($events->triggerEvent(PortabilityJobs::IMPORT_EVENT, ['user' => '', 'file' => '', 'type' => '']));
    }

    public function testThePortabilityClassesKeepTheNewCodeConventions(): void
    {
        $forbidden = ['Idno\\Legacy', 'Idno::site()', 'Idno::container()', '$_SERVER', '$_GET', '$_POST', '$_FILES', '$_REQUEST', '$_COOKIE', 'error_log(', 'exit;', 'header('];
        $files = glob(dirname(__DIR__, 2) . '/Idno/Portability/*.php') ?: [];

        $this->assertGreaterThan(10, count($files));
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $this->assertStringContainsString('declare(strict_types=1);', $source, basename($file));
            foreach ($forbidden as $text) {
                // A mention in a comment is not a use.
                $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);
                $this->assertStringNotContainsString($text, (string) $code, basename($file) . ' must not use ' . $text);
            }
            if (!str_contains($source, 'interface ')) {
                $this->assertMatchesRegularExpression('/^(final )?class /m', $source, basename($file) . ' is a class');
            }
        }
    }
}
