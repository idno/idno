<?php

declare(strict_types=1);

namespace Idno\Portability;

/**
 * What the import and export services need to know about the site, and about who may use them. The
 * services depend on this, not on the application's configuration object (Idno\Legacy\LegacySiteFacts
 * is the adapter over it).
 */
interface SiteFacts
{
    /** The site's address, with its trailing slash, as stored entities and files refer to it. */
    public function url(): string;

    /** The address as the current request sees it (https behind a proxy that says so). */
    public function displayUrl(): string;

    public function title(): string;

    public function description(): string;

    /** The host as the upload directory names it (without `www.`). */
    public function pathHost(): string;

    /** The host, as the archive's file name is made from it. */
    public function host(): string;

    public function version(): string;

    public function build(): string;

    /** The directory working files are made in, without a trailing slash. */
    public function temporaryDirectory(): string;

    /** The largest body a remote fetch may read: the `webservice_max_download` setting. */
    public function maxDownloadBytes(): int;

    /**
     * The entity classes of the content types the site publishes (the posts a feed export holds).
     *
     * @return list<string>
     */
    public function contentTypeClasses(): array;

    public function isAdministrator(string $userUuid): bool;

    /** Gets the site ready to draw entities outside a request for them (permalinks, not the current page's context). */
    public function prepareToDraw(): void;
}
