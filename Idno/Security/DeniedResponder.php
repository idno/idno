<?php

declare(strict_types=1);

namespace Idno\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The answer to a request a controller refused: the access-denied handler (a member who may not) and the entry
 * point (a visitor who is nobody) of the firewall, the same for both, because Idno never redirects to a login
 * page from here (a legacy page that wants a login does that itself): status 403 with the site's 403 page for a
 * caller that wants HTML, `{"error":"forbidden"}` for one that wants JSON, and a plain-text 403 when the page
 * cannot be drawn (the legacy application is not booted, or drawing it failed). Never cached.
 */
final class DeniedResponder implements AuthenticationEntryPointInterface, AccessDeniedHandlerInterface
{
    public function __construct(private readonly ForbiddenPage $page)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->respond($request);
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return $this->respond($request);
    }

    /** The request's attribute that says a refusal was answered: its `Cache-Control` is not a page's ask for caching */
    public const ATTRIBUTE = '_idno_denied';

    private function respond(Request $request): Response
    {
        $request->attributes->set(self::ATTRIBUTE, true);
        if (self::wantsJson($request)) {
            $response = new JsonResponse(['error' => 'forbidden'], 403);
        } else {
            try {
                $response = $this->page->render($request);
            } catch (\Throwable) {
                $response = null;
            }
            $response ??= new Response("Forbidden\n", 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
            $response->setStatusCode(403);
        }
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Whether the caller asked for JSON: `_t=json` (or jsonp) in the query, or an Accept header that names JSON
     * and no HTML.
     */
    private static function wantsJson(Request $request): bool
    {
        $type = $request->query->all()['_t'] ?? null;
        if (is_string($type) && in_array(strtolower($type), ['json', 'jsonp'], true)) {
            return true;
        }
        $accept = strtolower((string) $request->headers->get('Accept', ''));

        return str_contains($accept, 'json') && !str_contains($accept, 'html');
    }
}
