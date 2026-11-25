<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Discovery;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * WebFinger (RFC 7033): the JSON resource descriptor of a member, for `?resource=acct:<handle>@<host>`. Public on a private site, as it always
 * was; a resource that names nobody is the site's 404 page.
 */
final class Webfinger
{
    public function __construct(
        private readonly WebfingerDirectory $directory,
        private readonly PageRenderer $renderer,
    )
    {
    }

    public function answer(PageContext $page): Response
    {
        $resource = $page->input('resource');
        // (a resource that is not text, `resource[]=x`, names nobody)
        $found = is_string($resource) && $resource !== '' ? $this->directory->find($resource) : null;
        if ($found === null) {
            return $this->renderer->notFound($page);
        }

        $jrd = [
            'subject' => $resource,
            'aliases' => array_values(array_unique($found['aliases'])),
            'links' => $found['links'],
        ];

        return new Response(
            (string) json_encode($jrd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            200,
            ['Content-Type' => 'application/jrd+json', 'Access-Control-Allow-Origin' => '*']
        );
    }
}
