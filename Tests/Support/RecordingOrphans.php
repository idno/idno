<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Finds recordings that no test compares. A class that uses `RecordedAnswers` names each recording it compares by a literal group and a literal
 * case (`'conditional'`, `'legacy-304'`), so the check reads the test sources: a group directory that no using class names as a quoted literal is
 * an orphan (`<group>/`), and so is a `.txt` file whose name no using class names as a quoted literal (`<group>/<case>.txt`). It reads text, not a
 * run, so it is reliable however a class was filtered or ordered. The tests of the trait itself (`Tests/Unit/Support/`) are not using classes: their
 * literals would hide a real orphan.
 */
final class RecordingOrphans
{
    /** @return list<string> */
    public static function find(string $answersDirectory, string $testsDirectory): array
    {
        $sources = '';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($testsDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Unit/Support/')) {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            if (preg_match('/\buse\s+(?:\\\\?Tests\\\\Support\\\\)?RecordedAnswers\s*;/', $text) === 1) {
                $sources .= $text . "\n";
            }
        }
        $orphans = [];
        foreach (glob($answersDirectory . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $group = basename($directory);
            if (!str_contains($sources, "'" . $group . "'")) {
                $orphans[] = $group . '/';

                continue;
            }
            foreach (glob($directory . '/*') ?: [] as $file) {
                $case = pathinfo($file, PATHINFO_FILENAME);
                if (pathinfo($file, PATHINFO_EXTENSION) !== 'txt' || !str_contains($sources, "'" . $case . "'")) {
                    $orphans[] = $group . '/' . basename($file);
                }
            }
        }
        sort($orphans);

        return $orphans;
    }
}
