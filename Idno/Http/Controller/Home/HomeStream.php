<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Home;

use Idno\Common\Entity;
use Idno\Entities\User;

/**
 * What the home page lists and says: which posts it shows to the viewer, for which content types, a page at a time, and the title and
 * description of the feed. The home page's data source: `Idno\Pages\Homepage` (and so the feeds, the tag search and the `Idno\Legacy`
 * page it is served by) asks it for everything it draws, and a controller can ask it the same.
 *
 * The viewer is the session's user, as the legacy application knows it, and `page()` lists what that viewer may read: published
 * posts only (a draft is never listed, not even to its owner), by the viewer's read groups.
 */
interface HomeStream
{
    /**
     * Whether the site has any user. A site with none has no home page: its visitor is told the site is being set up (a 503).
     */
    public function siteHasUsers(): bool;

    /**
     * The owner of the home page of a single-user site: the site's one administrator. Null on a multi-user site and, with a warning
     * logged, on a single-user site that does not have exactly one administrator.
     */
    public function singleUserOwner(): ?User;

    /**
     * Whether the site is configured as a single-user site (a mention of its root is then the owner's).
     */
    public function isSingleUser(): bool;

    /**
     * The first administrator the site has, null when it has none. Unlike `singleUserOwner()` it does not insist on exactly one and does
     * not look at the site's kind: it is what a webmention to the root of a single-user site is passed on to.
     */
    public function firstAdministrator(): ?User;

    /**
     * The site's address as it is configured (`url`, written as the configuration has it): what the search page sends a visitor's query to.
     */
    public function siteAddress(): string;

    /**
     * The content types a request asks the home page to list.
     *
     * With a friendly path (`/content/note/article`: its part after the root, `all` for every type) the types it names, and the names
     * as given with the empty ones dropped (their keys kept), `['all']` for an empty path with a query; the request's own `types` do not
     * count. Without a friendly path and without a query, the types the site's home page is configured to show (none is every
     * registered type). Without a friendly path and with a query, the request's `types` as given. An empty result is every registered
     * content type.
     *
     * @param string|null $friendly the friendly path's argument (`note/article`), null or empty when the request has none
     * @param string|array<mixed>|null $query     the search term (`q`: a request can carry `q[]`, which the stores read as a query that matches nothing)
     * @param string|array<mixed>|null $requested the request's `types`
     *
     * @return array{types: array<int|string, mixed>, friendly: array<int, string>} the entity classes to list, and the friendly names
     */
    public function types(?string $friendly, string|array|null $query, string|array|null $requested): array;

    /**
     * One page of the stream: the published posts of the types the viewer may read that match the query (a hashtag, a word), newest first,
     * `items_per_page` of them from `$offset`, and the number of all of them.
     *
     * @param array<int|string, mixed> $types
     * @param string|array<mixed>|null $query the search term, as `types()` takes it
     *
     * @return array{items: array<int, Entity>|false, count: int} `items` is false when the database could not answer
     */
    public function page(array $types, string|array|null $query, int $offset): array;

    /**
     * The content types a logged-in user can create (each with its `editUrl` set, for API clients: the site may be configured to put the
     * editors elsewhere), false for a visitor.
     *
     * @return array<int|string, \Idno\Common\ContentType>|false
     */
    public function creatableTypes(): array|false;

    /**
     * The feed's title (the home page's, else the site's) and description (the site's, else a default).
     *
     * @return array{title: string, description: string}
     */
    public function titles(): array;

    /**
     * A timestamp as the `Last-Modified` header writes it (RFC 2616).
     */
    public function lastModified(int $timestamp): string;

    /**
     * What a webmention to the home page of a single-user site does: it is passed to the page of the user's profile. True when that page
     * took it, false when it did not or there is no page for the profile's address.
     *
     * @param array<mixed> $sourceResponse the fetched source (`response`, `content`)
     * @param array<mixed> $sourceMf2      its parsed microformats
     */
    public function profilePageWebmention(User $user, string $source, string $target, array $sourceResponse, array $sourceMf2): bool;
}
