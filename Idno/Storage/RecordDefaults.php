<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * What a store needs from the application to fill in the identity fields of a record it is
 * asked to save without them: an _id, a uuid (site URL + 'view/' + _id) and a siteid.
 *
 * A value/helper interface, not a container service (see config/services.php).
 */
interface RecordDefaults
{
    /**
     * A new record id, a string like the UUIDs the legacy backend generates.
     */
    public function generateId(): string;

    /**
     * The site's base URL with its trailing slash, for example 'https://example.com/'.
     */
    public function siteUrl(): string;

    /**
     * The id of the current site. Null when the site has none: a record saved without a siteid
     * then gets a siteid of null (today's behaviour when the Site entity has no uuid).
     */
    public function currentSiteId(): ?string;
}
