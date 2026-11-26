<?php

declare(strict_types=1);

namespace Idno\Http\Webmention;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The service that takes the webmentions of a page a controller serves: it is tagged `idno.webmention_receiver` with the page's name as its index
 * (`#[AsTaggedItem(index: 'Idno\Pages\Homepage')]`), and says for each of the page's routes whether a mention to it is taken. A page with no receiver, or a route its
 * receiver does not take, is a target that does not exist.
 */
#[AutoconfigureTag('idno.webmention_receiver')]
interface ReceivesWebmentions
{
    /**
     * Whether a mention aimed at this route of the page is taken (a feed, a search or a tag page of the home page's stream is not).
     */
    public function receivesFor(string $route): bool;

    /**
     * Whether a mention aimed at this route with these arguments names something that is there: a post, a shortlink's post or an account that exists, and that
     * a visitor may see, since the sender is nobody. It is asked BEFORE the sender's page is fetched, so a mention of an address that names nothing costs no
     * request. What is not there and what a visitor may not see (a private post, a draft, a deleted one) are one answer: false, with nothing logged.
     *
     * @param list<string> $arguments the route's parameters, in order
     */
    public function accepts(string $route, array $arguments): bool;

    /**
     * Takes the mention: whether the page accepted it. A route the receiver does not take is refused.
     *
     * @param list<string> $arguments the route's parameters, in order
     */
    public function receiveWebmention(string $route, array $arguments, ReceivedWebmention $mention): bool;
}
