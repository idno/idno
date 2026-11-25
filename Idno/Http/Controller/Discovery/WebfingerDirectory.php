<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Discovery;

/**
 * Who WebFinger can describe: the members of this site, over the legacy application (`Idno\Legacy\LegacyWebfingerDirectory`).
 */
interface WebfingerDirectory
{
    /**
     * The aliases and links of the member an `acct:` resource names (`acct:<handle>@<this site's host>`, the host optional), the links being what the
     * `webfinger` event's listeners add; null when the resource is no `acct:` resource or names nobody.
     *
     * @return array{aliases: list<string>, links: array<mixed>}|null
     */
    public function find(string $resource): ?array;
}
