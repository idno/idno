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
     * Takes the mention: whether the page accepted it. A route the receiver does not take is refused.
     *
     * @param list<string> $arguments the route's parameters, in order
     */
    public function receiveWebmention(string $route, array $arguments, ReceivedWebmention $mention): bool;
}
