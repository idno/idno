<?php

declare(strict_types=1);

namespace Idno\Http\Webmention;

/**
 * The page an address on this site is, for a webmention aimed at it: found as a GET of the address is routed (`WebmentionTargets::resolve()`).
 *
 * Either a page a controller serves (`$page` its `#[SitePage]` name, `$route` the route's name and `$arguments` the route's parameters, in order),
 * whose mention goes to the service that receives them for that page (`ReceivesWebmentions`), or a LEGACY page (`$page` the class, `$route` empty): a
 * plugin's page, a subclass of a shell, a page of a bundled plugin that is still a class. It receives through the closure that builds the page as the router
 * would and calls its `webmentionContent()`; nothing is built until then. A value object, not a service.
 */
final class WebmentionTarget
{
    /**
     * @param list<string>                                      $arguments the route's parameters (a legacy page's `arguments`: the pattern's captured groups)
     * @param (\Closure(ReceivedWebmention): bool)|null         $legacy    receives the mention as the legacy page would; null for a page a controller serves
     */
    public function __construct(
        public readonly string $page,
        public readonly string $route,
        public readonly array $arguments,
        private readonly ?\Closure $legacy = null,
    )
    {
    }

    public function isLegacy(): bool
    {
        return $this->legacy !== null;
    }

    /**
     * Hands the mention to the legacy page: whether it took it.
     *
     * @throws \LogicException for a page a controller serves, whose mention goes to its receiver
     */
    public function receiveLegacy(ReceivedWebmention $mention): bool
    {
        if ($this->legacy === null) {
            throw new \LogicException('The page ' . $this->page . ' is served by a controller: its mention goes to its receiver.');
        }

        return (bool) ($this->legacy)($mention);
    }
}
