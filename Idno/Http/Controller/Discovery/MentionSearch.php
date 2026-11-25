<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Discovery;

use Idno\Http\Page\PageContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * The @-mention search: the members' handles, names and avatars as JSON, for the editor's picker. It is open to whoever may see the site (the
 * walled garden refuses a visitor of a private site, as for every page that is not public) and gives nothing but those three values.
 */
final class MentionSearch
{
    public function __construct(private readonly MentionCandidates $candidates)
    {
    }

    public function answer(PageContext $page): Response
    {
        $username = $page->input('username');

        return new Response(
            (string) json_encode($this->candidates->candidates(is_string($username) ? $username : '')),
            200,
            ['Content-Type' => 'application/json']
        );
    }
}
