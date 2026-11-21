<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only (config/routes/test/fixtures.php): /_test/slow sleeps `seconds` (at most 30)
 * before it answers 200; /_test/stream sends `bytes` (at most 20 MB) of the letter a, in chunks,
 * with no Content-Length; /_test/sized?bytes=N is the same body in one piece, with a Content-Length
 * that declares its size; /_test/gzip?bytes=N answers with `Content-Encoding: gzip` the gzip of N
 * zero bytes (at most 50 MB once decoded; a few dozen kilobytes on the wire), with the Content-Length
 * of the compressed body.
 */
final class SlowController
{
    private const MAX_SECONDS = 30;
    private const MAX_BYTES = 20 * 1024 * 1024;
    private const MAX_GZIP_BYTES = 50 * 1024 * 1024;

    #[Route('/_test/slow', name: 'idno_test_slow')]
    public function slow(Request $request): Response
    {
        $seconds = max(0, min(self::MAX_SECONDS, $request->query->getInt('seconds')));
        sleep($seconds);

        return new Response('slept ' . $seconds, 200, ['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain']);
    }

    #[Route('/_test/sized', name: 'idno_test_sized')]
    public function sized(Request $request): Response
    {
        $bytes = max(0, min(self::MAX_BYTES, $request->query->getInt('bytes')));

        return new Response(str_repeat('a', $bytes), 200, [
            'Cache-Control' => 'no-store',
            'Content-Type' => 'text/plain',
            'Content-Length' => (string) $bytes,
        ]);
    }

    #[Route('/_test/gzip', name: 'idno_test_gzip')]
    public function gzip(Request $request): Response
    {
        $bytes = max(0, min(self::MAX_GZIP_BYTES, $request->query->getInt('bytes')));

        // Compressed in pieces, so that no more than a megabyte of the zeros exists at once.
        $context = deflate_init(ZLIB_ENCODING_GZIP);
        $piece = str_repeat("\0", 1024 * 1024);
        $body = '';
        for ($left = $bytes; $left > 0; $left -= strlen($piece)) {
            $body .= deflate_add($context, $left >= strlen($piece) ? $piece : substr($piece, 0, $left), ZLIB_NO_FLUSH);
        }
        $body .= deflate_add($context, '', ZLIB_FINISH);

        return new Response($body, 200, [
            'Cache-Control' => 'no-store',
            'Content-Type' => 'application/octet-stream',
            'Content-Encoding' => 'gzip',
            'Content-Length' => (string) strlen($body),
        ]);
    }

    #[Route('/_test/stream', name: 'idno_test_stream')]
    public function stream(Request $request): Response
    {
        $bytes = max(0, min(self::MAX_BYTES, $request->query->getInt('bytes')));

        return new StreamedResponse(static function () use ($bytes): void {
            $chunk = str_repeat('a', 8192);
            for ($left = $bytes; $left > 0; $left -= strlen($chunk)) {
                echo $left >= strlen($chunk) ? $chunk : substr($chunk, 0, $left);
                flush();
            }
        }, 200, ['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain']);
    }
}
