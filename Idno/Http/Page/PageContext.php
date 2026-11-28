<?php

declare(strict_types=1);

namespace Idno\Http\Page;

use Idno\Common\Entity;
use Idno\Entities\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the page a controller serves knows about its request: the input, the entity and owner it is about, the assets,
 * status and headers it asks for. It is the state a legacy `Idno\Common\Page` held in its properties and in the superglobals,
 * held on the request instead (the request's attribute `_idno_page`), for one request. Not a service.
 */
final class PageContext
{
    public const ATTRIBUTE = '_idno_page';

    /** The headers a page may ask caching of, by lower-case name */
    private const CACHING = ['cache-control' => 'Cache-Control', 'pragma' => 'Pragma', 'expires' => 'Expires'];

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

    /** @var list<array{0: string, 1: string}> headers added only to a response whose status is 200 (a Markdown document's canonical link) */
    private array $headersOnSuccess = [];

    /** @var array<string, string> headers added only to a response that has none of the name (the type a router gives a request the page then answers in its own) */
    private array $defaultHeaders = [];

    /** @var array<string, bool> by lower-case name: whether the first value of the header replaces a header of that name another part set */
    private array $replacing = [];

    /** @var array<string, string|list<string>>|null by canonical name */
    private ?array $caching = null;

    /** @var list<string>|null */
    private ?array $arguments = null;

    /** @var array<string, mixed> */
    private array $replacedInputs = [];

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
     * An input of the request as text: a string or a number as sent, and anything else (a field sent as an array, `code[]=x` or `email[$gt]=a`, or no field)
     * an empty string, never an error.
     */
    public function text(string $name): string
    {
        $value = $this->input($name);

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => '',
        };
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
     * A form field of the request alone (a POST field over a query field; never a cookie), unless the page replaced it (replaceInput()): null when
     * the request has none.
     */
    public function requestField(string $name): mixed
    {
        if (isset($this->replacedInputs[$name])) {
            return $this->replacedInputs[$name];
        }
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

    /**
     * A value the page gives an input in place of the request's own, wherever the page's input is read (a page whose address says what it
     * shows, as the tag page's does, over a stray `q` of the query string).
     */
    public function replaceInput(string $name, mixed $value): void
    {
        if ($name !== '') {
            $this->replacedInputs[$name] = $value;
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
     * Not for caching (`Cache-Control`, `Pragma`, `Expires`): that is `setCaching()`.
     *
     * @throws \InvalidArgumentException for a caching header
     */
    public function addHeader(string $name, string $value, bool $replace = true): void
    {
        if (isset(self::CACHING[strtolower($name)])) {
            throw new \InvalidArgumentException('Caching is asked for with setCaching(), not as a header: ' . $name);
        }
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
     * The page answers a conditional request: it last changed at `$lastChange` (a timestamp). When the request's copy is current (a GET or HEAD
     * whose `If-Modified-Since` is not before it, with no `If-None-Match`) the page stops here with `PageNotModified`, as a legacy page sent a
     * 304 and exited, and nothing after the call runs; otherwise it returns and the page goes on. A page calls this AFTER its own access checks,
     * as a legacy page called the gatekeeper: a viewer who may not see the page must be refused before it, never told the page is unchanged.
     *
     * @throws PageNotModified
     */
    public function answerConditionally(int $lastChange): void
    {
        if (ConditionalRequest::notModified($this->request, $lastChange)) {
            throw new PageNotModified();
        }
    }

    /**
     * The `Last-Modified` the page sends: the date of a timestamp.
     */
    public function setLastModified(int $timestamp): void
    {
        $this->addHeader('Last-Modified', HttpDate::format($timestamp));
    }

    /**
     * A header the page gives an answer that has none of its own (the router's `Content-Type` for an XHR request, which the page's
     * shell replaces).
     */
    public function addDefaultHeader(string $name, string $value): void
    {
        $this->defaultHeaders[$name] = $value;
    }

    /**
     * @return array<string, string>
     */
    public function defaultHeaders(): array
    {
        return $this->defaultHeaders;
    }

    /**
     * Puts on a response what the page asked for as headers: its defaults where the response has none of that name, the headers it added
     * (each replacing or added to, as it asked), and on a 200 the ones that belong to a success. The one rule the controller's response
     * listener and a deprecated shell's write both follow.
     */
    public function applyTo(Response $response): void
    {
        foreach ($this->defaultHeaders as $name => $value) {
            if (!$response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }
        foreach ($this->headers as $name => $values) {
            $response->headers->set($name, $values, $this->replacesHeader($name));
        }
        if ($response->getStatusCode() === 200) {
            foreach ($this->headersOnSuccess as [$name, $value]) {
                $response->headers->set($name, $value, false);
            }
        }
    }

    /**
     * A header that belongs only to an answer of status 200, whatever the page's own status said when it was drawn: the response
     * that is finally returned decides (a controller may answer 404 without telling the page). Added beside any other of its name.
     */
    public function addHeaderOnSuccess(string $name, string $value): void
    {
        $this->headersOnSuccess[] = [$name, $value];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function headersOnSuccess(): array
    {
        return $this->headersOnSuccess;
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
     * The caching headers (`Cache-Control`, `Pragma`, `Expires`) the page asks for: each name's lines (a string is one line, a list
     * several, as `setNoCache()` sent two `Cache-Control` lines), replacing what the page asked for under that name before and adding to
     * the names it asked for. A page that has asked owns the names it asked for: they are sent exactly as given, and PHP's own lines of
     * those names (the session's) are not sent.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @throws \InvalidArgumentException for any other header
     */
    public function setCaching(array $headers): void
    {
        $caching = $this->caching ?? [];
        foreach ($headers as $name => $lines) {
            $canonical = self::CACHING[strtolower((string) $name)] ?? throw new \InvalidArgumentException('Only Cache-Control, Pragma and Expires are caching headers: ' . $name);
            $caching[$canonical] = $lines;
        }
        $this->caching = $caching;
    }

    public function ownsCaching(): bool
    {
        return $this->caching !== null;
    }

    /**
     * @return array<string, string|list<string>> as the page gave them, by canonical name
     */
    public function caching(): array
    {
        return $this->caching ?? [];
    }

    /**
     * @return array<string, list<string>> the lines of each caching header the page asked for, in the order it asked
     */
    public function cachingLines(): array
    {
        return array_map(static fn (string|array $lines): array => array_values((array) $lines), $this->caching ?? []);
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
    public function setRedirect(?PageRedirect $redirect): void
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
