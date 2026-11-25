<?php

declare(strict_types=1);

namespace Idno\Http\Page;

/**
 * A page drawn in its shell and the headers its template type declares (Idno\Templating\ShellHeaders): what a Response is
 * made from. A value object, not a service.
 */
final class DrawnPage
{
    /**
     * @param list<array{0: string, 1: string}|array{0: string, 1: string, 2: bool}> $headers [name, value] pairs, in the order a legacy
     *        page sent them; a header the draw sent itself has a third element, whether it replaces the header of its name (the
     *        others replace unless `ShellHeaders::replaces()` says they are added)
     * @param int|null $status the status the draw itself set (`http_response_code()` in a template or a shell), null when it set none
     */
    public function __construct(public readonly string $body, public readonly array $headers, public readonly ?int $status = null)
    {
    }
}
