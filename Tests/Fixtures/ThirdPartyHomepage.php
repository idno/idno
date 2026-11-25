<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Pages\Homepage;

/**
 * Test-environment only: what a third-party plugin wrote when the home page was a legacy class of core's: a subclass of it, registered for
 * the root (`hijackRoute()`) or for a path of its own (`addRoute()`) under IDNO_TEST_CLAIM_ROUTES (`homepage-hijack`, `homepage-route`). It
 * overrides nothing: the home page it serves is the shell's, which draws it through the service the controller calls. Its constructor gives
 * the page an input after the parent's, as a plugin's page that prepared its page did.
 */
class ThirdPartyHomepage extends Homepage
{
    public function __construct()
    {
        parent::__construct();
        $this->setInput('fixture_subclass', '1');
    }
}
