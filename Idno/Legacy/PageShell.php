<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Page;
use Idno\Core\Idno;
use Idno\Core\PageHandler;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageEnded;
use Idno\Http\Page\PageNotModified;
use Idno\Http\Page\PageRedirect;
use Idno\Http\Page\SitePage;
use Idno\Templating\ShellHeaders;
use Idno\Templating\TypeChain;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The base of a page that a controller serves now and that stays a legacy class for the code that still names it: a plugin's page that
 * extends it, a route that plugin registered for it, the pages that build one. A shell has no logic of its own: each of its handlers
 * hands the request to the service the controller calls (`delegate()`), so a page has one implementation. A shell is deprecated, and is
 * removed in 3.0; constructing or serving a subclass of one logs a deprecation line, once for each shell in the process.
 *
 * `delegate()` runs the service on the request being served as a controller would: a `PageContext` built from the request and from what the
 * shell holds (its arguments, the inputs it was given with `setInput()`, its owner, entity and permalink flag), with a `ControllerPage`
 * over it as the current page while the service runs, so what the service and the templates it draws ask the current page is answered from
 * the context. The `Response` the service returns is written as a legacy page writes one: the status with `http_response_code()` and each
 * header with `header()`, with the rule that keeps the session's caching lines (`LegacyHeaderReconciler`), and the body is echoed, after which the request ends, as the legacy
 * page ended it where it drew (`exit`): nothing a subclass does after `parent::getContent()`, no router hook after the handler and no
 * `kernel.terminate` runs for a page served through a shell, as none did for the page it replaced. The page is the one its controller
 * serves: the shell names the controller action that serves it (`ACTION`) and takes that action's `#[SitePage]` (its flags, under the name of the
 * shell or its subclass), its Markdown form (the `.md` of an address a `MarkdownPermalink` shell is routed for) and the `Vary` of a permalink,
 * and a streamed or file response is sent as the controller's is. What the
 * service throws is what a legacy page did: a refusal, a missing page, a gone page and a forward are the page's own `deniedContent()`,
 * `noContent()`, `goneContent()` and `forward()`, and a current copy (`PageNotModified`) is the 304 that ended the request.
 */
abstract class PageShell extends Page
{
    /** The class of the shell itself, which each shell declares: a class that is not it is a subclass */
    protected const SHELL = '';

    /**
     * The controller action that serves the page, as `[class, method]`: the shell takes its `#[SitePage]` (null: a page with only a name, and no flags).
     * The action says `shell: true` and names the shell's class.
     *
     * @var array{0: class-string, 1: string}|null
     */
    public const ACTION = null;

    /** @var array<string, true> the shells whose deprecation has been logged in this process */
    private static array $deprecationLogged = [];

    public function __construct()
    {
        parent::__construct();
        $this->noteSubclass();
    }

    /**
     * Serves the page with the service: `$serve` is given the context of the request and returns the response.
     *
     * @param \Closure(PageContext): Response $serve
     */
    protected function delegate(\Closure $serve): void
    {
        $this->noteSubclass();
        $site = Idno::site();
        $context = $this->contextFor($this->currentRequest());
        // A permalink's answer depends on the Accept header (the HTML or the Markdown form): EVERY answer to a GET or a HEAD says so, a refusal, a missing page, a
        // forward and a 304 too, as the controller's listener adds it to every response. (Sent here, once, before anything is answered: what answers goes through
        // `header()`.) What answers a POST or a DELETE does not depend on it, and never said so.
        if ($context->page()->markdownPermalink && in_array($context->request()->getRealMethod(), ['GET', 'HEAD'], true)) {
            (new PhpHeaders())->send('Vary: Accept', false);
        }
        // An account page is closed to an API request whatever route the shell (or a plugin's subclass of it) is registered on: the router refuses the
        // registrations under /account/, and the action's own flag is the rest
        if ($context->page()->accountPage && $site->session()->isAPIRequest()) {
            $site->logging()->warning('API request to an account page refused: ' . ltrim(static::class, '\\'));
            $site->template()->autodetectTemplateType();
            $site->session()->addErrorMessage($site->language()->_('Account pages are not available to API requests.'));
            (new BarePage())->deniedContent();

            return;
        }
        $page = new ControllerPage($context, $this->xhr());
        $this->selectTypes($context, $page);

        $thrown = null;
        $response = null;
        $site->setCurrentPage($page);
        try {
            $response = $serve($context);
        } catch (PageRedirect | PageNotModified | PageEnded | AccessDeniedHttpException | NotFoundHttpException | GoneHttpException $e) {
            $thrown = $e;
        } finally {
            $site->setCurrentPage($this);
            // what the page learned while it served is the shell's, as it was when the shell did it
            if ($context->owner() !== null) {
                $this->setOwner($context->owner());
            }
            $this->setResponse($context->status());
        }

        if ($thrown instanceof PageRedirect) {
            $this->forward($thrown->location);

            return;
        }
        if ($thrown instanceof PageNotModified) {
            // the page sent its 304 where it asked, and the request ended there, as the legacy gatekeeper's did
            $this->write($thrown->toResponse(), $context);
            exit;
        }
        if ($thrown instanceof PageEnded) {
            // the page forwarded a script's request, which cannot be redirected, and ended there, as `forward()` ends such a request
            $this->forward();

            return;
        }
        if ($thrown instanceof AccessDeniedHttpException) {
            $this->deniedContent($thrown->getMessage());

            return;
        }
        if ($thrown instanceof NotFoundHttpException) {
            $this->noContent();

            return;
        }
        if ($thrown instanceof GoneHttpException) {
            $this->goneContent();

            return;
        }

        if ($response !== null) {
            $this->write($response, $context);
            // a page ends where it is written, as `drawPage()` (and `forward()`) ended it: what follows in the handler of a subclass, the router's
            // hooks after the handler and the kernel's termination never ran after a page, and still do not
            exit;
        }
    }

    /**
     * A DELETE with no valid form token to a shell that serves DELETE (it declares a `deleteContent()` of its own) is refused with the site's 403 page, as a
     * controller's page refuses it: nothing is reported and the administrator is not mailed (a legacy page threw the exception of a timed out page, which was the
     * reported 500 page). A shell that serves no DELETE keeps the legacy page's answer.
     */
    protected function refuseDeleteWithoutToken()
    {
        if ((new \ReflectionMethod($this, 'deleteContent'))->getDeclaringClass()->getName() === Page::class) {
            parent::refuseDeleteWithoutToken();

            return;
        }
        $this->deniedContent(Idno::site()->language()->_('The page you were on timed out.'));
    }

    /**
     * Receives a webmention with the service: `$receive` answers whether the page took it.
     *
     * @param \Closure(): bool $receive
     */
    protected function delegateWebmention(\Closure $receive): bool
    {
        $this->noteSubclass();

        return (bool) $receive();
    }

    /**
     * Logs the deprecation of a shell once for the process, when the class is a subclass of it.
     */
    private function noteSubclass(): void
    {
        if (isset(self::$deprecationLogged[static::SHELL]) || static::class === static::SHELL) {
            return;
        }
        self::$deprecationLogged[static::SHELL] = true;
        Idno::site()->logging()->warning(
            'Deprecated: ' . static::class . ' extends ' . static::SHELL . ', which is a deprecated shell of a page a controller serves now and is removed in 3.0. '
            . 'Extend \Idno\Common\Page instead (see docs/developers/plugins/upgrading.md).'
        );
    }

    /**
     * The request being served: the one on Symfony's stack (the legacy response pushes it while the legacy code runs), else the process's.
     */
    private function currentRequest(): Request
    {
        $stack = Idno::container()->get('request_stack');

        return ($stack instanceof RequestStack ? $stack->getCurrentRequest() : null) ?? Request::createFromGlobals();
    }

    /**
     * What `LegacyPageLifecycle::begin()` does for a controller's page before it is served: the type the action names when the request names none, and
     * the Markdown form a permalink's `.md` asked for. (A controller selects the type before `page/ready`; a shell does, here, after the events of the
     * page that the router fired: a listener of those sees the template type the request asked for.)
     */
    private function selectTypes(PageContext $context, ControllerPage $page): void
    {
        $site = Idno::site();
        $defaultType = $context->page()->defaultType;
        if ($defaultType !== null && empty($context->input('_t'))) {
            $context->setInput('_t', $defaultType);
            $template = $page->getInput('_t');
            if (is_string($template)) {
                $template = TypeChain::requested($template);
            }
            if (is_string($template) && TypeChain::requestable($template) && $site->template()->templateTypeExists($template)) {
                $site->template()->setTemplateType($template);
            }
        }
        if ($context->markdownSuffixRequested()) {
            $page->selectMarkdownIfWanted();
        }
    }

    /**
     * The `#[SitePage]` of the action that serves the page: the SHELL's (a subclass carries the flags of the shell it extends and cannot change them:
     * `ACTION` is no extension point), null for a shell that names none.
     */
    private static function actionPage(): ?SitePage
    {
        $shell = static::SHELL;
        $action = $shell !== '' && defined($shell . '::ACTION') ? constant($shell . '::ACTION') : null;

        return is_array($action) ? SitePage::of($action) : null;
    }

    /**
     * Whether a request with this method to this shell (or a subclass: it inherits the action) is one whose page takes it without the form token
     * (`formTokenReplacedBy` of its action). `PageHandler::serve()` asks it of the page the router is about to serve and makes that request nobody's
     * before the page is built, as the controller's path does (`page/ready` never sees the session's user): constructing a shell for any other purpose
     * (an address looked up, a page built and not served) changes nothing.
     */
    public static function takesNoFormToken(string $method): bool
    {
        $page = self::actionPage();

        return $page !== null && $page->changesStateWithoutFormToken($method);
    }

    private function contextFor(Request $request): PageContext
    {
        $page = self::actionPage();
        $context = new PageContext($request, $page?->withName(static::class) ?? new SitePage(name: static::class));
        $context->setMarkdownSuffix(PageHandler::markdownSuffixRequested());
        $context->setArguments(array_map('strval', array_values($this->arguments)));
        foreach ($this->data() as $name => $value) {
            $context->setInput((string) $name, $value);
        }
        $owner = $this->getOwner();
        $context->setOwner($owner instanceof \Idno\Entities\User ? $owner : null);
        $context->setEntity($this->getEntity());
        $context->setPermalink($this->isPermalink(), $this->getEntity());
        $context->setStatus($this->response());

        return $context;
    }

    /**
     * Writes the response as a legacy page did.
     *
     */
    private function write(Response $response, PageContext $context): void
    {
        // a file or a stream is prepared against the request as the kernel prepares a controller's response: `Content-Type` and `Content-Length`, a `Range`
        // answered 206 with exactly its bytes or 416, a HEAD with no body
        $sent = $response instanceof StreamedResponse || $response instanceof BinaryFileResponse;
        if ($sent) {
            // (with the kernel's charset, as its response listener gives it, so a stream with no type is `text/html; charset=UTF-8` on both paths)
            $response->setCharset(Idno::container()->get('kernel')->getCharset() ?? 'UTF-8');
            $response->prepare($context->request());
            if ($response->getStatusCode() === 416) {
                // (prepare() leaves the file's own length on the refusal of a range, and the answer has no body)
                $response->headers->set('Content-Length', '0');
            }
        }
        // the headers the page asked for, as the controller's listener puts them on its response
        $context->applyTo($response);

        $php = new PhpHeaders();
        (new LegacyHeaderReconciler(Idno::container()->get('kernel'), $php))->reconcile($response, $context, false);

        http_response_code($response->getStatusCode());
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            // (the caching headers were taken off by the rule above, and the date is the server's)
            if (in_array(strtolower($name), ['cache-control', 'pragma', 'expires', 'date'], true)) {
                continue;
            }
            $replace = isset($context->headers()[$name]) ? $context->replacesHeader($name) : ShellHeaders::replaces($name);
            foreach (array_values($values) as $position => $value) {
                $php->send($name . ': ' . $value, $position === 0 && $replace);
            }
        }
        if ($sent) {
            // sent as it is made (a file is never held in memory), and never for a HEAD, whose answer has no body: a stream's callback does work
            if ($context->request()->getRealMethod() !== 'HEAD') {
                \Idno\Pages\File\View::endOutputBuffers();
                $response->sendContent();
            }

            return;
        }
        echo $response->getContent();
    }
}
