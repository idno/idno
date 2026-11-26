<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Common\Page;

/**
 * Test-environment only (`/_test/legacy-forward`): a legacy page whose state-changing handlers refuse with `forward()` and then carry on with
 * what a refusal must stop (it echoes `CONTINUED`, standing for the delete or the save that came next). `?exit=0` forwards without ending the
 * page, as a handler that sends the redirect and goes on does.
 */
class LegacyForwardPage extends Page
{
    public function postContent()
    {
        $this->refuse();
    }

    public function putContent()
    {
        $this->refuse();
    }

    public function deleteContent()
    {
        $this->refuse();
    }

    private function refuse(): void
    {
        $this->forward('', $this->getInput('exit') !== '0');
        echo 'CONTINUED';
    }
}
