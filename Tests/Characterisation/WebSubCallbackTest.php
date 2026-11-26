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

    private const NOBODY_WHY = 'a request that is exempt from the form token acts as nobody, whatever session it carries (the legacy page ran as the browser\'s user)';

    private const METHOD_WHY = 'a method the callback does not take is the legacy router\'s 404 page, as for every page that moved to a controller';

    private const RECEIVED_WHY = 'a content distribution triggers pubsubhubbub/received, not pubsubhubbub/ping (the event the site\'s own hub is pinged with)';

    private const KEPT_WHY = 'the verified subscription leaves the pending list and the list keeps its shape (the legacy page replaced the list with a plain array, and for a visitor its save was refused)';

    private const UNSUBSCRIBE_WHY = 'an unsubscription is pending by id and is verified by it (the legacy page compared uuids, so it never matched one)';

    private const WALLED_WHY = 'the callback is open to a hub on a private site (the legacy page ran the walled garden\'s check on the GET)';

    /** The cases the callback that replaced the legacy page answers differently on purpose, each with why: the recordings are the legacy page's. */
    private const CHANGED = [];

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
            $this->assertRecordedAnswer(static::GROUP, $case, $this->withoutTheTrace($response));
            if ($state) {
                $this->assertRecordedAnswer(self::STORED, $case, $this->text($this->storedPending()));
                $this->assertRecordedAnswer(self::EVENTS, $case, $this->text($this->events()));
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
        $this->assertChangedCases();
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
        $this->assertChangedCases();
    }

    /** The cases that are not the legacy page's answer, on purpose, held to what they answer now (see the tests of the service for the rest). */
    private function assertChangedCases(): void
    {
        // filled in with the change
    }
}
