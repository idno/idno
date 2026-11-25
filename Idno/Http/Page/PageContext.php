<?php

declare(strict_types=1);

namespace Idno\Http\Page;

use Idno\Common\Entity;
use Idno\Entities\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the page a controller serves knows about its request: the input, the entity and owner it is about, the assets,
 * status and headers it asks for. It is the state a legacy `Idno\Common\Page` held in its properties and in the superglobals,
 * held on the request instead (the request's attribute `_idno_page`), for one request. Not a service.
 */
final class PageContext
{
    public const ATTRIBUTE = '_idno_page';

    /** @var array<string, mixed> */
    private array $inputs = [];

    /** @var array<string, mixed>|null the fields of a `json` input and of a JSON body, read on first use */
    private ?array $json = null;

    private bool $permalink = false;

    private ?Entity $entity = null;

    private ?User $owner = null;

    /** @var array<string, array<string, string>> */
    private array $assets = [];

    private int $status = 200;

    /** @var array<string, list<string>> */
    private array $headers = [];

    /** @var array<string, bool> by lower-case name: whether the first value of the header replaces a header of that name another part set */
    private array $replacing = [];

    /** @var array<string, string>|null */
    private ?array $caching = null;

    /** @var list<string>|null */
    private ?array $arguments = null;

    private bool $markdownSuffix = false;

    private ?PageRedirect $redirect = null;

    /** @var list<\Closure> */
    private array $afterResponse = [];

    public function __construct(private readonly Request $request, private readonly SitePage $page)
    {
    }

    public static function of(Request $request): self
    {
        $context = $request->attributes->get(self::ATTRIBUTE);
        if (!$context instanceof self) {
            throw new \LogicException('The request has no page context: its controller is not a site page.');
        }

        return $context;
    }

    public static function has(Request $request): bool
    {
        return $request->attributes->get(self::ATTRIBUTE) instanceof self;
    }

    public function attachTo(Request $request): void
    {
        $request->attributes->set(self::ATTRIBUTE, $this);
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function page(): SitePage
    {
        return $this->page;
    }

    /**
     * An input of the request: its form fields (a POST over the query string; never a cookie), then what the page set
     * (setInput()), then the fields of a `json` input and of a JSON body (a request that is not a GET).
     */
    public function input(string $name, mixed $default = null): mixed
    {
        if ($name === '') {
            return $default;
        }

        return $this->requestField($name) ?? $this->dataField($name) ?? $default;
    }

    /**
     * A form field of the request alone (a POST field over a query field; never a cookie): null when the request has none.
     */
    public function requestField(string $name): mixed
    {
        foreach ([$this->request->request->all(), $this->request->query->all()] as $fields) {
            if (isset($fields[$name])) {
                return $fields[$name];
            }
        }

        return null;
    }

    /**
     * What a legacy page kept in its `data`: the value the page set (setInput()), else a field of a `json` input or of a JSON
     * body; null when there is none.
     */
    public function dataField(string $name): mixed
    {
        if (isset($this->inputs[$name])) {
            return $this->inputs[$name];
        }

        return $this->json()[$name] ?? null;
    }

    /**
     * A value that stays under a request's own: input() gives it only when the request has none.
     */
    public function setInput(string $name, mixed $value): void
    {
        if ($name !== '') {
            $this->inputs[$name] = $value;
        }
    }

    public function isXhr(): bool
    {
        return $this->request->isXmlHttpRequest();
    }

    public function setPermalink(bool $permalink = true, ?Entity $entity = null): void
    {
        $this->permalink = $permalink;
        if ($permalink && $entity !== null) {
            $this->entity = $entity;
        }
    }

    public function isPermalink(): bool
    {
        return $this->permalink;
    }

    public function setEntity(?Entity $entity): void
    {
        $this->entity = $entity;
    }

    public function entity(): ?Entity
    {
        return $this->entity;
    }

    public function setOwner(?User $owner): void
    {
        $this->owner = $owner;
    }

    public function owner(): ?User
    {
        return $this->owner;
    }

    public function setAsset(string $name, string $value, string $class): void
    {
        $this->assets[$class][$name] = $value;
    }

    /**
     * @return array<string, string>
     */
    public function assets(string $class): array
    {
        return $this->assets[$class] ?? [];
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * A header the page asks for. A header of the same name (in any letter case) is replaced, or, when `$replace` is false, added to.
     */
    public function addHeader(string $name, string $value, bool $replace = true): void
    {
        $key = $name;
        foreach (array_keys($this->headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                $key = $existing;
                break;
            }
        }
        if ($replace || !isset($this->headers[$key])) {
            $this->replacing[strtolower($name)] = $replace;
        }
        $this->headers[$key] = $replace ? [$value] : [...($this->headers[$key] ?? []), $value];
    }

    /**
     * Whether a header of this name replaces one another part (a controller's response) already has, as opposed to being added
     * beside it: true unless the page began the header with `$replace` false.
     */
    public function replacesHeader(string $name): bool
    {
        return $this->replacing[strtolower($name)] ?? true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The caching headers (`Cache-Control`, `Pragma`, `Expires`) the page asks for, replacing any it asked for before. A page
     * that has asked owns its caching: nothing adds a default.
     *
     * @param array<string, string> $headers
     */
    public function setCaching(array $headers): void
    {
        $this->caching = $headers;
    }

    public function ownsCaching(): bool
    {
        return $this->caching !== null;
    }

    /**
     * @return array<string, string>
     */
    public function caching(): array
    {
        return $this->caching ?? [];
    }

    /**
     * The request's address as the server received it (path and query string, not normalised), as `Page::currentUrl()` reads it.
     */
    public function requestUri(): string
    {
        $uri = $this->request->server->get('REQUEST_URI');

        return is_string($uri) ? $uri : $this->request->getRequestUri();
    }

    /**
     * @param list<string> $arguments
     */
    public function setArguments(array $arguments): void
    {
        $this->arguments = array_values($arguments);
    }

    /**
     * What the router matched in the address, as a legacy page's `$arguments` were: by default the route's parameters other
     * than `lead`, `slash` and the framework's own (`_route`, `_controller`), in order.
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        if ($this->arguments === null) {
            $arguments = [];
            $parameters = $this->request->attributes->get('_route_params', []);
            foreach (is_array($parameters) ? $parameters : [] as $name => $value) {
                $name = (string) $name;
                if ($name === 'lead' || $name === 'slash' || str_starts_with($name, '_')) {
                    continue;
                }
                $arguments[] = is_scalar($value) ? (string) $value : '';
            }
            $this->arguments = $arguments;
        }

        return $this->arguments;
    }

    /**
     * Whether the request's path had the `.md` suffix of a post's Markdown form (and was routed without it); the route sets it.
     */
    public function setMarkdownSuffix(bool $requested = true): void
    {
        $this->markdownSuffix = $requested;
    }

    public function markdownSuffixRequested(): bool
    {
        return $this->markdownSuffix;
    }

    /**
     * A forward the page made and went on from (`forward($location, false)`): the response carries it.
     */
    public function setRedirect(PageRedirect $redirect): void
    {
        $this->redirect = $redirect;
    }

    public function redirect(): ?PageRedirect
    {
        return $this->redirect;
    }

    /**
     * Work the page asks to be done once the visitor has the answer (`AfterResponse::run()`).
     */
    public function afterResponse(\Closure $work): void
    {
        $this->afterResponse[] = $work;
    }

    /**
     * @return list<\Closure>
     */
    public function afterResponseWork(): array
    {
        return $this->afterResponse;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        $fields = [];
        $field = $this->request->request->all()['json'] ?? $this->request->query->all()['json'] ?? null;
        if (is_string($field) && trim($field) !== '') {
            $fields = $this->merge($fields, self::decode($field));
        }
        if ($this->request->getRealMethod() !== 'GET') {
            $fields = $this->merge($fields, self::decode($this->request->getContent()));
        }

        return $this->json = $fields;
    }

    /**
     * The earlier fields win, as the legacy page merged what it parsed under what it had.
     *
     * @param array<string, mixed> $have
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function merge(array $have, array $parsed): array
    {
        return array_merge($parsed, $have);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $text): array
    {
        $text = trim(str_replace('[]"', '"', $text));
        if ($text === '') {
            return [];
        }
        $parsed = json_decode($text, true);

        return is_array($parsed) ? $parsed : [];
    }
}
