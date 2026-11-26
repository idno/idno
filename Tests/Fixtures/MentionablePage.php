<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Common\Page;

/**
 * Test-environment only: a legacy page a "plugin" registers at `/_test/mentionable/:id/?` (IDNO_TEST_MENTIONABLE=1) that takes every webmention. It
 * appends the arguments it was called with, and whether it was marked a permalink, to the file IDNO_TEST_MENTION_RECORD names, as one JSON line.
 */
class MentionablePage extends Page
{
    public function getContent()
    {
        echo 'mentionable';
    }

    public function webmentionContent($source, $target, $source_response, $source_mf2)
    {
        $file = getenv('IDNO_TEST_MENTION_RECORD');
        if (is_string($file) && $file !== '') {
            file_put_contents($file, json_encode([
                'arguments' => $this->arguments,
                'permalink' => $this->isPermalink(),
                'source' => $source,
                'target' => $target,
                'response' => $source_response,
                'mf2' => $source_mf2,
            ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        }

        return true;
    }
}
