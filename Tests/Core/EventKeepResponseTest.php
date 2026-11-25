<?php

declare(strict_types=1);

namespace Tests\Core;

use Idno\Core\Idno;
use Tests\IdnoTestCase;

/**
 * An event triggered with a rule for the response it keeps (`triggerEvent()`'s fourth argument): once the rule accepts the response, a later
 * listener's `setResponse()` changes nothing, so the first listener to answer wins. An event with no rule is as it was: the last answer wins.
 */
final class EventKeepResponseTest extends IdnoTestCase
{
    private function listeners(string $name): void
    {
        $events = Idno::site()->events();
        $events->addListener($name, static function (\Idno\Core\Event $event): void {
            $event->setResponse('first');
        }, 10);
        $events->addListener($name, static function (\Idno\Core\Event $event): void {
            $event->setResponse(false);
        }, 0);
        $events->addListener($name, static function (\Idno\Core\Event $event): void {
            $event->setResponse('third');
        }, -10);
    }

    public function testWithARuleTheFirstAcceptedResponseIsKept(): void
    {
        $name = 'test/keep-' . bin2hex(random_bytes(4));
        $this->listeners($name);

        $answer = Idno::site()->events()->triggerEvent($name, [], true, static fn (mixed $current): bool => is_string($current));

        $this->assertSame('first', $answer);
    }

    public function testAnAnswerTheRuleDoesNotAcceptIsReplaced(): void
    {
        $name = 'test/keep-' . bin2hex(random_bytes(4));
        $events = Idno::site()->events();
        $events->addListener($name, static function (\Idno\Core\Event $event): void {
            $event->setResponse(false);
        }, 10);
        $events->addListener($name, static function (\Idno\Core\Event $event): void {
            $event->setResponse('later');
        }, 0);

        $this->assertSame('later', $events->triggerEvent($name, [], true, static fn (mixed $current): bool => is_string($current)));
    }

    public function testWithNoRuleTheLastAnswerWins(): void
    {
        $name = 'test/keep-' . bin2hex(random_bytes(4));
        $this->listeners($name);

        $this->assertSame('third', Idno::site()->events()->triggerEvent($name));
    }
}
