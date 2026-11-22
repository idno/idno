<?php

declare(strict_types=1);

namespace Idno\Portability;

/**
 * What an importer did, as the counts it reports. A helper, not a service.
 */
final class ImportResult
{
    /** An import stops, as failed, after this many items in a row that could not be imported (a database that went away). */
    public const MAX_CONSECUTIVE_FAILURES = 25;

    /**
     * The log line for an item that failed: the exception's class and the item's title, with control characters
     * replaced and cut to 80 characters (never the exception's message, which can quote the content).
     */
    public static function failure(\Throwable $e, string $title): string
    {
        return 'An item could not be imported and was skipped (' . $e::class . '): ' . substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $title), 0, 80);
    }

    /**
     * @param bool $stopped the import was stopped after MAX_CONSECUTIVE_FAILURES items in a row failed (the key is
     *                      there only then)
     *
     * @return array{posts: int, comments: int, media: int, skipped: int, stopped?: true}
     */
    public static function counts(int $posts = 0, int $comments = 0, int $media = 0, int $skipped = 0, bool $stopped = false): array
    {
        $counts = ['posts' => $posts, 'comments' => $comments, 'media' => $media, 'skipped' => $skipped];
        if ($stopped) {
            $counts['stopped'] = true;
        }

        return $counts;
    }
}
