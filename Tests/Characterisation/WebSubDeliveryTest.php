<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * What a hub may do at the WebSub callback of a real site, over HTTP: a delivery is accepted only for a subscription the subscriber has, and, when the site
 * sent the hub a secret, only with a valid `X-Hub-Signature`; a verification must name the topic the site subscribed to, within its lease. The recorded
 * answers of the callback are `WebSubCallbackTest`'s. The members are Ada (who is followed, and has a feed and a hub), Bob (who follows her) and Carol.
 */
final class WebSubDeliveryTest extends PrivateSiteTestCase
{
    private const SECRET = 'a-secret-the-site-sent-the-hub-0123456789abcdef0123456789abcdef';

    private const TOPIC = 'https://ada.example/feed';

    private const BODY = '<feed xmlns="http://www.w3.org/2005/Atom"><title>Ada</title></feed>';

    private string $eventsFile = '';

    /** @var array<string, string> the ids of the members, by handle */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->eventsFile = (string) tempnam(sys_get_temp_dir(), 'idno-websub-delivery-');
        parent::setUp();
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $this->addUser('bob');
        $this->addUser('carol');
        $this->configure();
    }

    protected function tearDown(): void
    {
        @unlink($this->eventsFile);
        parent::tearDown();
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_RECORD_WEBSUB' => $this->eventsFile];
    }

    /**
     * Makes the subscription: Ada has a feed and a hub, Bob follows her, and what the options say.
     *
     * @param array{secret?: string|null, hub?: bool, follows?: bool, pending?: array<string, int|null>} $options
     *     `pending` is the entries of the lists by `<mode>:<entry>` and how many seconds ago each was added (null: no time kept, as an earlier release stored it)
     */
    private function configure(array $options = []): void
    {
        $options += ['secret' => self::SECRET, 'hub' => true, 'follows' => true, 'pending' => []];
        $code = <<<'PHP'
        $options = json_decode(%OPTIONS%, true);
        $ada = \Idno\Entities\User::getByHandle('ada');
        $bob = \Idno\Entities\User::getByHandle('bob');
        $ada->pubsub_self = %TOPIC%;
        $ada->pubsub_hub = $options['hub'] ? 'https://hub.example/' : '';
        $ada->save(true);
        $bob->following = $options['follows'] ? [$ada->getUUID() => ['name' => 'Ada']] : [];
        $bob->pubsub_secrets = $options['secret'] === null ? [] : [$ada->getID() => $options['secret']];
        $pending = new \stdClass();
        $now = time();
        foreach ($options['pending'] as $key => $age) {
            [$mode, $entry] = explode(':', $key, 2);
            $entry = $entry === 'uuid' ? $ada->getUUID() : ($entry === 'id' ? $ada->getID() : $entry);
            $pending->$mode = array_merge($pending->$mode ?? [], [$entry]);
            if ($age !== null) {
                $pending->at = ($pending->at ?? []) + [$mode . ':' . $entry => $now - $age];
            }
        }
        $bob->pubsub_pending = serialize($pending);
        $bob->save(true);

        return ['ada' => $ada->getID(), 'bob' => $bob->getID(), 'carol' => \Idno\Entities\User::getByHandle('carol')->getID()];
        PHP;
        $ids = $this->runInSite(str_replace(['%OPTIONS%', '%TOPIC%'], [$this->literal((string) json_encode($options)), $this->literal(self::TOPIC)], $code));
        $this->assertIsArray($ids);
        $this->ids = $ids;
    }

    private function address(string $subscriber = 'bob', string $subscription = 'ada'): string
    {
        return 'pubsub/callback/' . $this->ids[$subscriber] . '/' . $this->ids[$subscription];
    }

    /** @return list<array<string, mixed>> the events the site has triggered since this was last asked */
    private function events(): array
    {
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->eventsFile))));
        file_put_contents($this->eventsFile, '');

        return array_map(static fn (string $line): array => json_decode($line, true), $lines);
    }

    /** @param array<string, string> $headers */
    private function deliver(array $headers = [], string $body = self::BODY, string $subscriber = 'bob', string $subscription = 'ada'): SiteResponse
    {
        $this->events();

        return $this->site()->request('POST', $this->address($subscriber, $subscription), ['Content-Type' => 'application/atom+xml'] + $headers, $body);
    }

    private function verify(string $mode, string $topic = self::TOPIC, string $method = 'GET', string $challenge = 'challenge-1234'): SiteResponse
    {
        return $this->site()->request($method, $this->address() . '?' . http_build_query(['hub.mode' => $mode, 'hub.topic' => $topic, 'hub.challenge' => $challenge]));
    }

    /** @return array<string, array{0: string}> */
    public static function methods(): array
    {
        return ['sha256' => ['sha256'], 'sha384' => ['sha384'], 'sha512' => ['sha512']];
    }

    /** @dataProvider methods */
    public function testASignedDeliveryIsAcceptedAndTriggersOnce(string $method): void
    {
        $response = $this->deliver(['X-Hub-Signature' => $method . '=' . hash_hmac($method, self::BODY, self::SECRET)]);

        $this->assertSame(200, $response->status);
        $this->assertSame('', $response->body);
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame(['event' => 'websub/received', 'subscriber' => 'bob', 'subscription' => 'ada', 'data' => self::BODY, 'user' => null], $events[0]);
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function badSignatures(): array
    {
        $good = hash_hmac('sha256', self::BODY, self::SECRET);

        return [
            'none' => [[]],
            'sha1' => [['X-Hub-Signature' => 'sha1=' . hash_hmac('sha1', self::BODY, self::SECRET)]],
            'another secret' => [['X-Hub-Signature' => 'sha256=' . hash_hmac('sha256', self::BODY, 'another')]],
            'too short' => [['X-Hub-Signature' => 'sha256=' . substr($good, 0, 32)]],
            'an unknown method' => [['X-Hub-Signature' => 'md5=' . hash_hmac('md5', self::BODY, self::SECRET)]],
            'no method' => [['X-Hub-Signature' => $good]],
        ];
    }

    /**
     * @dataProvider badSignatures
     *
     * @param array<string, string> $headers
     */
    public function testABadSignatureIsAnsweredAsAnAcceptedDeliveryAndTriggersNothing(array $headers): void
    {
        $response = $this->deliver($headers);

        $this->assertSame(200, $response->status, 'the sender is not told');
        $this->assertSame('', $response->body);
        $this->assertSame([], $this->events());
    }

    public function testASubscriptionWithNoSecretTakesAnUnsignedDelivery(): void
    {
        $this->configure(['secret' => null]);

        $this->assertSame(200, $this->deliver()->status);
        $this->assertCount(1, $this->events());
    }

    public function testADeliveryForAPairThatIsNoSubscriptionIsRefusedAndTriggersNothing(): void
    {
        $signed = ['X-Hub-Signature' => 'sha256=' . hash_hmac('sha256', self::BODY, self::SECRET)];

        $this->assertSame(410, $this->deliver($signed, self::BODY, 'bob', 'bob')->status, 'a member posting to themselves');
        $this->assertSame(410, $this->deliver($signed, self::BODY, 'carol', 'ada')->status, 'a member who does not follow');
        $this->assertSame(410, $this->deliver($signed, self::BODY, 'bob', 'carol')->status, 'a user who is not followed');
        $this->configure(['hub' => false]);
        $this->assertSame(410, $this->deliver($signed)->status, 'a user with no hub');
        $this->configure(['follows' => false]);
        $this->assertSame(410, $this->deliver($signed)->status, 'a subscriber who no longer follows');
        $this->assertSame([], $this->events());
    }

    public function testAVerificationMustNameTheTopicForBothModes(): void
    {
        $this->configure(['pending' => ['subscribe:uuid' => 5, 'unsubscribe:id' => 5]]);

        foreach (['subscribe', 'unsubscribe'] as $mode) {
            $this->assertSame(403, $this->verify($mode, 'https://elsewhere.example/feed')->status, $mode . ' with another topic');
            $this->assertSame(403, $this->verify($mode, '')->status, $mode . ' with none');
            $this->assertSame(200, $this->verify($mode)->status, $mode . ' with the topic');
        }
    }

    public function testAnEntryThatHasExpiredIsNotVerifiedAndOneInsideItsLeaseIs(): void
    {
        $this->configure(['pending' => ['subscribe:uuid' => 86400 + 60]]);
        $this->assertSame(403, $this->verify('subscribe')->status);

        $this->configure(['pending' => ['subscribe:uuid' => 86400 - 60]]);
        $this->assertSame(200, $this->verify('subscribe')->status);
        $this->assertSame(403, $this->verify('subscribe')->status, 'and only once');

        $this->configure(['pending' => ['subscribe:uuid' => null]]);
        $this->assertSame(200, $this->verify('subscribe')->status, 'an entry an earlier release stored has no time');
    }

    public function testAHeadRequestConsumesNothing(): void
    {
        $this->configure(['pending' => ['subscribe:uuid' => 5]]);

        $this->assertSame(200, $this->verify('subscribe', self::TOPIC, 'HEAD')->status);
        $this->assertSame(200, $this->verify('subscribe')->status, 'the entry is still pending');
    }

    public function testAChallengeOverTheCapIsRefusedAndTheEntryStays(): void
    {
        $this->configure(['pending' => ['subscribe:uuid' => 5]]);

        $this->assertSame(403, $this->verify('subscribe', self::TOPIC, 'GET', str_repeat('a', 513))->status);
        $this->assertSame(200, $this->verify('subscribe', self::TOPIC, 'GET', str_repeat('a', 512))->status);
    }

    public function testTheChallengeIsInTheBodyAndNowhereElse(): void
    {
        $this->configure(['pending' => ['subscribe:uuid' => 5]]);

        $response = $this->verify('subscribe', self::TOPIC, 'GET', 'only-in-the-body-1234');

        $this->assertSame('only-in-the-body-1234', $response->body);
        foreach ($response->lines as $line) {
            $this->assertStringNotContainsString('only-in-the-body-1234', $line, 'not in a header');
        }
    }

    public function testTheSecretIsNeverPrintedByThePagesOfTheSite(): void
    {
        foreach (['profile/bob?_t=json', 'profile/ada?_t=json', '?_t=json', 'profile/bob', 'feed.xml', 'profile/bob?_t=rss'] as $path) {
            $answer = $this->site()->request('GET', $path);
            $this->assertStringNotContainsString(self::SECRET, $answer->body, $path);
            $this->assertStringNotContainsString('pubsub_secrets', $answer->body, $path);
        }
        $json = $this->runInSite('return json_encode([\Idno\Entities\User::getByHandle(\'bob\'), \Idno\Entities\User::getByHandle(\'ada\')]);');
        $this->assertStringNotContainsString(self::SECRET, (string) $json);
    }
}
