<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Entity;
use Idno\Common\Page;
use Idno\Core\Idno;
use Idno\Core\Time;
use Idno\Entities\User;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRedirect;
use Idno\Http\Page\SitePage;
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
 */
final class ControllerPage extends Page
{
    private readonly PageContext $context;

    /**
     * @param PageContext|null $context null is the home page of a request for `/`
     */
    public function __construct(?PageContext $context = null)
    {
        $this->context = $context ?? new PageContext(Request::create('/'), new SitePage('Idno\Pages\Homepage'));
        $this->xhr = $this->context->isXhr();
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

    public function getInput($name, $default = null, ?callable $filter = null)
    {
        if (empty($name)) {
            return null;
        }
        $value = $this->context->input((string) $name, $default);
        if ($filter !== null) {
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
        return $this->context->isXhr();
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

    public function isAcceptedContentType($contentType, $ignore_priority = false)
    {
        $accept = (string) $this->context->request()->headers->get('Accept', '');
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
                $q -= 0.000000000001;
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
        $this->context->setCaching(['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache']);
    }

    public function setLastModifiedHeader(int $timestamp)
    {
        $this->context->addHeader('Last-Modified', Time::timestampToRFC2616($timestamp));
    }

    /**
     * A conditional request is answered by the controller's response (Response::isNotModified()), not by exiting here.
     */
    public function lastModifiedGatekeeper($timestamp)
    {
    }

    public function deniedContent($title = '')
    {
        $this->context->setStatus(403);

        throw new AccessDeniedHttpException();
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
     * Where a legacy page sends a Location header and exits, this throws PageRedirect with the location the page would have sent
     * (the template type, when it is not the default, carried in `_t`).
     */
    public function forward(string $location = '', bool $exit = true)
    {
        $site = Idno::site();
        if (empty($location)) {
            $location = $site->config()->getDisplayURL();
        }
        $template = $site->template();
        if ($template->getTemplateType() != 'default') {
            $location = $template->getURLWithVar('_t', $template->getTemplateType(), $location);
        }

        throw new PageRedirect((string) $location);
    }

    /**
     * A controller's exception is the kernel's to answer, not this page's.
     */
    public function exception($e)
    {
        throw $e;
    }
}
