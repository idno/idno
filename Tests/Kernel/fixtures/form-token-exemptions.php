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
    'Idno\Http\Controller\WebmentionController::__invoke' => 'Webmention (W3C): the sender is anonymous; the source page is fetched through the remote client and must link to the target, which must be a page of this site that takes mentions',
    'Idno\Http\Controller\WebSubController::__invoke' => 'WebSub content distribution: the hub is another server with no session; a delivery is accepted only for a subscription the subscriber really has, and one made with a secret only with a valid X-Hub-Signature; the body is only handed to the listeners of websub/received',
    'Tests\Fixtures\FixtureShellController::receive' => 'a test of a shell whose page takes no form token',
    'Tests\Fixtures\SitePageFixtureController::tokenless' => 'a test of the exemption',
];
