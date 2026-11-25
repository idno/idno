<?php

declare(strict_types=1);

namespace Idno\Legacy;

/**
 * The headers PHP holds for the response it is about to send (what `header()` queued: the session's cache limiter, the plugins'
 * `Link`, the legacy boot's): a small seam over PHP's own functions, so that the rule for a site page's headers is a pure decision
 * that a test can run against a double. Idno\Legacy\PhpHeaders is the one implementation.
 */
interface HeaderQueue
{
    /**
     * Whether PHP keeps a list that can be read and changed: a web server's, and only until the output begins. The command line keeps none.
     */
    public function listed(): bool;

    /**
     * The lines held, each `Name: value`, in the order they will be sent.
     *
     * @return list<string>
     */
    public function queued(): array;

    /**
     * Takes every line of a name out of the list.
     */
    public function remove(string $name): void;

    /**
     * Queues a line: `$replace` replaces the lines of its name, otherwise it is added after them (`header($line, $replace)`).
     */
    public function send(string $line, bool $replace): void;
}
