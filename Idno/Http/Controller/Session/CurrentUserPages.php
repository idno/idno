<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Session;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Templating\TemplateTranslation;
use Symfony\Component\HttpFoundation\Response;

/**
 * The current-user page (`/currentUser`): the profile of the member this request is made by, the one an API client asks "who am I" of (a signed request is
 * answered about the member that signed it, whatever cookie it carries). Nobody is signed in: the site's 404 page. What it asks the site is `SignIn`.
 */
final class CurrentUserPages
{
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly SignIn $signIn,
        private readonly TemplateTranslation $translation,
    )
    {
    }

    /**
     * The member's profile, drawn as a permalink. A POST is answered as a GET (an API contract).
     */
    public function show(PageContext $page): Response
    {
        $user = $this->signIn->currentUser();
        if ($user === null) {
            return $this->renderer->notFound($page);
        }

        $page->setPermalink(); // This is a permalink

        $title = $this->signIn->userTitle($user);

        return $this->renderer->page($page, [
            'title' => $title,
            'body' => $this->renderer->template('entity/User/profile', ['user' => $user]),
            'description' => $this->translation->translate('The %s profile for %s', [$this->signIn->siteTitle(), $title]),
        ]);
    }
}
