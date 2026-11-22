<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use Idno\Legacy\LateLogger;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Queue\CollectingLogger;

final class LateLoggerTest extends TestCase
{
    public function testTheLoggerIsFetchedWhenTheFirstLineIsWrittenAndOnlyOnce(): void
    {
        $inner = new CollectingLogger();
        $fetched = 0;
        $logger = new LateLogger(static function () use ($inner, &$fetched) {
            ++$fetched;

            return $inner;
        });
        $this->assertSame(0, $fetched, 'building it fetches nothing');

        $logger->warning('one');
        $logger->error('two');

        $this->assertSame(1, $fetched);
        $this->assertSame([['warning', 'one'], ['error', 'two']], $inner->lines);
    }
}
