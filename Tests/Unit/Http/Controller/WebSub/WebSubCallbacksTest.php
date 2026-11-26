<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\WebSub;

use Idno\Common\Entity;
use Idno\Entities\User;
use Idno\Http\Controller\WebSub\Subscriptions;
use Idno\Http\Controller\WebSub\WebSubCallbacks;
use Idno\Http\Page\DrawnPage;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageDrawer;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The WebSub callback on doubles of what it asks the site and a real renderer over a double of the drawing: every branch of a verification and of a
 * delivery, the list a verification leaves, and what a delivery triggers.
 */
final class WebSubCallbacksTest extends TestCase
{
    private const UUID = 'https://example.com/view/ada';

    private const ID = '00000000-0000-4000-8000-000000000001';

    /** @var array<string, User> the members, by id */
    public array $users = [];

    /** @var array<string, Entity> the entities that are no members, by id */
    public array $entities = [];

    public \stdClass $pending;

    /** @var list<\stdClass> every list saved */
    public array $saved = [];

    /** @var list<array{string, array<string, mixed>}> every event triggered */
    public array $triggered = [];

    /** @var list<string> */
    public array $logged = [];

    public int $limit = 1000;

    private WebSubCallbacks $callbacks;

    protected function setUp(): void
    {
        $this->users = ['bob' => $this->user('bob-uuid', 'bob'), self::ID => $this->user(self::UUID, self::ID)];
        $this->entities = [];
        $this->pending = new \stdClass();
        $this->saved = [];
        $this->triggered = [];
        $this->logged = [];
        $this->limit = 1000;
        $test = $this;
        $subscriptions = new class($test) implements Subscriptions {
            public function __construct(private readonly WebSubCallbacksTest $test)
            {
            }

            public function user(string $id): ?User
            {
                return $this->test->users[$id] ?? null;
            }

            public function entity(string $id): ?Entity
            {
                return $this->test->users[$id] ?? $this->test->entities[$id] ?? null;
            }

            public function pending(User $subscriber): \stdClass
            {
                return $this->test->pending;
            }

            public function savePending(User $subscriber, \stdClass $pending): void
            {
                $this->test->saved[] = clone $pending;
            }

            public function trigger(string $event, array $data): void
            {
                $this->test->triggered[] = [$event, $data];
            }

            public function maxBody(): int
            {
                return $this->test->limit;
            }

            public function log(string $message): void
            {
                $this->test->logged[] = $message;
            }
        };
        $translation = new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return $text;
            }
        };
        $this->callbacks = new WebSubCallbacks($subscriptions, new PageRenderer(new WebSubDrawerDouble(), $translation));
    }

    private function user(string $uuid, string $id): User
    {
        $user = $this->getMockBuilder(User::class)->disableOriginalConstructor()->onlyMethods(['getUUID', 'getID'])->getMock();
        $user->method('getUUID')->willReturn($uuid);
        $user->method('getID')->willReturn($id);

        return $user;
    }

    /** @param array<string, string> $query */
    private function context(array $query = [], string $method = 'GET', string $body = ''): PageContext
    {
        return new PageContext(Request::create('/pubsub/callback/bob/ada', $method, $query, [], [], [], $body), new SitePage('Idno\Pages\Pubsubhubbub\Callback', methods: ['GET', 'POST'], formTokenReplacedBy: 'a test'));
    }

    private function verify(array $query): Response
    {
        return $this->callbacks->verify($this->context($query), 'bob', self::ID);
    }

    private function pend(?array $subscribe, ?array $unsubscribe = null): void
    {
        $this->pending = new \stdClass();
        if ($subscribe !== null) {
            $this->pending->subscribe = $subscribe;
        }
        if ($unsubscribe !== null) {
            $this->pending->unsubscribe = $unsubscribe;
        }
    }

    public function testAPendingSubscriptionIsVerifiedWithThePlainTextChallenge(): void
    {
        $this->pend([self::UUID]);

        $response = $this->verify(['hub_mode' => 'subscribe', 'hub_challenge' => 'abc']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('abc', $response->getContent());
        $this->assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testTheChallengeIsNeverMarkup(): void
    {
        $this->pend([self::UUID]);

        $response = $this->verify(['hub_mode' => 'subscribe', 'hub_challenge' => '<script>alert(1)</script>']);

        $this->assertSame('<script>alert(1)</script>', $response->getContent());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function testTheDottedSpellingOfTheFieldsIsRead(): void
    {
        $this->pend([self::UUID]);
        $context = $this->context();
        $context->setInput('hub.mode', 'subscribe');
        $context->setInput('hub.challenge', 'dotted');

        $this->assertSame('dotted', $this->callbacks->verify($context, 'bob', self::ID)->getContent());
    }

    public function testTheVerifiedSubscriptionLeavesThePendingListAndTheListKeepsItsShape(): void
    {
        $this->pend([self::UUID, 'another-uuid'], [self::ID]);

        $this->verify(['hub_mode' => 'subscribe', 'hub_challenge' => 'abc']);

        $this->assertCount(1, $this->saved);
        $this->assertSame(['subscribe' => ['another-uuid'], 'unsubscribe' => [self::ID]], get_object_vars($this->saved[0]));
        $this->assertInstanceOf(\stdClass::class, $this->saved[0]);
    }

    public function testASubscriptionPendingByIdIsVerified(): void
    {
        $this->pend([self::ID]);

        $this->assertSame(200, $this->verify(['hub_mode' => 'subscribe', 'hub_challenge' => 'abc'])->getStatusCode());
        $this->assertSame([], $this->saved[0]->subscribe);
    }

    public function testAnUnsubscriptionPendingByIdIsVerified(): void
    {
        $this->pend([self::UUID], [self::ID]);

        $response = $this->verify(['hub_mode' => 'unsubscribe', 'hub_challenge' => 'bye']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('bye', $response->getContent());
        $this->assertSame(['subscribe' => [self::UUID], 'unsubscribe' => []], get_object_vars($this->saved[0]));
    }

    public function testASubscriptionPendingForTheOtherModeIsNotVerified(): void
    {
        $this->pend([self::UUID], []);

        $this->assertSame(403, $this->verify(['hub_mode' => 'unsubscribe', 'hub_challenge' => 'abc'])->getStatusCode());
        $this->assertSame([], $this->saved);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function requestsThatAreNotVerified(): array
    {
        return [
            'no mode' => [['hub_challenge' => 'abc']],
            'another mode' => [['hub_mode' => 'denied', 'hub_challenge' => 'abc']],
            'a mode that is not text' => [['hub_mode' => ['subscribe'], 'hub_challenge' => 'abc']],
            'nothing' => [[]],
        ];
    }

    /**
     * @dataProvider requestsThatAreNotVerified
     *
     * @param array<string, mixed> $query
     */
    public function testARequestThatIsNotAVerificationIsDeniedAndChangesNothing(array $query): void
    {
        $this->pend([self::UUID], [self::ID]);

        $response = $this->verify($query);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->saved);
        $this->assertStringNotContainsString('abc', (string) $response->getContent());
    }

    public function testNothingPendingIsDenied(): void
    {
        $this->pend([], []);
        $this->assertSame(403, $this->verify(['hub_mode' => 'subscribe', 'hub_challenge' => 'abc'])->getStatusCode());

        $this->pending = new \stdClass();
        $this->assertSame(403, $this->verify(['hub_mode' => 'subscribe', 'hub_challenge' => 'abc'])->getStatusCode());

        $this->pending = new \stdClass();
        $this->pending->subscribe = 'not a list';
        $this->assertSame(403, $this->verify(['hub_mode' => 'subscribe', 'hub_challenge' => 'abc'])->getStatusCode());
        $this->assertSame([], $this->saved);
    }

    public function testTheSubscriptionOfAnotherUserIsNotVerified(): void
    {
        $this->pend(['somebody-else']);

        $this->assertSame(403, $this->verify(['hub_mode' => 'subscribe', 'hub_challenge' => 'abc'])->getStatusCode());
    }

    public function testAnUnknownSubscriberOrSubscriptionIsGone(): void
    {
        $this->pend([self::UUID]);
        $query = ['hub_mode' => 'subscribe', 'hub_challenge' => 'abc'];

        $this->assertSame(410, $this->callbacks->verify($this->context($query), 'nobody', self::ID)->getStatusCode());
        $this->assertSame(410, $this->callbacks->verify($this->context($query), 'bob', 'nothing')->getStatusCode());
        $this->assertSame([], $this->saved);
    }

    public function testASubscriberThatIsNoMemberIsGone(): void
    {
        $this->entities['post'] = $this->getMockBuilder(Entity::class)->disableOriginalConstructor()->getMock();
        $this->pend([self::UUID]);

        $this->assertSame(410, $this->callbacks->verify($this->context(['hub_mode' => 'subscribe']), 'post', self::ID)->getStatusCode());
    }

    public function testTheLogHoldsNothingThatIsNotPrintableAndIsCut(): void
    {
        $this->pend([]);

        $this->verify(['hub_mode' => "subscribe\r\nforged: line\x00" . str_repeat('a', 500), 'hub_challenge' => 'abc']);

        $this->assertNotSame([], $this->logged);
        foreach ($this->logged as $line) {
            $this->assertMatchesRegularExpression('/\A[\x20-\x7e]*\z/', $line);
            $this->assertLessThan(200, strlen($line));
        }
    }

    public function testDistributionTriggersReceivedNotPing(): void
    {
        $response = $this->callbacks->distribute($this->context([], 'POST', "  <feed/>\n"), 'bob', self::ID);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertCount(1, $this->triggered);
        [$event, $data] = $this->triggered[0];
        $this->assertSame('websub/received', $event);
        $this->assertSame(['subscriber', 'subscription', 'data'], array_keys($data));
        $this->assertSame($this->users['bob'], $data['subscriber']);
        $this->assertSame($this->users[self::ID], $data['subscription']);
        $this->assertSame('<feed/>', $data['data']);
    }

    public function testADeliveryWithNoBodyIsDeliveredEmpty(): void
    {
        $this->callbacks->distribute($this->context([], 'POST'), 'bob', self::ID);

        $this->assertSame('', $this->triggered[0][1]['data']);
    }

    public function testADeliveryToUnknownAddressesIsGoneAndTriggersNothing(): void
    {
        $this->assertSame(410, $this->callbacks->distribute($this->context([], 'POST', 'x'), 'nobody', self::ID)->getStatusCode());
        $this->assertSame(410, $this->callbacks->distribute($this->context([], 'POST', 'x'), 'bob', 'nothing')->getStatusCode());
        $this->entities['post'] = $this->getMockBuilder(Entity::class)->disableOriginalConstructor()->getMock();
        $this->assertSame(410, $this->callbacks->distribute($this->context([], 'POST', 'x'), 'bob', 'post')->getStatusCode());
        $this->assertSame([], $this->triggered);
    }

    public function testABodyOverTheLimitIsRefusedAndHandedToNobody(): void
    {
        $this->limit = 10;

        $this->assertSame(200, $this->callbacks->distribute($this->context([], 'POST', str_repeat('a', 10)), 'bob', self::ID)->getStatusCode());
        $this->assertSame(413, $this->callbacks->distribute($this->context([], 'POST', str_repeat('a', 11)), 'bob', self::ID)->getStatusCode());
        $this->assertCount(1, $this->triggered);
    }

    public function testADeliveryNeverTriggersTheEventTheSitesOwnHubIsPingedWith(): void
    {
        $this->callbacks->distribute($this->context([], 'POST', 'x'), 'bob', self::ID);

        $this->assertNotContains('pubsubhubbub/ping', array_column($this->triggered, 0));
    }
}

/** A double of the drawing: the page is a marker, a template its name. */
final class WebSubDrawerDouble implements PageDrawer
{
    public function templateType(): string
    {
        return 'default';
    }

    public function isApiRequest(): bool
    {
        return false;
    }

    public function finishSession(): void
    {
    }

    public function forwardTrace(?array $frames): ?string
    {
        return null;
    }

    public function displayUrl(): string
    {
        return 'https://example.com/';
    }

    public function urlWithVar(string $name, string $value, string $url): string
    {
        return $url;
    }

    public function drawTemplate(string $name, array $vars): string
    {
        return '<' . $name . '>';
    }

    public function drawPage(array $vars, int $status, string $shell = 'shell'): DrawnPage
    {
        return new DrawnPage('page', [['Content-type', 'text/html']]);
    }
}
