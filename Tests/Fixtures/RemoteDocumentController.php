<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only (config/routes/test/fixtures.php): the pages of a stand-in for another server. A test that needs a webmention's source (or any
 * page the site fetches from a remote party) stores the document with `PUT /_test/remote/{name}` (the body is the document; `?status=` its status, 200 by
 * default; `?type=` its content type, `text/html; charset=utf-8` by default), and the site fetches it from `http://93.184.216.34/{name}`: a public address
 * that `UrlValidator::isSafeUrl()` accepts without a lookup, which `LocalOnlyHttpClient` answers from this route when the site's environment names it in
 * IDNO_TEST_REMOTE_STAND_IN. `GET /_test/remote/{name}` serves the document (404 when there is none); every fetch is recorded, and
 * `GET /_test/remote-fetched` lists the names fetched since it was last asked, oldest first, and forgets them. The files are in a directory of the
 * system temporary directory that is named after this checkout's real path (a file name starts with the port of the site that answers, so two sites share nothing), written under an exclusive lock (the web server has several workers).
 */
final class RemoteDocumentController
{
    private const NAME = '[a-z0-9-]{1,40}';

    #[Route('/_test/remote/{name}', name: 'idno_test_remote_document', requirements: ['name' => self::NAME], methods: ['GET', 'HEAD', 'PUT'])]
    public function document(Request $request, string $name): Response
    {
        $file = self::directory() . '/' . $request->getPort() . '-' . $name . '.document';
        if ($request->isMethod('PUT')) {
            $query = $request->query->all();
            $status = is_string($query['status'] ?? null) ? max(100, min(599, (int) $query['status'])) : 200;
            $type = is_string($query['type'] ?? null) ? $query['type'] : 'text/html; charset=utf-8';
            file_put_contents($file, json_encode(['status' => $status, 'type' => $type, 'body' => base64_encode($request->getContent())], JSON_THROW_ON_ERROR), LOCK_EX);

            return new Response('', 204, ['Cache-Control' => 'no-store']);
        }
        file_put_contents(self::directory() . '/' . $request->getPort() . '-fetched.log', $name . "\n", FILE_APPEND | LOCK_EX);
        $stored = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($stored)) {
            return new Response('No such document.', 404, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
        }

        return new Response((string) base64_decode((string) $stored['body']), (int) $stored['status'], ['Content-Type' => (string) $stored['type'], 'Cache-Control' => 'no-store']);
    }

    #[Route('/_test/remote-fetched', name: 'idno_test_remote_fetched', methods: ['GET'])]
    public function fetched(Request $request): JsonResponse
    {
        $names = [];
        $handle = fopen(self::directory() . '/' . $request->getPort() . '-fetched.log', 'c+');
        if ($handle !== false) {
            if (flock($handle, LOCK_EX)) {
                $names = array_values(array_filter(explode("\n", (string) stream_get_contents($handle)), static fn (string $line): bool => $line !== ''));
                ftruncate($handle, 0);
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }

        return new JsonResponse($names, 200, ['Cache-Control' => 'no-store']);
    }

    private static function directory(): string
    {
        $directory = rtrim(sys_get_temp_dir(), '/') . '/idno-test-remote-' . substr(hash('sha256', (string) realpath(dirname(__DIR__, 2))), 0, 12);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        return $directory;
    }
}
