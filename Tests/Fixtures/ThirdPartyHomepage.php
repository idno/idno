<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Pages\Homepage;

/**
 * Test-environment only: what a third-party plugin wrote when the home page was a legacy class of core's: a subclass of it, registered for
 * the root (`hijackRoute()`) or for a path of its own (`addRoute()`) under IDNO_TEST_CLAIM_ROUTES (`homepage-hijack`, `homepage-route`). The
 * home page it serves is the shell's, which draws it through the service the controller calls. Its constructor gives the page an input after
 * the parent's, as a plugin's page that prepared its page did, and its `getContent()` goes on after the parent's: the legacy home page
 * ended the request when it drew, so what follows (a marker, a second page) must never be sent.
 */
class ThirdPartyHomepage extends Homepage
{
    public const AFTER_PARENT = '<!--after the parent drew the page-->';

    public function __construct()
    {
        parent::__construct();
        $this->setInput('fixture_subclass', '1');
    }

    public function getContent()
    {
        parent::getContent();
        echo self::AFTER_PARENT;
        parent::getContent();
    }
}
