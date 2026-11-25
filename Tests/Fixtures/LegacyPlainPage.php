<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Common\Page;

/**
 * Test-environment only (`/_test/legacy-plain`): a legacy page that does nothing in its handler but answer in plain text, as the pages
 * that are controllers' now once did (`robots.txt` was one). The lifecycle tests hold a controller's page to a real legacy page with it.
 */
class LegacyPlainPage extends Page
{
    public function getContent()
    {
        // `?throw=1`: a legacy page that fails, for the legacy exception handler's own 500 page
        if ($this->getInput('throw')) {
            throw new \RuntimeException('the legacy page broke');
        }
        header('Content-type: text/plain');
        echo "plain\n";
    }
}
