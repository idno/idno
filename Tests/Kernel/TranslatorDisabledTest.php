<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * symfony/translation is installed for its catalogue loaders only. Idno\Core\Language is the site's
 * translator, so FrameworkBundle must not register a second one (it would be enabled by the package alone).
 */
final class TranslatorDisabledTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function environments(): iterable
    {
        yield 'test' => ['test'];
        yield 'prod' => ['prod'];
    }

    /**
     * @dataProvider environments
     */
    public function testFrameworkBundleRegistersNoTranslator(string $environment): void
    {
        $previous = $_SERVER['IDNO_CACHE_DIR'] ?? null;
        $cacheDir = sys_get_temp_dir() . '/idno-test-cache-' . bin2hex(random_bytes(6));
        $_SERVER['IDNO_CACHE_DIR'] = $cacheDir;
        try {
            $kernel = new Kernel($environment, $environment === 'test', bootLegacy: false);
            $kernel->boot();
            $container = $kernel->getContainer();
            $this->assertFalse($container->has('translator'));
            $this->assertFalse($container->has('translator.default'));
            $this->assertFalse($container->has('Symfony\Contracts\Translation\TranslatorInterface'));
            $this->assertFalse($container->hasParameter('translator.default_path'));
        } finally {
            if ($previous === null) {
                unset($_SERVER['IDNO_CACHE_DIR']);
            } else {
                $_SERVER['IDNO_CACHE_DIR'] = $previous;
            }
            self::remove($cacheDir);
        }
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
