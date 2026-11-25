<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\Discovery;

use Idno\Http\Controller\Discovery\SiteFiles;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DiscoveryDoubles.php';

/**
 * Robots.txt, the manifest and the service worker on doubles of the site's icons and a real renderer over a recording drawer.
 */
final class SiteFilesTest extends TestCase
{
    private RecordingDrawer $drawer;

    private function files(?string $projectDir = null, string $display = 'https://example.com/'): SiteFiles
    {
        $this->drawer = DiscoveryDoubles::drawer();

        return new SiteFiles(DiscoveryDoubles::renderer($this->drawer), new FixedIcons($display), $projectDir ?? dirname(__DIR__, 5));
    }

    public function testRobotsIsPlainText(): void
    {
        $response = $this->files()->robots(DiscoveryDoubles::context('/robots.txt'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame("User-agent: *\nDisallow:\n", $response->getContent());
        $this->assertSame([['txt/robots', []]], $this->drawer->templates);
    }

    public function testTheManifestIsByteForByteWhatThePageSent(): void
    {
        $response = $this->files()->manifest(DiscoveryDoubles::context('/chrome/manifest.json'));

        $icons = [];
        foreach (['36', '48', '72', '96', '144', '192'] as $size) {
            $icons[] = ['src' => 'https://example.com/static/logo_' . $size . '.png', 'sizes' => $size . 'x' . $size, 'type' => 'image/png'];
        }
        $expected = json_encode([
            'name' => 'Ada\'s "site"',
            'short_name' => 'Ada\'s "site"',
            'icons' => $icons,
            'start_url' => 'https://example.com/session/login',
            'display' => 'standalone',
            'scope' => '/',
        ], JSON_PRETTY_PRINT);
        $this->assertSame($expected, $response->getContent());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testTheManifestScopeIsTheSitesPathInASubdirectory(): void
    {
        $manifest = json_decode((string) $this->files(null, 'https://example.com/blog/')->manifest(DiscoveryDoubles::context())->getContent(), true);

        $this->assertSame('/blog/', $manifest['scope']);
        $this->assertSame('https://example.com/blog/session/login', $manifest['start_url']);
    }

    public function testBothServiceWorkerSpellingsServeTheCommittedMinFile(): void
    {
        $committed = (string) file_get_contents(dirname(__DIR__, 5) . '/js/service-worker.min.js');
        $this->assertNotSame('', $committed);

        foreach (['/service-worker.js', '/service-worker.min.js'] as $path) {
            $response = $this->files()->serviceWorker(DiscoveryDoubles::context($path));

            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->assertSame('application/javascript', $response->headers->get('Content-Type'), $path);
            $this->assertSame($committed, $response->getContent(), $path);
        }
    }

    public function testAReleaseWithoutTheScriptIsACleanNotFound(): void
    {
        $response = $this->files(sys_get_temp_dir() . '/idno-no-such-project-' . bin2hex(random_bytes(4)))->serviceWorker(DiscoveryDoubles::context('/service-worker.js'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('pages/404', $this->drawer->templates[0][0]);
    }
}
