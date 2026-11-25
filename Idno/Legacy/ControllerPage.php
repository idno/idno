<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Entity;
use Idno\Common\Page;
use Idno\Core\Idno;
use Idno\Entities\User;
use Idno\Http\Page\HttpDate;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRedirect;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Markdown\MarkdownRequest;
use Idno\Templating\ShellHeaders;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The current page (Idno::site()->currentPage()) of a request a controller serves. Templates, helpers and plugins ask the
 * current page for the request's input, address, entity, owner and status; this answers them from the PageContext of the
 * request instead of the superglobals and the page's own properties, and where a legacy page exits (a refusal, a missing page,
 * a forward) it throws what the controller turns into a response.
 *
 * It does not run Component's constructor (no init(), no event hooks, no routes): building one neither makes it the
 * current page nor triggers `page/ready`; the controller's caller does that.
 *
 * No inherited member may send a header, set the process's status, echo or exit for a page a controller serves. Each member of
 * `Page` that would is overridden here to work from the request and record its effect on the context (headers, status, a
 * redirect), or throws a \LogicException naming the method: `get()`, `post()` and the like are the router's dispatch, which a
 * controller replaces. `Tests\Kernel\ControllerPageMembersTest` walks the members of `Page` and fails for a new one that is neither.
 */
final class ControllerPage extends Page
{
    private readonly PageContext $context;

    /**
     * @param PageContext|null $context null is the home page of a request for `/`
     */
    /**
     * @param bool $xhrFromStart false leaves the XHR flag off until startXhr(): a legacy page's flag was set by the router's
     *                           `<method>_xhr` call, after the page was built and had said `page/ready`
     */
    public function __construct(?PageContext $context = null, bool $xhrFromStart = true)
    {
        $this->context = $context ?? new PageContext(Request::create('/'), new SitePage('Idno\Pages\Homepage'));
        $this->xhr = $xhrFromStart && $this->context->isXhr();
        $this->arguments = $this->context->arguments();
    }

    public function context(): PageContext
    {
        return $this->context;
    }

    public function init()
    {
    }

    public function pageClassName(): string
    {
        return $this->context->page()->name;
    }

    /**
     * Answers as `Page::getInput()` does, line for line: the request's form field (else a non-null default) goes through the
     * filter, and only when that is null is what the page holds (setInput(), a JSON payload) looked at; so a non-null default
     * hides the page's own values. The filter runs again on the result when the first answer was empty.
     */
    public function getInput($name, $default = null, ?callable $filter = null)
    {
        if (empty($name)) {
            return null;
        }
        $value = null;

        // What `Input::getInput()` answered from `$_REQUEST`
        $request = $this->context->requestField((string) $name);
        if ($request === null && $default !== null) {
            $request = $default;
        }
        if ($filter !== null) {
            $request = call_user_func($filter, $name, $request);
        }

        if ($request !== null) {
            $value = $request;
        } else {
            $data = $this->context->dataField((string) $name);
            if ($data !== null) {
                $value = $data;
            }
        }
        if ($value === null && $default !== null) {
            $value = $default;
        }
        if ($filter !== null && empty($request)) {
            $value = call_user_func($filter, $name, $value);
        }

        return $value;
    }

    public function setInput($name, $value)
    {
        $this->context->setInput((string) $name, $value);
    }

    /**
     * A legacy page parsed a JSON payload into this array. The context reads one on demand (input()), so there is nothing here.
     */
    public function &data(): array
    {
        $data = [];

        return $data;
    }

    public function xhr(): bool
    {
        return $this->xhr;
    }

    /**
     * Sets the XHR flag the request asks for (what `get_xhr()` did).
     */
    public function startXhr(): void
    {
        $this->xhr = $this->context->isXhr();
    }

    public function setResponse(int $code)
    {
        $this->context->setStatus($code);
    }

    public function response(): int
    {
        return $this->context->status();
    }

    public function setEntity($entity)
    {
        $this->context->setEntity($entity instanceof Entity ? $entity : null);
    }

    public function getEntity(): ?Entity
    {
        return $this->context->entity();
    }

    public function removeEntity()
    {
        $this->context->setEntity(null);
    }

    public function setPermalink(bool $status = true, ?Entity $entity = null)
    {
        $this->context->setPermalink($status, $entity);
    }

    public function isPermalink()
    {
        return $this->context->isPermalink();
    }

    public function setOwner($user)
    {
        if ($user instanceof User) {
            $this->context->setOwner($user);
        }
    }

    public function getOwner()
    {
        return $this->context->owner() ?? false;
    }

    public function setAsset(string $name, string $value, string $class)
    {
        $this->context->setAsset($name, $value, $class);
    }

    public function getAssets(string $class)
    {
        $assets = $this->context->assets($class);

        return $assets === [] ? false : $assets;
    }

    public function currentUrl($tokenise = false)
    {
        $url         = parse_url((string) Idno::site()->config()->url);
        $url['path'] = $this->context->requestUri();

        return $tokenise ? $url : self::buildUrl($url);
    }

    public function referrer(): string
    {
        return (string) $this->context->request()->headers->get('Referer', '');
    }

    public function getReferrer()
    {
        return $this->referrer();
    }

    /**
     * The old algorithm, quirks included: ranges are keyed as written (a space after a comma stays in the key), a tie is broken by
     * taking 1 off the quality (the old code wrote that as the octal literal `000000000001`) and the first of the ordered ranges
     * decides. The header is the request's `Accept`, else the one a web server passed on as `REDIRECT_HTTP_ACCEPT`.
     */
    public function isAcceptedContentType($contentType, $ignore_priority = false)
    {
        $request = $this->context->request();
        $accept = (string) $request->headers->get('Accept', '');
        if ($accept === '') {
            // (a web server that rewrote the request passes the header on under this name)
            $accept = (string) $request->server->get('REDIRECT_HTTP_ACCEPT', '');
        }
        if ($accept === '') {
            return false;
        }
        if ($ignore_priority) {
            return substr_count($accept, (string) $contentType) > 0;
        }
        $types = [];
        foreach (explode(',', $accept) as $range) {
            $q = 1;
            if (strpos($range, ';q=')) {
                [$range, $q] = explode(';q=', $range);
            }
            while (in_array($q, $types)) {
                $q -= 1;
            }
            $types[$range] = $q;
        }
        arsort($types);
        foreach ($types as $type => $value) {
            return $type == $contentType;
        }

        return false;
    }

    public function setNoCache()
    {
        // what `Page::setNoCache()` sent: two `Cache-Control` lines and a `Pragma`; the session's `Expires` is left as it is
        $this->context->setCaching(['Cache-Control' => ['no-store, no-cache, must-revalidate, max-age=0', 'post-check=0, pre-check=0'], 'Pragma' => 'no-cache']);
    }

    /**
     * Sends the date the page last changed as its `Last-Modified` (an HTTP-date, `HttpDate::format()`).
     */
    public function setLastModifiedHeader(int $timestamp)
    {
        $this->context->addHeader('Last-Modified', HttpDate::format($timestamp));
    }

    /**
     * Asks to answer a conditional request: the page last changed at `$timestamp`. A legacy page sent the 304 here and exited, and a controller's
     * page does not exit, so this returns and the code after it runs on; the answer is decided last, on the response the page made
     * (`ConditionalRequest::apply()`): a GET or HEAD whose `If-Modified-Since` is not before `$timestamp`, and that has no `If-None-Match`, is
     * answered 304 with no body. (A legacy page compared the other way round: it answered 304 to a date NOT AFTER the change.)
     */
    public function lastModifiedGatekeeper($timestamp)
    {
        $this->context->answerConditionally((int) $timestamp);
    }

    /**
     * @param string $title what the refusal page is titled (the exception's message)
     */
    public function deniedContent($title = '')
    {
        $this->context->setStatus(403);

        throw new AccessDeniedHttpException((string) $title);
    }

    public function noContent()
    {
        $this->context->setStatus(404);

        throw new NotFoundHttpException();
    }

    public function goneContent()
    {
        $this->context->setStatus(410);

        throw new GoneHttpException();
    }

    /**
     * Where a legacy page sends a `Location` header and exits, this throws a PageRedirect that the response is made from (the
     * template type, when it is not the default, carried in `_t`); `forward($location, false)`, which sends the header and goes
     * on, records the redirect on the context and returns, and the response carries it. An API request is not redirected: it
     * gets the JSON `{"location": ...}` (with the trace when `forward_trace` is set), as it got from the legacy page. The
     * session is closed first when the page ends, as `finishEarly()` did. A page that cannot forward (a POST, PUT or DELETE sent
     * as an XHR request) does nothing, as before.
     */
    public function forward(string $location = '', bool $exit = true)
    {
        // The rule of a forward is the page renderer's, the one place it is written (the container's: a page does not own one)
        $redirect = Idno::container()->get(PageRenderer::class)->forward($this->context, $location, $exit, 302, debug_backtrace());
        if ($redirect === null) {
            return;
        }
        if ($exit) {
            $this->context->setRedirect(null);

            throw $redirect;
        }
        $this->context->setRedirect($redirect);
    }

    /**
     * A controller's exception is the kernel's to answer, not this page's.
     */
    public function exception($e)
    {
        throw $e;
    }

    /**
     * Whether the request was made over HTTPS, as `Page::isSSL()` decides from the process's request (the server's HTTPS
     * variable, the port, `X-Forwarded-Proto`), read from this page's request.
     */
    public function isSecure(): bool
    {
        $server = $this->context->request()->server;
        $https = $server->get('HTTPS');
        if ($https !== null) {
            if ($https == '1' || strtolower((string) $https) === 'on') {
                return true;
            }
        } elseif ($server->get('SERVER_PORT') == '443') {
            return true;
        }

        return strtolower((string) $server->get('HTTP_X_FORWARDED_PROTO', '')) === 'https';
    }

    /**
     * Where a legacy page redirects with a 307 (so that a POST is kept) and exits, this throws that redirect.
     */
    public function sslGatekeeper()
    {
        $config = Idno::site()->config();
        if (!$this->isSecure() && empty($config->ignore_ssl_gatekeeper) && $config->hasSSL()) {
            throw new PageRedirect(str_replace('http://', 'https://', $this->currentUrl()), null, 307);
        }
    }

    public function wantsMarkdown()
    {
        return MarkdownRequest::wanted(
            $this->context->markdownSuffixRequested(),
            $this->getInput('format'),
            $this->getInput('_t'),
            $this->context->request()->headers->get('Accept')
        );
    }

    public function sendVaryAccept()
    {
        $this->context->addHeader('Vary', 'Accept', false);
    }

    /**
     * Draws the Markdown form of an entity and RETURNS it (a legacy page echoed it and exited): the headers of a Markdown
     * document are recorded on the context for the response.
     *
     * @return string|false false when the site has no `markdown` template type
     */
    public function drawMarkdown(Entity $object)
    {
        $template = Idno::site()->template();
        if (!$template->setTemplateType('markdown')) {
            return false;
        }
        $drawn = $template->__(['title' => $object->getTitle(), 'object' => $object, 'markdown_entity' => $object]);
        // The session's messages are used up, as drawPage() did
        $markdown = $drawn->drawPageWithoutSideEffects(false, 'shell', true);
        // The canonical link is for an answer of status 200: the response the controller returns decides, not the page's status
        foreach ($drawn->shellHeaders(200) as [$name, $value]) {
            if (ShellHeaders::replaces($name)) {
                $this->context->addHeader($name, $value);
            } else {
                $this->context->addHeaderOnSuccess($name, $value);
            }
        }

        return (string) $markdown;
    }

    // The router's dispatch (the legacy handlers' entry points). A controller is its own handler: none of these may run on a
    // page it serves, and they would read the process's request and send headers.

    public function head_xhr()
    {
        $this->unavailable(__FUNCTION__);
    }

    public function head()
    {
        $this->unavailable(__FUNCTION__);
    }

    public function parseJSONPayload()
    {
        $this->unavailable(__FUNCTION__);
    }

    public function get_xhr()
    {
        $this->unavailable(__FUNCTION__);
    }

    public function get($params = array())
    {
        $this->unavailable(__FUNCTION__);
    }

    public function post_xhr()
    {
        $this->unavailable(__FUNCTION__);
    }

    public function post()
    {
        $this->unavailable(__FUNCTION__);
    }

    public function options()
    {
        $this->unavailable(__FUNCTION__);
    }

    public function put_xhr()
    {
        $this->unavailable(__FUNCTION__);
    }

    public function put()
    {
        $this->unavailable(__FUNCTION__);
    }

    public function delete_xhr()
    {
        $this->unavailable(__FUNCTION__);
    }

    public function delete()
    {
        $this->unavailable(__FUNCTION__);
    }

    public function webmention()
    {
        $this->unavailable(__FUNCTION__);
    }

    public function flushBrowser()
    {
        $this->unavailable(__FUNCTION__);
    }

    private function unavailable(string $method): never
    {
        throw new \LogicException('Page::' . $method . '() is not available on a page a controller serves: the controller answers, from the request.');
    }
}
