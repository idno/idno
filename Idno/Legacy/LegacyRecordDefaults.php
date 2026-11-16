<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Idno;
use Idno\Storage\RecordDefaults;
use Ramsey\Uuid\Uuid;

/**
 * The RecordDefaults of the legacy application, read at call time, as the old backend's
 * Idno\Data\MySQL::saveRecord() (since removed) filled in a record:
 *  - _id: a random UUID (DataConcierge::generateID());
 *  - uuid: Config::getURL() (the site URL with its trailing slash, '/' when it has none) . 'view/' . _id;
 *  - siteid: the Site entity's uuid (Idno::site()->site_details()), or null when there is none.
 *
 * It needs the legacy application: calling siteUrl() or currentSiteId() before it has booted throws
 * a \LogicException.
 */
final class LegacyRecordDefaults implements RecordDefaults
{
    public function generateId(): string
    {
        return Uuid::uuid4()->toString();
    }

    public function siteUrl(): string
    {
        return (string) $this->site()->config()->getURL();
    }

    public function currentSiteId(): ?string
    {
        $details = $this->site()->site_details();

        return empty($details) ? null : $details->uuid();
    }

    private function site(): Idno
    {
        $site = Idno::site();
        if (!$site instanceof Idno) {
            throw new \LogicException('LegacyRecordDefaults needs the legacy application, which has not booted yet (a kernel built with bootLegacy: false?).');
        }

        return $site;
    }
}
