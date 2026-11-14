<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The response for a request served by legacy code (Idno\Common\Page and the Toro router).
 *
 * Legacy code writes the response itself, with header(), http_response_code(), echo and
 * exit, so this response sends nothing of its own: no status line, no headers, no
 * buffering or flushing. Its status and headers exist only on the object, for code that
 * inspects it; the kernel's response listeners may change them without effect.
 */
final class LegacyResponse extends StreamedResponse
{
    /**
     * Sends nothing: legacy code calls header() and http_response_code() itself.
     */
    public function sendHeaders(?int $statusCode = null): static
    {
        return $this;
    }

    /**
     * Leaves the response as it is. Response::prepare() would discard the callback of a
     * HEAD request (legacy code handles HEAD itself) and set headers that are not sent anyway.
     */
    public function prepare(Request $request): static
    {
        return $this;
    }

    /**
     * Runs the legacy code and, unlike Response::send(), neither closes output buffers nor
     * finishes the request (fastcgi_finish_request()): as before the kernel handled
     * requests, PHP does that when the script ends, after its shutdown functions.
     */
    public function send(bool $flush = true): static
    {
        $this->sendHeaders();
        $this->sendContent();

        return $this;
    }
}
