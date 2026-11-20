<?php

declare(strict_types=1);

namespace Idno\Http\Client;

use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads a response body as a stream and stops at a size limit, whether or not the server declared a
 * length.
 */
final class BoundedBody
{
    /**
     * The body, cut at $limit bytes. Once more than $limit bytes have arrived the response is
     * cancelled; at most one chunk beyond the limit is ever held in memory. A transport failure
     * (including a silence longer than the client's idle timeout, as a TimeoutException) is thrown.
     */
    public static function read(HttpClientInterface $client, ResponseInterface $response, int $limit): string
    {
        $limit = max(0, $limit);
        $body = '';

        foreach ($client->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                $response->cancel();
                throw new TimeoutException('The server sent nothing for too long.');
            }

            $content = $chunk->getContent();
            $room = $limit - strlen($body);
            if (strlen($content) > $room) {
                $body .= substr($content, 0, $room);
                $response->cancel();
                break;
            }

            $body .= $content;
        }

        return $body;
    }
}
