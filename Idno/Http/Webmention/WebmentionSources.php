<?php

declare(strict_types=1);

namespace Idno\Http\Webmention;

/**
 * What the webmention endpoint asks the site about a mention's SOURCE, the page of another server that says it links to one of this site's: whether the
 * site may fetch it, the fetch itself, what it parses to, and whether it is an address of this site. Implemented over the legacy application by
 * `Idno\Legacy\LegacyWebmentionSources`, so the endpoint is built and tested without it.
 */
interface WebmentionSources
{
    /**
     * Whether the site may fetch the address: an http or https address that is, and resolves to, a public one (the first of the checks that keep a
     * sender from making the site request its own network).
     */
    public function isSafe(string $source): bool;

    /**
     * The source page, fetched through the client that refuses private networks on every hop (`Webservice::safeSend()`): the answer as `Webservice`
     * gives it (`response`, `content`, ...), or false when there is none. A request that fails is an answer with no content, not false.
     *
     * @return array<string, mixed>|false
     */
    public function fetch(string $source): array|false;

    /**
     * The microformats of the fetched page, as the page at `$source` makes them.
     *
     * @return array<mixed>
     */
    public function parse(string $content, string $source): array;

    /**
     * Whether the address is one of this site's own (a post of the site that mentions another).
     */
    public function isLocal(string $url): bool;

    /**
     * Writes a line to the site's log: `$level` is `debug`, `info`, `warning` or `error`. The caller has made the message safe to log.
     */
    public function log(string $level, string $message): void;
}
