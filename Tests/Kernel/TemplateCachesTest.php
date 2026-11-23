<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * What rendering writes to disk, and where: the HTML purifier's serialised definitions and Twig's compiled templates (PHP
 * files that are included) go to the kernel's cache directory, never to the upload path (which is served, and may be an
 * s3:// address) or a shared temporary directory.
 */
final class TemplateCachesTest extends PrivateSiteTestCase
{
    public function testNothingIsWrittenUnderTheUploadPath(): void
    {
        $cookie = $this->registerUser('writer');
        $permalink = $this->publishStatus($cookie, '<p onclick="x()">A <b>purified</b> post <script>alert(1)</script></p>');
        $page = $this->site()->request('GET', substr($permalink, strlen($this->baseUrl())));
        $this->assertSame(200, $page->status, $this->serverLog());
        $this->assertStringContainsString('purified', $page->body);
        $this->assertStringNotContainsString('<script>alert(1)', $page->body, 'the text was purified');

        $uploadPath = $this->runInSite('return rtrim((string) \Idno\Core\Idno::site()->config()->uploadpath, "/");');
        $this->assertIsString($uploadPath);
        $this->assertDirectoryExists($uploadPath);
        $entries = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploadPath, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $entries[] = substr($entry->getPathname(), strlen($uploadPath) + 1);
        }
        $caches = array_values(array_filter($entries, static fn (string $entry): bool => stripos($entry, 'HTMLPurifier') !== false || stripos($entry, 'twig') !== false || str_ends_with($entry, '.ser')));
        $this->assertSame([], $caches, 'no purifier, Twig or serialised cache entry under the upload path');

        $purifierDirectory = $this->runInSite('return \Idno\Core\Idno::container()->get("kernel")->getCacheDir() . "/htmlpurifier";');
        $this->assertDirectoryExists($purifierDirectory, 'the purifier\'s cache is in the kernel\'s cache directory');
    }

    public function testThePurifierCacheIsWrittenInTheKernelCacheDirectory(): void
    {
        $cookie = $this->registerUser('writer');
        $permalink = $this->publishStatus($cookie, '<p onclick="x()">A <b>purified</b> post</p>');
        $this->assertSame(200, $this->site()->request('GET', substr($permalink, strlen($this->baseUrl())))->status, $this->serverLog());

        $directory = $this->runInSite('return \Idno\Core\Idno::container()->get("kernel")->getCacheDir() . "/htmlpurifier";');
        $this->assertIsString($directory);
        $serialised = [];
        if (is_dir($directory)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.ser')) {
                    $serialised[] = $file->getPathname();
                }
            }
        }
        $this->assertNotSame([], $serialised, 'a serialised definition was written in the kernel\'s cache directory');
    }

    public function testTwigsCompiledTemplatesGoToTheKernelCacheDirectory(): void
    {
        $result = $this->runInSite('
            $container = \Idno\Core\Idno::container();
            $kernel = $container->get("kernel");
            $twig = $container->get("idno.test.twig");
            return [
                "cacheClass" => get_class($twig->getCache(false)),
                "twig" => $twig->getCache(false)->generateKey("a/b.html.twig", "Cls"),
                "cacheDir" => $kernel->getCacheDir(),
                "upload" => rtrim((string) \Idno\Core\Idno::site()->config()->uploadpath, "/"),
            ];
        ');
        $this->assertIsArray($result);
        $this->assertSame(\Idno\Templating\TolerantTwigCache::class, $result['cacheClass']);
        $this->assertStringStartsWith($result['cacheDir'] . '/twig/', $result['twig'], 'the compiled templates are in the kernel\'s cache directory, trusted as the compiled container is');
        $this->assertFalse(str_starts_with($result['twig'], $result['upload'] . '/'), 'and not in the upload path');
        $this->assertFalse(str_starts_with($result['twig'], sys_get_temp_dir() . '/') && !str_starts_with($result['cacheDir'], sys_get_temp_dir() . '/'), 'nor a shared temporary directory');
    }
}
