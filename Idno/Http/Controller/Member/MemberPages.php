<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Member;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Templating\TemplateTranslation;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page a signed-in member keeps for themselves: their drafts (`/drafts/`). The one implementation:
 * `MemberPagesController` asks for a member before it calls it, so the list is always the current member's.
 */
final class MemberPages
{
    /** The number of drafts on a page. */
    public const DRAFTS_PER_PAGE = 25;

    public function __construct(
        private readonly MemberLists $lists,
        private readonly PageRenderer $renderer,
        private readonly TemplateTranslation $translation,
    )
    {
    }

    /**
     * The member's drafts, 25 a page from the request's `offset` (an integer, whatever is written), with the total for the paging links.
     */
    public function drafts(PageContext $page): Response
    {
        $offset = (int) $page->input('offset', 0);
        $drafts = $this->lists->drafts(self::DRAFTS_PER_PAGE, $offset);

        return $this->renderer->page($page, [
            'title' => $this->translation->translate('Drafts'),
            'body' => $this->renderer->template('drafts', [
                'drafts' => $drafts['items'],
                'count' => self::DRAFTS_PER_PAGE,
                'offset' => $offset,
                'total' => $drafts['total'],
            ]),
        ]);
    }
}
