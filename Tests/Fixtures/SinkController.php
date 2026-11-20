<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-environment only (config/routes/test/fixtures.php): a place for the site to send requests to
 * unprompted. /_test/sink/{name} appends the request (in /_test/echo's shape) to a file of its own
 * and answers 202; /_test/sink/{name}/log returns the recorded requests as a JSON list and empties
 * the file. The files are in a directory under the system temporary directory named after this
 * checkout's real path (a sibling of the test rig's, which CI's servers do not use), and are
 * written under an exclusive lock because the web server has several workers.
 */
final class SinkController
{
    private const NAME = '[a-z0-9-]{1,40}';

    #[Route('/_test/sink/{name}', name: 'idno_test_sink', requirements: ['name' => self::NAME])]
    public function record(Request $request, string $name): Response
    {
        $line = json_encode(RequestDescription::of($request), JSON_THROW_ON_ERROR) . "\n";
        file_put_contents(self::file($name), $line, FILE_APPEND | LOCK_EX);

        return new Response('', 202, ['Cache-Control' => 'no-store']);
    }

    #[Route('/_test/sink/{name}/log', name: 'idno_test_sink_log', requirements: ['name' => self::NAME])]
    public function log(string $name): JsonResponse
    {
        $requests = [];
        $handle = fopen(self::file($name), 'c+');
        if ($handle !== false) {
            if (flock($handle, LOCK_EX)) {
                $contents = (string) stream_get_contents($handle);
                ftruncate($handle, 0);
                flock($handle, LOCK_UN);
                foreach (explode("\n", $contents) as $line) {
                    if ($line !== '') {
                        $requests[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    }
                }
            }
            fclose($handle);
        }

        return new JsonResponse($requests, 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * The sink's file for a name that route requirements have already limited to [a-z0-9-]{1,40}.
     */
    private static function file(string $name): string
    {
        $directory = rtrim(sys_get_temp_dir(), '/') . '/idno-test-sink-' . substr(hash('sha256', (string) realpath(dirname(__DIR__, 2))), 0, 12);
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        return $directory . '/' . $name . '.jsonl';
    }
}
