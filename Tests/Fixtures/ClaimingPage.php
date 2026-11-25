<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Common\Page;

/**
 * Test-environment only: a legacy page a "plugin" registers for a path a controller serves (`addRoute()` or `hijackRoute()` of
 * `/_test/claimable/?`, or `addRoute()` of `/_test/unclaimed/?`, under IDNO_TEST_CLAIM_ROUTES), which must keep winning.
 */
class ClaimingPage extends Page
{
    public function getContent()
    {
        echo 'claimed';
    }

    public function postContent()
    {
        echo 'claimed-post';
    }

    public function putContent()
    {
        echo 'claimed-put';
    }

    public function deleteContent()
    {
        echo 'claimed-delete';
    }
}
