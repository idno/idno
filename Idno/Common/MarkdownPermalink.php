<?php

declare(strict_types=1);

namespace Idno\Common;

/**
 * A page that is the permalink of an entity and can answer with the entity's Markdown form.
 *
 * The router serves `<address>.md` with such a page when nothing else answers that address (PageHandler::serve()). The page
 * decides, once it knows the viewer may read the entity, whether the request asked for Markdown (Page::wantsMarkdown()) and
 * answers with Page::drawMarkdown().
 */
interface MarkdownPermalink
{
}
