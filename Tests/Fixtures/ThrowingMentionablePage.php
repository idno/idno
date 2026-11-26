<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Common\Page;

/**
 * Test-environment only: a legacy page that takes webmentions and whose constructor fails after `Page::init()` has made it the current page and installed
 * its exception handler.
 */
class ThrowingMentionablePage extends Page
{
    public function __construct()
    {
        parent::__construct();
        throw new \RuntimeException('the constructor fails');
    }

    public function webmentionContent($source, $target, $source_response, $source_mf2)
    {
        return true;
    }
}
