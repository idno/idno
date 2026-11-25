<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Common\MarkdownPermalink;
use Idno\Common\Page;

/**
 * Test-environment only: a legacy page standing for a shell, a core page class that a controller replaces and the router still
 * registers (`/_test/claimable/?`, under IDNO_TEST_CLAIM_ROUTES). Each method answers its own name, so a test sees which page and
 * which method served a request. It is the permalink of a Markdown form (the `.md` suffix is routed to it).
 */
class ReplacedPage extends Page implements MarkdownPermalink
{
    public function getContent()
    {
        echo $this->wantsMarkdown() ? 'legacy-markdown' : 'legacy';
    }

    public function postContent()
    {
        echo 'legacy-post';
    }

    public function putContent()
    {
        echo 'legacy-put';
    }

    public function deleteContent()
    {
        echo 'legacy-delete';
    }
}
