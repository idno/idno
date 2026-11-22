<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\Bonita\Templates;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Test-environment only (config/services_test.php registers it as a `kernel.request` listener): when the
 * environment variable IDNO_TEST_TEMPLATE_TRACE names a file, every template draw of the request appends one
 * line to it: the template type asked for, the template's name and the file the draw found, relative to the
 * project (`-` when no file was found), separated by tabs, in the order the draws begin. With the variable
 * unset the hook is not installed: nothing is called and nothing is written.
 */
final class TemplateTrace
{
    public function __invoke(RequestEvent $event): void
    {
        $file = getenv('IDNO_TEST_TEMPLATE_TRACE');
        if (!is_string($file) || $file === '') {
            return;
        }
        self::install($file, dirname(__DIR__, 2));
    }

    /**
     * Installs the hook: each draw appends a line to $file (under LOCK_EX: the web server has several workers).
     */
    public static function install(string $file, string $projectDirectory): void
    {
        Templates::$trace = static function (string $type, string $name, string $path) use ($file, $projectDirectory): void {
            if ($path !== '-' && str_starts_with($path, $projectDirectory . '/')) {
                $path = substr($path, strlen($projectDirectory) + 1);
            }
            file_put_contents($file, $type . "\t" . $name . "\t" . $path . "\n", FILE_APPEND | LOCK_EX);
        };
    }

    public static function uninstall(): void
    {
        Templates::$trace = null;
    }
}
