<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\Profile\ProfilePages;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\RoutedByLegacyTable;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * A member's profile page, at `/profile/<handle>` (and its friendly content paths) and at `/actor/<handle>` where the ActivityPub plugin does not take that
 * address. It has no `#[Route]`: core registers the patterns in the legacy route table, where a plugin's registration decides what it does to them, and the
 * table says which requests are the profile's and hands them to this action (`#[RoutedByLegacyTable]`). The class `Idno\Pages\User\View`, kept as a
 * deprecated shell, names this action and serves what the controller does not take: the edit form's POST.
 */
#[AsController]
final class ProfileController
{
    public function __construct(private readonly ProfilePages $pages)
    {
    }

    #[SitePage(name: 'Idno\Pages\User\View', shell: true)]
    #[RoutedByLegacyTable(route: 'idno_profile')]
    public function view(PageContext $page): Response
    {
        return $this->pages->view($page);
    }
}
