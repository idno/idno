<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Idno\Queue\EventTrigger;
use Idno\Queue\QueuedEvent;
use Idno\Queue\QueuedEventHandler;
use Idno\Queue\UserContext;
use PHPUnit\Framework\TestCase;

final class QueuedEventHandlerTest extends TestCase
{
    public function testItTriggersTheEventWithItsData(): void
    {
        $trigger = new RecordingEventTrigger('result');
        $handler = new QueuedEventHandler($trigger, new RecordingUserContext());

        $result = $handler(new QueuedEvent('default', 'syndicate', ['a' => 1, 'b' => [2]], null));

        $this->assertSame([['syndicate', ['a' => 1, 'b' => [2]]]], $trigger->triggered);
        $this->assertSame('result', $result, 'what the listeners returned is the handler\'s result');
    }

    public function testItLogsTheUserOnAndOffAroundTheEvent(): void
    {
        $log = new \ArrayObject();
        $handler = new QueuedEventHandler(new RecordingEventTrigger(true, $log), new RecordingUserContext($log));

        $handler(new QueuedEvent('default', 'syndicate', [], 'https://example.com/user/1'));

        $this->assertSame(['on https://example.com/user/1', 'trigger syndicate', 'off'], $log->getArrayCopy());
    }

    public function testAnEventWithNoUserRunsAsNobody(): void
    {
        $log = new \ArrayObject();
        $handler = new QueuedEventHandler(new RecordingEventTrigger(true, $log), new RecordingUserContext($log));

        $handler(new QueuedEvent('default', 'syndicate', [], null));

        $this->assertSame(['on null', 'trigger syndicate', 'off'], $log->getArrayCopy());
    }

    public function testAFailingEventDoesNotLeaveItsUserLoggedOn(): void
    {
        $log = new \ArrayObject();
        $trigger = new RecordingEventTrigger(true, $log, new \RuntimeException('listener failed'));
        $context = new RecordingUserContext($log);
        $handler = new QueuedEventHandler($trigger, $context);

        try {
            $handler(new QueuedEvent('default', 'syndicate', [], 'https://example.com/user/1'));
            $this->fail('The exception was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('listener failed', $e->getMessage());
        }

        $this->assertSame(['on https://example.com/user/1', 'trigger syndicate', 'off'], $log->getArrayCopy());
        $this->assertNull($context->loggedOn, 'nobody is logged on afterwards');
    }

    public function testAnErrorIsNotSwallowedEither(): void
    {
        $context = new RecordingUserContext();
        $handler = new QueuedEventHandler(new RecordingEventTrigger(true, null, new \TypeError('wrong type')), $context);

        $this->expectException(\TypeError::class);
        try {
            $handler(new QueuedEvent('default', 'syndicate', [], 'https://example.com/user/1'));
        } finally {
            $this->assertNull($context->loggedOn);
        }
    }

    public function testAnEventForAUserThatNoLongerExistsThrowsAndTriggersNothing(): void
    {
        $trigger = new RecordingEventTrigger();
        $context = new RecordingUserContext(null, ['https://example.com/user/1']);
        $handler = new QueuedEventHandler($trigger, $context);

        try {
            $handler(new QueuedEvent('default', 'syndicate', [], 'https://example.com/user/gone'));
            $this->fail('A missing user did not throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('https://example.com/user/gone', $e->getMessage());
        }

        $this->assertSame([], $trigger->triggered);
        $this->assertNull($context->loggedOn);
    }
}
