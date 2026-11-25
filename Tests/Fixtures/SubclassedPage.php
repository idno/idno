<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Test-environment only: a plugin's subclass of the shell (ReplacedPage), which the router serves in place of the shell.
 */
final class SubclassedPage extends ReplacedPage
{
    public function getContent()
    {
        echo $this->wantsMarkdown() ? 'subclass-markdown' : 'subclass';
    }
}
