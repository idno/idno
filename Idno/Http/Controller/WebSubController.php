<?php

declare(strict_types=1);

namespace Idno\Http\Controller;

use Idno\Http\Controller\WebSub\WebSubCallbacks;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The WebSub callback: a hub's verification of a subscription (GET) and its delivery of content (POST). A hub posts with no session and no form token
 * (`formTokenReplacedBy`), and the request acts as nobody. The callback is open to a hub on a private site. Core still registers the legacy pattern
 * `/pubsub/callback/:id/:id/?`, held by `Idno\Legacy\ControllerRoute`.
 */
#[AsController]
final class WebSubController
{
    public function __construct(private readonly WebSubCallbacks $callbacks)
    {
    }

    #[Route('/{lead}pubsub/callback/{subscriber}/{subscription}{slash}', name: 'idno_websub_callback', requirements: ['lead' => '/?', 'slash' => '/?', 'subscriber' => '[A-Za-z0-9\-]+', 'subscription' => '[A-Za-z0-9\-]+'], defaults: ['lead' => '', 'slash' => ''], methods: ['GET', 'HEAD', 'OPTIONS', 'POST'])]
    #[SitePage(
        name: 'Idno\Pages\Pubsubhubbub\Callback',
        publicOnPrivateSite: true,
        methods: ['GET', 'POST'],
        formTokenReplacedBy: 'WebSub content distribution: the hub posts to a callback address unique to one subscriber and one subscription, and the body is only handed to the listeners of websub/received'
    )]
    public function __invoke(PageContext $page, string $subscriber, string $subscription): Response
    {
        return $page->request()->isMethod('POST')
            ? $this->callbacks->distribute($page, $subscriber, $subscription)
            : $this->callbacks->verify($page, $subscriber, $subscription);
    }
}
