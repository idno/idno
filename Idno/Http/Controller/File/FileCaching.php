<?php

declare(strict_types=1);

namespace Idno\Http\Controller\File;

use Idno\Http\Page\HttpDate;

/**
 * How long, and by whom, a stored file may be kept: the three sets of caching lines the file page sends. An enum: not a service.
 */
enum FileCaching: string
{
    /** A file anyone may read: kept by any cache for thirty days */
    case Public = 'public';

    /** A file only some may read (attached to a post that is not public or not published): never kept by a shared cache */
    case Restricted = 'restricted';

    /** A file for administrators alone (an archive of the whole site): kept by no cache at all */
    case AdministratorsOnly = 'administrators';

    /**
     * The lines the page asks for with `PageContext::setCaching()`.
     *
     * @param  int $now The moment of the request
     * @return array<string, string>
     */
    public function caching(int $now): array
    {
        return match ($this) {
            self::Public => ['Pragma' => 'public', 'Cache-Control' => 'public', 'Expires' => HttpDate::format($now + 30 * 86400)],
            self::Restricted => ['Cache-Control' => 'private, no-cache'],
            self::AdministratorsOnly => ['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache'],
        };
    }
}
