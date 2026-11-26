<?php

declare(strict_types=1);

namespace Idno\Http\Controller\WebSub;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * The WebSub callback a hub talks to, `/pubsub/callback/<subscriber>/<subscription>`. A hub is another server: it has no session and no form token.
 *
 * A GET verifies a subscription (or an unsubscription): the hub repeats a challenge it made up, and the callback gives it back only when the site asked for
 * what the hub says: the subscription is in the subscriber's list of pending subscriptions for that mode (`PendingList`: not older than its lease) AND the
 * hub's `hub.topic` is the feed the site subscribed to (`Subscriptions::topic()`, compared with `hash_equals()`). Anything else is refused and changes nothing.
 * The challenge comes from the request, so it is answered as plain text that a browser is told not to guess the type of, never as a page, at most
 * `MAX_CHALLENGE` bytes of it. A HEAD gets the answer of the GET and consumes nothing.
 *
 * A POST delivers the content of a feed the subscriber follows. The member ids in an address are public, so the address is no secret: a delivery is accepted
 * only for a subscription that exists (the subscriber follows the subscription, and the subscription has a hub), and, when the site sent the hub a secret
 * (`hub.secret`, for a hub that is https), only with a valid `X-Hub-Signature` (`sha256`, `sha384` or `sha512`: the HMAC of the raw body with the secret,
 * compared with `hash_equals()`). A delivery that fails the signature is answered as an accepted one is (WebSub: the subscriber does not tell the sender) and
 * triggers nothing. A subscription made before the site sent secrets has none and is held to the first rule alone. The body (at most `Subscriptions::maxBody()`
 * bytes, trimmed) is handed to the listeners of `websub/received` and nothing else is done with it.
 */
final class WebSubCallbacks
{
    /** The longest part of the hub's request that is written to the log, in bytes. */
    private const LOGGED = 100;

    /** The longest challenge that is answered, in bytes: a hub's are short random strings. */
    public const MAX_CHALLENGE = 512;

    /** The signature methods a delivery may use. SHA-1 is not one. */
    private const SIGNATURE_METHODS = ['sha256', 'sha384', 'sha512'];

    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly PageRenderer $renderer,
    )
    {
    }

    /**
     * A hub's verification of a subscription. `$consume` is false for a HEAD, which answers as the GET does and removes nothing.
     */
    public function verify(PageContext $page, string $subscriberId, string $subscriptionId, bool $consume = true): Response
    {
        $subscriber = $this->subscriptions->user($subscriberId);
        $subscription = $this->subscriptions->entity($subscriptionId);
        if ($subscriber === null || $subscription === null) {
            return $this->renderer->gone($page);
        }

        $mode = $this->text($page, 'mode');
        $challenge = $this->text($page, 'challenge');
        $topic = $this->text($page, 'topic');
        $this->subscriptions->log('WebSub: ' . self::line($mode) . ' verification ping');

        $expected = $this->subscriptions->topic($subscription);
        if (($mode === 'subscribe' || $mode === 'unsubscribe') && strlen($challenge) <= self::MAX_CHALLENGE && $expected !== '' && hash_equals($expected, $topic)) {
            $pending = $this->subscriptions->pending($subscriber);
            $wanted = [$subscription->getUUID(), $subscription->getID()];
            if (PendingList::isPending($pending, $mode, $wanted, $this->subscriptions->now())) {
                if ($consume) {
                    PendingList::remove($pending, $mode, $wanted);
                    $this->subscriptions->savePending($subscriber, $pending);
                }
                $this->subscriptions->log('WebSub: ' . $mode . ' verified');

                // The challenge is taken from the request: plain text, and browsers are told not to guess another type, so it is never run as HTML
                return new Response($challenge, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff']);
            }
        }

        return $this->renderer->denied($page);
    }

    /**
     * A hub's delivery of content.
     */
    public function distribute(PageContext $page, string $subscriberId, string $subscriptionId): Response
    {
        $subscriber = $this->subscriptions->user($subscriberId);
        $subscription = $this->subscriptions->user($subscriptionId);
        if ($subscriber === null || $subscription === null || !$this->subscriptions->isSubscribed($subscriber, $subscription)) {
            return $this->renderer->gone($page);
        }

        $limit = $this->subscriptions->maxBody();
        $stream = $page->request()->getContent(true);
        $content = is_resource($stream) ? (string) stream_get_contents($stream, $limit + 1) : '';
        if (strlen($content) > $limit) {
            $this->subscriptions->log('WebSub: a delivery over ' . $limit . ' bytes was refused');

            return new Response('', 413);
        }

        $secret = $this->subscriptions->secret($subscriber, $subscription);
        if ($secret !== '' && !self::signedWith($secret, $content, $page->request()->headers->get('X-Hub-Signature'))) {
            // Answered as an accepted delivery is: the sender is not told, and nothing is done with the content
            $this->subscriptions->log('WebSub: a delivery with no valid signature was ignored');

            return new Response('', 200);
        }

        $this->subscriptions->log('WebSub: content received');
        $this->subscriptions->trigger('websub/received', [
            'subscriber' => $subscriber,
            'subscription' => $subscription,
            'data' => trim($content),
        ]);

        return new Response('', 200);
    }

    /** Whether the header is `<method>=<hex digest>` of one of the methods and the digest is the HMAC of the body with the secret. */
    private static function signedWith(string $secret, string $body, ?string $header): bool
    {
        if ($header === null || !str_contains($header, '=')) {
            return false;
        }
        [$method, $digest] = explode('=', $header, 2);
        $method = strtolower($method);
        if (!in_array($method, self::SIGNATURE_METHODS, true)) {
            return false;
        }

        return hash_equals(hash_hmac($method, $body, $secret), strtolower($digest));
    }

    /** A field of the hub's request, as `hub.x` or `hub_x` (PHP writes a dot in a name as an underscore): its text, or '' when it is missing or is not text. */
    private function text(PageContext $page, string $name): string
    {
        $value = $page->input('hub.' . $name, $page->input('hub_' . $name));

        return is_string($value) ? $value : '';
    }

    /** Text from a request, made safe to write in a log line: printable ASCII only, and cut. */
    private static function line(string $text): string
    {
        return substr((string) preg_replace('/[^\x20-\x7e]/', '?', $text), 0, self::LOGGED);
    }
}
