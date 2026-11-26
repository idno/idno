<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the WebSub callback (`/pubsub/callback/<subscriber>/<subscription>`) answers, recorded from the legacy page over HTTP, and what each request does to the
 * subscriber's list of pending subscriptions and which events it triggers. The answers are `Tests/Characterisation/fixtures/answers/websub/`.
 *
 * The subscriber is Bob and the subscription is Ada, whose ids are fixed. Each case writes the pending list the way the listener of `follow` writes it (an
 * object of two lists: `subscribe` holds the followed user's uuid, `unsubscribe` their id), makes its request as the hub does (no session unless a case
 * says so) and records the answer, the list as it is stored afterwards (group `websub-pending`) and the events the request triggered (group `websub-events`, one JSON line each).
 */
final class WebSubCallbackTest extends PermalinkSiteCase
{
    use RecordedAnswers;

    protected const GROUP = 'websub';

    /** The list of pending subscriptions as it is stored after each request, and the events each request triggered. */
    private const STORED = 'websub-pending';

    private const EVENTS = 'websub-events';

    private const CHALLENGE_WHY = 'a subscription or an unsubscription is verified by the uuid or by the id of the subscription in the pending list (the legacy page compared uuids, so an unsubscription, which is pending by id, was never verified)';

    private const BROKEN_WHY = 'a pending list or a mode that is not what the callback reads is a request it does not verify (403), where the legacy page ended in the 500 page';

    private const DELIVERY_WHY = 'a delivery is handed to the listeners of websub/received, and the answer is 200 with no body (the legacy page triggered websub/ping, whose listener of the site\'s own hub failed on content with no `object`: the 500 page)';

    private const METHOD_WHY = 'a method the callback does not take is the legacy router\'s 404 page, as for every page that moved to a controller';

    private const WALLED_WHY = 'the callback is open to a hub on a private site (the legacy page ran the walled garden\'s check on the GET)';

    private const PAGE_WHY = 'a delivery is a request of a page of the site: the page that refuses it carries the headers every page of the site carries (the legacy page took the POST in its own method, outside the lifecycle of a page)';

    /**
     * The cases the callback that replaced the legacy page answers differently on purpose, each with why, and what it answers now: the status and, for a 200,
     * the body ('' for none). The recordings are the legacy page's and are kept as they were.
     */
    private const CHANGED = [
        'subscribe-pending-by-id' => self::CHALLENGE_WHY,
        'unsubscribe-pending-id' => self::CHALLENGE_WHY,
        'subscribe-mode-array' => self::BROKEN_WHY,
        'pending-is-nothing' => self::BROKEN_WHY,
        'pending-is-text' => self::BROKEN_WHY,
        'subscribe-no-list' => self::BROKEN_WHY,
        'unsubscribe-pending-subscription-only' => self::BROKEN_WHY,
        'post-known' => self::DELIVERY_WHY,
        'post-known-twice' => self::DELIVERY_WHY,
        'post-no-body' => self::DELIVERY_WHY,
        'post-with-cookie' => self::DELIVERY_WHY,
        'post-with-admin-cookie' => self::DELIVERY_WHY,
        'post-with-query' => self::DELIVERY_WHY,
        'walled-post-known' => self::DELIVERY_WHY,
        'post-unknown-subscriber' => self::PAGE_WHY,
        'post-unknown-subscription' => self::PAGE_WHY,
        'walled-post-unknown' => self::PAGE_WHY,
        'put-visitor' => self::METHOD_WHY,
        'delete-visitor' => self::METHOD_WHY,
        'malformed-host' => self::METHOD_WHY,
        'walled-get-subscribe' => self::WALLED_WHY,
        'subscribe-wrong-topic' => self::TOPIC_WHY,
        'subscribe-no-challenge' => self::TOPIC_WHY,
        'walled-get-nothing-pending' => 'a request the callback refuses on a private site is the page of the site that refuses it, with the headers every page of the site carries (the legacy page was refused by the walled garden before its page began)',
    ];

    private const TOPIC_WHY = 'a verification must name the topic the site subscribed to (the legacy page did not look at it, so anyone who knew the address could confirm a subscription to any topic)';

    /** @var array<string, array{int, string|null}> what the changed cases answer: the status and the body of a 200 (null: any) */
    private const EXPECTED = [
        'subscribe-pending-by-id' => [200, 'challenge-1234'],
        'unsubscribe-pending-id' => [200, 'challenge-1234'],
        'subscribe-mode-array' => [403, null],
        'pending-is-nothing' => [403, null],
        'pending-is-text' => [403, null],
        'subscribe-no-list' => [403, null],
        'unsubscribe-pending-subscription-only' => [403, null],
        'post-known' => [200, ''],
        'post-known-twice' => [200, ''],
        'post-no-body' => [200, ''],
        'post-with-cookie' => [200, ''],
        'post-with-admin-cookie' => [200, ''],
        'post-with-query' => [200, ''],
        'walled-post-known' => [200, ''],
        'post-unknown-subscriber' => [410, null],
        'post-unknown-subscription' => [410, null],
        'walled-post-unknown' => [410, null],
        'put-visitor' => [404, null],
        'delete-visitor' => [404, null],
        'malformed-host' => [404, null],
        'walled-get-subscribe' => [200, 'challenge-1234'],
        'walled-get-nothing-pending' => [403, null],
        'subscribe-wrong-topic' => [403, null],
        'subscribe-no-challenge' => [403, null],
    ];

    /**
     * The cases that leave the pending list another way: the verified subscription is removed and the list is still an object of lists (the legacy page
     * stored the list of the mode as a plain array, with the subscription still in it, and the next follow failed on it). What is stored, as JSON.
     *
     * @var array<string, string>
     */
    private const STORED_CHANGED = [
        'subscribe-pending' => '{"subscribe":[]}',
        'subscribe-pending-with-others' => '{"subscribe":["{bob-uuid}"],"unsubscribe":["{ada-id}"]}',
        'subscribe-hub-underscore' => '{"subscribe":[]}',
        'subscribe-wrong-topic' => '{"subscribe":["{ada-uuid}"]}',
        'subscribe-no-challenge' => '{"subscribe":["{ada-uuid}"]}',
        'subscribe-hostile-challenge' => '{"subscribe":[]}',
        'subscribe-pending-trailing-slash' => '{"subscribe":[]}',
        'subscribe-pending-member-cookie' => '{"subscribe":[]}',
        'subscribe-pending-by-id' => '{"subscribe":[]}',
        'unsubscribe-pending-uuid' => '{"unsubscribe":[]}',
        'unsubscribe-pending-id' => '{"unsubscribe":[]}',
        'head-pending' => '{"subscribe":["{ada-uuid}"]}',
        'walled-get-subscribe' => '{"subscribe":[]}',
        'walled-get-member' => '{"subscribe":[]}',
    ];

    /** The cases whose events are the legacy ones with the event renamed and the user nobody: a delivery, which acts as nobody, triggers websub/received. */
    private const EVENTS_CHANGED = ['post-known', 'post-known-twice', 'post-no-body', 'post-with-cookie', 'post-with-admin-cookie', 'post-with-query', 'walled-post-known'];

    private static string $eventsFile = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$eventsFile = (string) tempnam(sys_get_temp_dir(), 'idno-websub-');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            if (self::$eventsFile !== '' && is_file(self::$eventsFile)) {
                unlink(self::$eventsFile);
            }
            self::$eventsFile = '';
            parent::tearDownAfterClass();
        }
    }

    protected function seedsOwnedPosts(): bool
    {
        return false;
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_RECORD_WEBSUB' => self::$eventsFile] + (str_contains($this->getName(), 'PrivateSite') ? ['IDNO_WALLED_GARDEN' => '1'] : []);
    }

    /**
     * Writes Bob's pending list as the follow listener writes it. `$subscribe` and `$unsubscribe` hold `uuid`, `id` and `bob` (the followed user's
     * uuid, their id and Bob's own uuid); null leaves the list out.
     *
     * @param list<string>|null $subscribe
     * @param list<string>|null $unsubscribe
     */
    private function pend(?array $subscribe, ?array $unsubscribe, ?string $raw = null): void
    {
        $code = <<<'PHP'
        $ada = \Idno\Entities\User::getByHandle('ada');
        $bob = \Idno\Entities\User::getByHandle('bob');
        $values = ['uuid' => $ada->getUUID(), 'id' => $ada->getID(), 'bob' => $bob->getUUID()];
        // What makes the subscription a real one: Ada's feed and hub, and Bob following her
        $ada->pubsub_self = 'https://ada.example/feed';
        $ada->pubsub_hub = 'https://hub.example/';
        if (!$ada->save(true)) {
            throw new \RuntimeException('ada not saved');
        }
        $bob->following = [$ada->getUUID() => ['name' => 'Ada Admin']];
        $raw = json_decode(%RAW%, true);
        if ($raw !== null) {
            $bob->pubsub_pending = $raw;
        } else {
            $pending = new \stdClass();
            $subscribe = json_decode(%SUBSCRIBE%, true);
            $unsubscribe = json_decode(%UNSUBSCRIBE%, true);
            if ($subscribe !== null) {
                $pending->subscribe = array_map(static fn (string $name): string => $values[$name], $subscribe);
            }
            if ($unsubscribe !== null) {
                $pending->unsubscribe = array_map(static fn (string $name): string => $values[$name], $unsubscribe);
            }
            $bob->pubsub_pending = serialize($pending);
        }
        if (!$bob->save(true)) {
            throw new \RuntimeException('bob not saved');
        }

        return true;
        PHP;
        $this->runInSite(str_replace(['%RAW%', '%SUBSCRIBE%', '%UNSUBSCRIBE%'], [$this->literal(json_encode($raw)), $this->literal(json_encode($subscribe)), $this->literal(json_encode($unsubscribe))], $code));
    }

    /** Bob's pending list as it is stored (the text that was saved, with the ids and the uuids of the site written as names). */
    private function storedPending(): string
    {
        $code = <<<'PHP'
        $ada = \Idno\Entities\User::getByHandle('ada');
        $bob = \Idno\Entities\User::getByHandle('bob');
        $stored = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class)->findById('entities', $bob->getID())['pubsub_pending'] ?? null;

        return is_string($stored) ? str_replace([$ada->getUUID(), $ada->getID(), $bob->getUUID()], ['{ada-uuid}', '{ada-id}', '{bob-uuid}'], $stored) : 'none';
        PHP;

        return (string) $this->runInSite($code);
    }

    /** The events recorded since this was last asked, one line each (the user's uuid and the site's uuids written as names). */
    private function events(): string
    {
        $text = (string) file_get_contents(self::$eventsFile);
        file_put_contents(self::$eventsFile, '');

        return $text === '' ? "none\n" : str_replace(['https://fixture.example/view/00000000000040008000000000000001', 'https://fixture.example/view/00000000000040008000000000000002'], ['{ada-uuid}', '{bob-uuid}'], $text);
    }

    /**
     * A 500 page of a site in debug mode holds the exception's trace, whose paths are the machine's: it is recorded as the page and the exception's message.
     */
    private function withoutTheTrace(SiteResponse $response): SiteResponse
    {
        if ($response->status !== 500) {
            return $response;
        }
        $message = preg_match('/(Undefined array key &quot;[a-z]+&quot;|Attempt to read property[^<]*?on [a-z]+|Undefined (?:array key|property)[^<]*)/', $response->body, $found) === 1 ? preg_replace('/ in \/.*\z/s', '', html_entity_decode($found[1])) : 'no message found';

        return new SiteResponse(500, ['Content-Type' => (string) $response->header('Content-Type')], "(the 500 page: " . $message . ")\n");
    }

    /**
     * The answer compared with its recording, but for the `Link: rel="self"` line: the legacy page's carried the whole request address, the hub's challenge
     * in its query included, and the page's now carries its path alone (the challenge is answered once and is reflected nowhere but in the plain-text body).
     */
    private function assertAnswer(string $case, SiteResponse $response): void
    {
        if (static::captureMode() !== 'compare' || isset(self::CHANGED[$case])) {
            $this->assertRecordedAnswer(static::GROUP, $case, $response);

            return;
        }
        $file = self::recordedAnswersDirectory() . '/' . static::GROUP . '/' . $case . '.txt';
        $this->assertFileExists($file, $case);
        $actual = self::normalise($response, $this->baseUrl());
        foreach (explode("\n", $actual) as $line) {
            if (str_ends_with($line, '; rel="self"') && str_starts_with($line, 'link: ')) {
                $this->assertStringNotContainsString('?', $line, $case . ': the page\'s own address has no query');
            }
        }
        $neutral = static fn (string $text): string => (string) preg_replace('/^link: <[^>]*>; rel="self"$/m', 'link: <{self}>; rel="self"', $text);
        $this->assertSame($neutral((string) file_get_contents($file)), $neutral($actual), $case);
    }

    /** A case in `CHANGED` answers what `EXPECTED` says. */
    private function assertChanged(string $case, SiteResponse $response): void
    {
        [$status, $body] = self::EXPECTED[$case] ?? throw new \LogicException('No expectation for ' . $case);
        $this->assertSame($status, $response->status, $case);
        if ($body !== null) {
            $this->assertSame($body, $response->body, $case);
        }
        if ($body === 'challenge-1234') {
            $this->assertSame('text/plain; charset=utf-8', $response->header('Content-Type'), $case);
            $this->assertSame('nosniff', strtolower((string) $response->header('X-Content-Type-Options')), $case);
        }
    }

    /** The list as stored after the request: the recording's, or for a case in `STORED_CHANGED` what that says. */
    private function assertStored(string $case): void
    {
        if (!isset(self::STORED_CHANGED[$case])) {
            $this->assertSameAsRecorded(self::STORED, $case, $this->text($this->storedPending()));

            return;
        }
        $this->assertSame(self::STORED_CHANGED[$case], $this->storedJson(), $case . ': the list as stored');
    }

    /** The events triggered: the recording's, or for a case in `EVENTS_CHANGED` the recording's with the event renamed and the user nobody. */
    private function assertEvents(string $case): void
    {
        $events = $this->events();
        if (!in_array($case, self::EVENTS_CHANGED, true)) {
            $this->assertSameAsRecorded(self::EVENTS, $case, $this->text($events));

            return;
        }
        $recorded = explode("\n\n", (string) file_get_contents(self::recordedAnswersDirectory() . '/' . self::EVENTS . '/' . $case . '.txt'), 2)[1];
        $expected = '';
        foreach (array_filter(explode("\n", $recorded)) as $line) {
            $event = json_decode($line, true);
            $this->assertSame('websub/ping', $event['event'], $case);
            $expected .= json_encode(array_replace($event, ['event' => 'websub/received', 'user' => null])) . "\n";
        }
        $this->assertSame($expected, $events, $case . ': the events');
    }

    /** One answer compared with its recording, whether or not the case is one whose answer changed. */
    private function assertSameAsRecorded(string $group, string $case, SiteResponse $response): void
    {
        if (static::captureMode() !== 'compare') {
            $this->assertRecordedAnswer($group, $case, $response);

            return;
        }
        $file = self::recordedAnswersDirectory() . '/' . $group . '/' . $case . '.txt';
        $this->assertFileExists($file, $group . '/' . $case);
        $this->assertSame((string) file_get_contents($file), self::normalise($response, $this->baseUrl()), $group . '/' . $case);
    }

    /** Bob's pending list as stored, as JSON (an object of lists, or a plain list when the legacy shape is stored), the site's ids written as names. */
    private function storedJson(): string
    {
        $code = <<<'PHP'
        $ada = \Idno\Entities\User::getByHandle('ada');
        $bob = \Idno\Entities\User::getByHandle('bob');
        $stored = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class)->findById('entities', $bob->getID())['pubsub_pending'] ?? null;
        $pending = is_string($stored) ? unserialize($stored, ['allowed_classes' => [\stdClass::class]]) : null;

        return str_replace([$ada->getUUID(), $ada->getID(), $bob->getUUID()], ['{ada-uuid}', '{ada-id}', '{bob-uuid}'], (string) json_encode($pending, JSON_UNESCAPED_SLASHES));
        PHP;

        return (string) $this->runInSite($code);
    }

    private function text(string $text): SiteResponse
    {
        return new SiteResponse(200, ['Content-Type' => 'text/plain'], $text . "\n");
    }

    /**
     * One case: the pending list is written, the request made, and the answer, the list afterwards and the events recorded.
     *
     * @param list<string>|null $subscribe
     * @param list<string>|null $unsubscribe
     * @param array<string, string> $headers
     */
    private function case(string $case, ?array $subscribe, ?array $unsubscribe, string $method, string $path, array $headers = [], string $viewer = 'visitor', ?string $body = null, bool $state = true, ?string $raw = null): SiteResponse
    {
        $response = null;
        foreach (range(1, static::passes()) as $_) {
            $this->pend($subscribe, $unsubscribe, $raw);
            $this->events();
            $response = $this->site()->request($method, $path, $this->asViewer($viewer, $headers), $body);
            $this->assertAnswer($case, $this->withoutTheTrace($response));
            if (isset(self::CHANGED[$case])) {
                $this->assertChanged($case, $response);
            }
            if ($state) {
                $this->assertStored($case);
                $this->assertEvents($case);
            }
        }

        return $response;
    }

    /** @return string the callback's address for a subscriber and a subscription (default Bob and Ada) */
    private function address(string $query = '', ?string $subscriber = null, ?string $subscription = null): string
    {
        $ids = $this->seedPermalinkContent()['ids'];

        return 'pubsub/callback/' . ($subscriber ?? $ids['bob']) . '/' . ($subscription ?? $ids['ada']) . ($query === '' ? '' : '?' . $query);
    }

    private function challenge(string $mode, string $challenge = 'challenge-1234', string $topic = 'https://ada.example/feed', string $spelling = 'hub.'): string
    {
        return http_build_query([$spelling . 'mode' => $mode, $spelling . 'topic' => $topic, $spelling . 'challenge' => $challenge, $spelling . 'lease_seconds' => '3600']);
    }

    private function scenario(): void
    {
        $this->seedPermalinkContent();
        $ids = $this->seedPermalinkContent()['ids'];
        $body = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Ada</title></feed>';

        // The hub verifies a subscription that is pending
        $this->case('subscribe-pending', ['uuid'], null, 'GET', $this->address($this->challenge('subscribe')));
        $this->case('subscribe-pending-with-others', ['bob', 'uuid'], ['id'], 'GET', $this->address($this->challenge('subscribe')));
        $this->case('subscribe-hub-underscore', ['uuid'], null, 'GET', $this->address($this->challenge('subscribe', 'underscore-5678', 'https://ada.example/feed', 'hub_')));
        $this->case('subscribe-wrong-topic', ['uuid'], null, 'GET', $this->address($this->challenge('subscribe', 'challenge-1234', 'https://elsewhere.example/feed')));
        $this->case('subscribe-no-challenge', ['uuid'], null, 'GET', $this->address('hub.mode=subscribe'));
        $this->case('subscribe-hostile-challenge', ['uuid'], null, 'GET', $this->address($this->challenge('subscribe', '<script>alert(document.domain)</script>')));
        $this->case('subscribe-pending-trailing-slash', ['uuid'], null, 'GET', str_replace('?', '/?', $this->address($this->challenge('subscribe'))));
        $this->case('subscribe-pending-member-cookie', ['uuid'], null, 'GET', $this->address($this->challenge('subscribe')), [], 'member');
        $this->case('subscribe-pending-by-id', ['id'], null, 'GET', $this->address($this->challenge('subscribe')));

        // ... and one that is not
        $this->case('subscribe-nothing-pending', [], null, 'GET', $this->address($this->challenge('subscribe')));
        $this->case('subscribe-no-list', null, null, 'GET', $this->address($this->challenge('subscribe')));
        $this->case('subscribe-another-pending', ['bob'], null, 'GET', $this->address($this->challenge('subscribe')));
        $this->case('subscribe-pending-unsubscription-only', [], ['uuid'], 'GET', $this->address($this->challenge('subscribe')));
        $this->case('no-mode', ['uuid'], ['uuid'], 'GET', $this->address('hub.challenge=abc'));
        $this->case('other-mode', ['uuid'], ['uuid'], 'GET', $this->address($this->challenge('denied')));
        $this->case('no-parameters', ['uuid'], null, 'GET', $this->address());
        $this->case('subscribe-mode-array', ['uuid'], null, 'GET', $this->address('hub.mode[]=subscribe&hub.challenge=abc'), [], 'visitor', null, false);

        // The list a verification leaves, and a second verification of the same subscription
        $this->case('unsubscribe-pending-uuid', null, ['uuid'], 'GET', $this->address($this->challenge('unsubscribe')));
        $this->case('unsubscribe-pending-id', null, ['id'], 'GET', $this->address($this->challenge('unsubscribe')));
        $this->case('unsubscribe-nothing-pending', ['uuid'], [], 'GET', $this->address($this->challenge('unsubscribe')));
        $this->case('unsubscribe-pending-subscription-only', ['uuid'], null, 'GET', $this->address($this->challenge('unsubscribe')));

        // A pending list that is not an object of lists
        $this->case('pending-is-nothing', null, null, 'GET', $this->address($this->challenge('subscribe')), [], 'visitor', null, true, '');
        $this->case('pending-is-text', null, null, 'GET', $this->address($this->challenge('subscribe')), [], 'visitor', null, true, serialize('text'));

        // Subscriber and subscription that do not exist, or are not users
        $this->case('unknown-subscriber', ['uuid'], null, 'GET', $this->address($this->challenge('subscribe'), '00000000000040008000000000000fff'));
        $this->case('unknown-subscription', ['uuid'], null, 'GET', $this->address($this->challenge('subscribe'), null, '00000000000040008000000000000fff'));
        $this->case('unknown-both', ['uuid'], null, 'GET', $this->address($this->challenge('subscribe'), '00000000000040008000000000000ffe', '00000000000040008000000000000fff'));

        // The other methods
        $this->case('head-pending', ['uuid'], null, 'HEAD', $this->address($this->challenge('subscribe')));
        $this->case('head-nothing-pending', [], null, 'HEAD', $this->address($this->challenge('subscribe')));
        $this->case('options-visitor', ['uuid'], null, 'OPTIONS', $this->address(), [], 'visitor', null, false);
        $this->case('put-visitor', ['uuid'], null, 'PUT', $this->address($this->challenge('subscribe')), [], 'visitor', null, false);
        $this->case('delete-visitor', ['uuid'], null, 'DELETE', $this->address($this->challenge('subscribe')), [], 'visitor', null, false);
        $this->case('malformed-host', ['uuid'], null, 'GET', $this->address($this->challenge('subscribe')), ['Host' => 'bad host!'], 'visitor', null, false);

        // Content distribution
        $feed = ['Content-Type' => 'application/atom+xml'];
        $this->case('post-known', ['uuid'], null, 'POST', $this->address(), $feed, 'visitor', $body);
        $this->case('post-known-twice', ['uuid'], null, 'POST', $this->address(), $feed, 'visitor', "  \n" . $body . "\n\n");
        $this->case('post-no-body', ['uuid'], null, 'POST', $this->address(), $feed);
        $this->case('post-unknown-subscriber', ['uuid'], null, 'POST', $this->address('', '00000000000040008000000000000fff'), $feed, 'visitor', $body);
        $this->case('post-unknown-subscription', ['uuid'], null, 'POST', $this->address('', null, '00000000000040008000000000000fff'), $feed, 'visitor', $body);
        $this->case('post-with-cookie', ['uuid'], null, 'POST', $this->address(), $feed, 'member', $body);
        $this->case('post-with-admin-cookie', ['uuid'], null, 'POST', $this->address(), $feed, 'admin', $body);
        $this->case('post-with-query', ['uuid'], null, 'POST', $this->address($this->challenge('subscribe')), $feed, 'visitor', $body);
    }

    private function walledScenario(): void
    {
        $body = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Ada</title></feed>';
        $this->case('walled-get-subscribe', ['uuid'], null, 'GET', $this->address($this->challenge('subscribe')));
        $this->case('walled-get-nothing-pending', [], null, 'GET', $this->address($this->challenge('subscribe')));
        $this->case('walled-get-member', ['uuid'], null, 'GET', $this->address($this->challenge('subscribe')), [], 'member');
        $this->case('walled-post-known', ['uuid'], null, 'POST', $this->address(), ['Content-Type' => 'application/atom+xml'], 'visitor', $body);
        $this->case('walled-post-unknown', ['uuid'], null, 'POST', $this->address('', '00000000000040008000000000000fff'), ['Content-Type' => 'application/atom+xml'], 'visitor', $body);
    }

    public function testWhatTheCallbackAnswersToEachRequest(): void
    {
        $this->scenario();
    }

    /** The same on another site (the second recording of each case in a verified capture). */
    public function testTheSameRequestsOnAnotherSiteAreAnsweredTheSame(): void
    {
        if (static::passes() === 1) {
            $this->markTestSkipped('only a verified capture records the cases on a second site');
        }
        $this->scenario();
    }

    public function testWhatTheCallbackAnswersOnAPrivateSite(): void
    {
        $this->walledScenario();
    }
}
