<?php

declare(strict_types=1);

namespace Idno\Portability;

/**
 * Whether an import is running in this process. What an import brings in is history, not news: while it
 * runs, nothing is federated, syndicated, sent as a webmention or pinged to a hub for it. The places that
 * would announce a post ask active(); the importers wrap their work in during(). It is a flag on a service,
 * not a constant, because a queue worker lives on after the import and a constant cannot be taken back.
 */
final class ImportMode
{
    private int $depth = 0;

    public function active(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Runs $work with the mode on, and puts it back however $work ends.
     *
     * @template T
     * @param callable(): T $work
     *
     * @return T
     */
    public function during(callable $work): mixed
    {
        $this->depth++;
        try {
            return $work();
        } finally {
            $this->depth--;
        }
    }
}
