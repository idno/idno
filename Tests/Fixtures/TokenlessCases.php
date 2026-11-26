<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\Idno;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Idno\Security\FormToken;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Test-environment only: what the pages that take a request with no form token (`SitePageFixtureController::tokenless()` and the shell
 * `FixtureTokenlessShell`, through `FixtureShellPages::receive()`) answer for each `case` of the request, so that the controller's path and
 * the shell's are held to the same answers: who made the request as the legacy session says (the default, JSON); a drawn page in the shell that
 * prints the form token (`draw`) and adds a session message of its own (`message`); the 500 (`throw`), 403 (`denied`) and 404 (`notfound`) pages;
 * and a redirect.
 */
final class TokenlessCases
{
    /** The message the `message` case adds to the session of the request. */
    public const MESSAGE = 'A message of the exempt page';

    public static function answer(PageContext $page, PageRenderer $renderer, ?bool $granted = null): Response
    {
        $session = Idno::site()->session();
        $token = Idno::container()->get(FormToken::class)->value();
        switch ((string) $page->input('case', '')) {
            case 'message':
                $session->addErrorMessage(self::MESSAGE);
                // no break: and draws it
            case 'draw':
                return $renderer->page($page, ['title' => 'A drawn page', 'description' => 'A page drawn for a test.', 'body' => '<p>form token ' . $token . '</p>']);
            case 'throw':
                throw new \RuntimeException('The exempt page failed');
            case 'denied':
                throw new AccessDeniedHttpException();
            case 'notfound':
                throw new NotFoundHttpException();
            case 'redirect':
                return $renderer->redirect($page, 'https://example.invalid/after');
        }
        $uuid = $session->currentUserUUID();
        $answer = ['user' => $uuid === false ? null : $uuid, 'api' => $session->isAPIRequest(), 'method' => $page->request()->getMethod(), 'form_token' => $token];
        if ($granted !== null) {
            $answer['granted'] = $granted;
        }

        return new JsonResponse($answer);
    }
}
