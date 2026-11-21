<?php

declare(strict_types=1);

namespace Idno\Http\Client;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Sits under the private-network client when the site sends requests through a proxy. The connection
 * the client makes is to the proxy, so the address it reports as connected to (`primary_ip`) is the
 * proxy's, not the target's; that report is hidden from the private-network client when it is one of
 * the proxy's addresses, so that it does not refuse the proxy for being on a private network. It
 * allows nothing else: the target's address is still checked before the request, and a target at the
 * proxy's own address is refused like any other private one.
 */
final class ProxyAddressMask implements HttpClientInterface, ResetInterface
{
    /** @var list<string> packed addresses */
    private readonly array $addresses;

    /**
     * @param list<string> $addresses the proxy's IP addresses, in any written form
     */
    public function __construct(private HttpClientInterface $client, array $addresses)
    {
        $packed = [];
        foreach ($addresses as $address) {
            $binary = @inet_pton($address);
            if ($binary !== false) {
                $packed[] = $binary;
            }
        }
        $this->addresses = $packed;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $onProgress = $options['on_progress'] ?? null;
        if (is_callable($onProgress)) {
            $addresses = $this->addresses;
            $options['on_progress'] = static function (int $downloaded, int $size, array $info) use ($onProgress, $addresses): void {
                $reported = isset($info['primary_ip']) && is_string($info['primary_ip']) ? @inet_pton($info['primary_ip']) : false;
                if ($reported !== false && in_array($reported, $addresses, true)) {
                    $info['primary_ip'] = '';
                }
                $onProgress($downloaded, $size, $info);
            };
        }

        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }

    public function reset(): void
    {
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }
}
