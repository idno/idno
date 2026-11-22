<?php

declare(strict_types=1);

namespace Tests\Portability;

use IdnoPlugins\Text\Entry;

/**
 * An article that cannot be written: its JSON fails with an exception, or (body "error") an \Error, and in every case
 * its template fails (a body of "draw" is fine as JSON, so only the template path fails).
 */
final class ExplodingEntry extends Entry
{
    public function draw($feed_view = false, $suffix = '')
    {
        throw new \LogicException('this entity\'s template fails');
    }

    public function jsonSerialize(): mixed
    {
        if ($this->body === 'draw') {
            return parent::jsonSerialize(); // fine as data: only its template fails
        }
        if ($this->body === 'error') {
            throw new \TypeError('a bug that is not an exception');
        }

        throw new \RuntimeException('this entity cannot be rendered');
    }
}
