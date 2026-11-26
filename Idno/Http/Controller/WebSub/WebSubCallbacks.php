<?php

declare(strict_types=1);

namespace Idno\Http\Controller\WebSub;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * The WebSub callback a hub talks to, `/pubsub/callback/<subscriber>/<subscription>`: the address is unique to one subscriber and one subscription, and a hub
 * that is not the site's own calls it with no session and no form token.
 *
 * A GET verifies a subscription (or an unsubscription): the hub repeats a challenge it made up, and the callback gives it back when the site really asked
 * for what the hub says, that is, when the subscription is in the subscriber's list of pending subscriptions for that mode. The challenge comes from the
 * request, so it is answered as plain text that a browser is told not to guess the type of, never as a page.
 *
 * A POST delivers the content of a feed the subscriber follows: the body (at most `Subscriptions::maxBody()` bytes, trimmed) is handed to the listeners of
 * `websub/received` and nothing else is done with it. The hub does not sign it: WebSub gives a hub a `hub.secret` to sign deliveries with, and the
 * site sends none when it subscribes, so what authenticates a delivery is the address it is posted to.
 */
final class WebSubCallbacks
{
    /** The longest part of the hub's request that is written to the log, in bytes. */
    private const LOGGED = 100;

    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly PageRenderer $renderer,
    )
    {
    }

    /**
     * A hub's verification of a subscription.
     */
    public function verify(PageContext $page, string $subscriberId, string $subscriptionId): Response
    {
        $subscriber = $this->subscriptions->user($subscriberId);
        $subscription = $this->subscriptions->entity($subscriptionId);
        if ($subscriber === null || $subscription === null) {
            return $this->renderer->gone($page);
        }

        $mode = $this->text($page, 'mode');
        $challenge = $this->text($page, 'challenge');
        $this->subscriptions->log('WebSub: ' . self::line($mode) . ' verification ping');

        if ($mode === 'subscribe' || $mode === 'unsubscribe') {
            $pending = $this->subscriptions->pending($subscriber);
            $list = isset($pending->$mode) && is_array($pending->$mode) ? $pending->$mode : [];
            $wanted = [$subscription->getUUID(), $subscription->getID()];
            $remaining = array_values(array_filter($list, static fn (mixed $entry): bool => !in_array($entry, $wanted, true)));
            if (count($remaining) < count($list)) {
                $pending->$mode = $remaining;
                $this->subscriptions->savePending($subscriber, $pending);
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
        if ($subscriber === null || $subscription === null) {
            return $this->renderer->gone($page);
        }

        $limit = $this->subscriptions->maxBody();
        $stream = $page->request()->getContent(true);
        $content = is_resource($stream) ? (string) stream_get_contents($stream, $limit + 1) : '';
        if (strlen($content) > $limit) {
            $this->subscriptions->log('WebSub: a delivery over ' . $limit . ' bytes was refused');

            return new Response('', 413);
        }

        $this->subscriptions->log('WebSub: content received');
        $this->subscriptions->trigger('websub/received', [
            'subscriber' => $subscriber,
            'subscription' => $subscription,
            'data' => trim($content),
        ]);

        return new Response('', 200);
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
