<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Profile;

use Idno\Entities\User;
use Idno\Http\Page\PageContext;

/**
 * What the profile page asks the site: the account a handle names, the content types a friendly path lists, one page of the account's published posts,
 * how the account is titled and when it changed, and what saving the profile's edit form does. The legacy application implements it
 * (`Idno\Legacy\LegacyProfiles`), so the profile service is built and tested without it.
 */
interface Profiles
{
    /**
     * The account a handle names, null for none (an empty handle and a handle nobody has).
     */
    public function byHandle(string $handle): ?User;

    /**
     * The content types a friendly path lists (`notes`, `articles/notes`, `all`: its parts, with the empty ones left out; `all` is every registered
     * content type and ends the list; a part that names no type adds none, so a path that names none is an empty list, which lists every type). Null,
     * for an address with no friendly path, is the notes and the articles.
     *
     * @return array<int|string, mixed> the entity classes to list
     */
    public function typesFor(?string $friendly): array;

    /**
     * One page of the account's published posts of the types, newest first, `items_per_page` of them from `$offset` (what the viewer may not read is
     * left out), and the number of all of them.
     *
     * @param array<int|string, mixed> $types
     *
     * @return array{items: array<int, \Idno\Common\Entity>|false, count: int} `items` is false when the database could not answer
     */
    public function stream(User $user, array $types, int $offset): array;

    public function title(User $user): string;

    /**
     * The site's title, which the profile's description names.
     */
    public function siteTitle(): string;

    /**
     * When the account last changed, as a timestamp; 0 for one that has no date.
     */
    public function updated(User $user): int;

    public function handle(User $user): string;

    /**
     * Saves what the request holds (the edit form's name, profile fields and picture) into the account, if the signed-in member may edit it: the account's
     * own member does, nobody else, and nothing is stored for anyone else. Whether it was saved.
     */
    public function saveFromInput(User $user): bool;

    /**
     * The address of the account's profile.
     */
    public function url(User $user): string;

    /**
     * Where a request that came from another page sends the person back: the page it came from when it is this site's, else the site's home page.
     */
    public function referrer(PageContext $page): string;
}
