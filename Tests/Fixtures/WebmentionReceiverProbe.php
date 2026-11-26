<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Http\Webmention\ReceivesWebmentions;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Test-environment only: the services tagged `idno.webmention_receiver`, by the page name each is indexed under, as the endpoint finds the receiver of a page.
 */
final class WebmentionReceiverProbe
{
    public function __construct(
        #[AutowireLocator('idno.webmention_receiver')]
        private readonly ServiceLocator $receivers,
    )
    {
    }

    /**
     * @return list<string> the page names that have a receiver
     */
    public function pages(): array
    {
        return array_keys($this->receivers->getProvidedServices());
    }

    public function receiverOf(string $page): ?ReceivesWebmentions
    {
        return $this->receivers->has($page) ? $this->receivers->get($page) : null;
    }
}
