<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * What rendering writes to disk, and where: the HTML purifier's serialised definitions go to the kernel's cache
 * directory, never to the upload path (which is served, and may be an s3:// address).
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
}
