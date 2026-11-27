<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * Who is asking, for EntityRepository: the values the legacy getObjects() reads from the session
 * and from the db() service, read at the moment a query is built (never cached by an implementation).
 *
 * Implementations: Idno\Legacy\LegacyAccessContext (the container's AccessContext: the legacy
 * session and ignore-access flag) and FixedAccessContext (constructor-set values, for tests).
 */
interface AccessContext
{
    /**
     * The uuid of the logged-on user; null for an anonymous visitor (or when there is no session).
     */
    public function currentUserUuid(): ?string;

    /**
     * Whether the current user is a site admin (false for an anonymous visitor, and for a request
     * authenticated by API key, as Idno\Entities\User::isAdmin() rules).
     */
    public function isAdmin(): bool;

    /**
     * The access values the current user may read, used when a query names none.
     *
     * @return list<string> access values the current user may read, e.g. ['PUBLIC', 'SITE', <uuid>, <group ids>]
     */
    public function readAccessGroupIds(): array;

    /**
     * Whether access control is switched off (the legacy setIgnoreAccess(true)).
     */
    public function ignoreAccess(): bool;

    /**
     * The uuids of the accounts that are being deleted: what they own is in no listing and no count, for anyone but an administrator.
     *
     * @return list<string>
     */
    public function closedOwners(): array;

    /**
     * The entity classes that are the site's own content, whoever owns them (a static page): what a closed account owns of them is still listed.
     *
     * @return list<string>
     */
    public function siteContentClasses(): array;
}
