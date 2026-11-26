<?php

declare(strict_types=1);

/**
 * Every site page action that takes state-changing requests without the session's form token, with the exact sentence its
 * `#[SitePage(formTokenReplacedBy: ...)]` gives for what authenticates them instead. Read by
 * `Tests\Kernel\FormTokenExemptionsTest`, which fails for an exemption that is not listed here and for a listed one that is gone:
 * adding an exemption is a change to this list, where a reviewer reads its reason.
 *
 * @return array<string, string> `Class::method` => the sentence
 */
return [
    'Tests\Fixtures\FixtureShellController::receive' => 'a test of a shell whose page takes no form token',
    'Tests\Fixtures\SitePageFixtureController::tokenless' => 'a test of the exemption',
];
