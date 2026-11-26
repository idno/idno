<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only: the controller of the page whose shell is `FixtureShell`. It makes one call of the service the shell calls. `show` is a
 * permalink (`/_test/fixture-shell/<name>`): the route's last parameter takes the `.md` of the Markdown form.
 */
#[AsController]
final class FixtureShellController
{
    public function __construct(private readonly FixtureShellPages $pages)
    {
    }

    #[Route('/{lead}_test/fixture-shell/{name}{slash}', name: 'idno_test_fixture_shell', requirements: ['lead' => '/?', 'name' => '[^/]+', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'], utf8: false)]
    #[SitePage(name: FixtureShell::class, shell: true, markdownPermalink: true)]
    public function show(PageContext $page): Response
    {
        return $this->pages->draw($page);
    }

    /**
     * The same page under /account/, with the flags of an account and settings page: a signed API request is refused, and Markdown is a title and one line.
     */
    #[Route('/{lead}account/_test-fixture-shell-controller{slash}', name: 'idno_test_fixture_shell_account', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: FixtureAccountShell::class, shell: true, accountPage: true, settingsPage: true)]
    public function showAccount(PageContext $page): Response
    {
        return $this->pages->draw($page);
    }

    /**
     * A page that takes a POST with no form token (an exemption the guard test lists): who made the request, as the page sees it.
     */
    #[Route('/{lead}_test/fixture-shell-tokenless-controller{slash}', name: 'idno_test_fixture_shell_tokenless', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS', 'POST'])]
    #[SitePage(name: FixtureTokenlessShell::class, shell: true, methods: ['GET', 'POST'], formTokenReplacedBy: 'a test of a shell whose page takes no form token')]
    public function receive(PageContext $page): Response
    {
        return $this->pages->receive($page);
    }

    /**
     * A page the action says is served to a visitor of a private site: what decides it on the shell path is the shell's own registration.
     */
    #[Route('/{lead}_test/fixture-shell-public-controller{slash}', name: 'idno_test_fixture_shell_public', requirements: ['lead' => '/?', 'slash' => '/?'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS'])]
    #[SitePage(name: FixturePublicShell::class, shell: true, publicOnPrivateSite: true)]
    public function showPublic(PageContext $page): Response
    {
        return $this->pages->draw($page);
    }
}
