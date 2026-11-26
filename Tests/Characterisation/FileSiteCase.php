<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * The site the stored files' recordings are made on: `PermalinkSiteCase`'s (Ada, Bob and Carol, and Bob's note in each state a viewer's rights differ
 * by) and, beside it, files with fixed ids: a public image, a PDF, an HTML file (which is sent as a download), a file attached to each of Bob's notes
 * (public, members only, private to Bob, draft), an archive carrying the `restricted` mark, one carrying the mark an earlier build used (`site_export`),
 * and an archive that carries no mark but is the one the settings record as the export (`export_file_id`, written in capital letters: the comparison
 * ignores letter case). Every file holds 300 bytes (the letters A to Z in turn), so that the bytes of a range can be told.
 *
 * The viewers are `visitor` (nobody), `member` (Carol), `owner` (Bob) and `admin` (Ada).
 */
abstract class FileSiteCase extends PermalinkSiteCase
{
    /** The size of every file the site holds. */
    protected const SIZE = 300;

    /** Why a download is not named as it was: the legacy page took the id a second time for the name */
    protected const WHY_DOWNLOAD = 'a download is named after the last segment of its address, where the legacy page named every one after the file\'s id';

    /** Why a refused range differs: it says it has no body */
    protected const WHY_REFUSED = 'a refused range says its answer has no body (Content-Length: 0)';

    /** Why a request the route does not take differs: the legacy 404 page, as every moved page answers it */
    protected const WHY_NOT_SERVED = 'the route does not take this request (another method, a malformed Host, a spelling it does not match): the legacy router answers the 404 page, as for every page that moved to a controller';

    /** Why an XHR request differs: the headers of the same request that is not XHR */
    protected const WHY_XHR = 'an XHR GET gets the headers of the same request that is not XHR';

    /** Why a conditional request differs: the comparison was the wrong way round */
    protected const WHY_CONDITIONAL = 'a copy is current when its date is not before the file\'s last change: the legacy comparison was the wrong way round';

    /** @var array<string, string> the id of each seeded file by name: the files of a site, by class and address */
    private static array $files = [];

    /**
     * The ids of the seeded files, by name: `image`, `pdf`, `html`, `attached-public`, `attached-site`, `attached-owner`, `attached-draft`, `restricted`,
     * `site-export`, `recorded`.
     *
     * @return array<string, string>
     */
    protected function seedFiles(): array
    {
        $key = static::class . '|' . $this->baseUrl();
        if (isset(self::$files[$key])) {
            return self::$files[$key];
        }
        $this->seedPermalinkContent();
        $code = <<<'PHP'
        $site = \Idno\Core\Idno::site();
        // (a script has no request to name the site's host, which is the directory a file is stored under: the web server serves the files of its own host)
        $site->config()->host = (string) parse_url($site->config()->getURL(), PHP_URL_HOST);
        $store = \Idno\Core\Idno::container()->get(\Idno\Storage\Files\FileStore::class);
        $records = \Idno\Core\Idno::container()->get(\Idno\Storage\RecordStore::class);
        $bytes = '';
        for ($i = 0; $i < 300; $i++) {
            $bytes .= chr(65 + $i % 26);
        }
        $uuidOf = static fn (string $id): string => (string) $records->findById('entities', $id)['uuid'];
        $files = [
            'image' => ['00000000000040008000000000000f01', ['filename' => 'photo.png', 'mime_type' => 'image/png']],
            'pdf' => ['00000000000040008000000000000f02', ['filename' => 'paper.pdf', 'mime_type' => 'application/pdf']],
            'html' => ['00000000000040008000000000000f03', ['filename' => 'page.html', 'mime_type' => 'text/html']],
            'attached-public' => ['00000000000040008000000000000f04', ['filename' => 'public.png', 'mime_type' => 'image/png', 'attached_entity' => $uuidOf('00000000000040008000000000000041')]],
            'attached-site' => ['00000000000040008000000000000f05', ['filename' => 'members.png', 'mime_type' => 'image/png', 'attached_entity' => $uuidOf('00000000000040008000000000000042')]],
            'attached-owner' => ['00000000000040008000000000000f06', ['filename' => 'private.png', 'mime_type' => 'image/png', 'attached_entity' => $uuidOf('00000000000040008000000000000043')]],
            'attached-draft' => ['00000000000040008000000000000f07', ['filename' => 'draft.png', 'mime_type' => 'image/png', 'attached_entity' => $uuidOf('00000000000040008000000000000044')]],
            'restricted' => ['00000000000040008000000000000f08', ['filename' => 'archive.zip', 'mime_type' => 'application/zip', 'restricted' => 'admin']],
            'site-export' => ['00000000000040008000000000000f09', ['filename' => 'old-archive.zip', 'mime_type' => 'application/zip', 'site_export' => true]],
            'recorded' => ['00000000000040008000000000000f0a', ['filename' => 'recorded.zip', 'mime_type' => 'application/zip']],
        ];
        $ids = [];
        foreach ($files as $name => [$id, $metadata]) {
            $stream = fopen('php://memory', 'w+');
            fwrite($stream, $bytes);
            rewind($stream);
            $store->storeAs($id, $stream, $metadata + ['length' => strlen($bytes)]);
            $ids[$name] = $id;
        }
        $site->config()->export_file_id = strtoupper($ids['recorded']);
        $site->config()->save();

        return $ids;
        PHP;
        $ids = $this->runInSite($code);
        $this->assertIsArray($ids);

        return self::$files[$key] = $ids;
    }

    /** The bytes every file holds: the letters A to Z in turn. */
    protected static function bytes(): string
    {
        $bytes = '';
        for ($i = 0; $i < self::SIZE; $i++) {
            $bytes .= chr(65 + $i % 26);
        }

        return $bytes;
    }

    /** Files carry no change time of their own, so `Last-Modified` is the time of the request: no recording may hold it. */
    protected function seedsOwnedPosts(): bool
    {
        return false;
    }

    /**
     * The answer with a `Last-Modified` that is the time of the request (within ten seconds of its `Date`) replaced by a marker, so that it can be
     * recorded; a `Last-Modified` that is anything else is kept as it was sent.
     */
    protected static function withTheRequestTimeMarked(SiteResponse $r): SiteResponse
    {
        $date = strtotime((string) $r->header('Date'));
        $mark = static function (string $value) use ($date): string {
            $at = strtotime($value);

            return $date !== false && $at !== false && abs($at - $date) <= 10 ? '<the time of the request>' : $value;
        };
        $headers = [];
        foreach ($r->headers as $name => $value) {
            $headers[$name] = strcasecmp((string) $name, 'last-modified') === 0 ? $mark((string) $value) : $value;
        }
        $lines = [];
        foreach ($r->lines as $line) {
            $lines[] = preg_match('/^last-modified:\s*(.*)$/i', $line, $m) === 1 ? 'Last-Modified: ' . $mark($m[1]) : $line;
        }

        return new SiteResponse($r->status, $headers, $r->body, $lines);
    }

    /**
     * A request whose path is sent exactly as written (the harness's client removes the slashes a path starts with, so it cannot ask for `//file/<id>`).
     *
     * @param array<string, string> $headers
     */
    protected function rawRequest(string $method, string $path, array $headers = []): SiteResponse
    {
        $lines = [];
        $requestHeaders = [];
        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }
        $curl = curl_init(rtrim($this->baseUrl(), '/') . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $requestHeaders, CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$lines): int {
                $lines[] = $line;

                return strlen($line);
            },
        ]);
        $content = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $this->assertNotFalse($content, 'the request was answered');

        return new SiteResponse($status, HttpSiteClient::parseHeaderLines($lines), (string) $content, HttpSiteClient::finalLines($lines));
    }

    /**
     * One recorded request for a file, the time of the request marked in place of `Last-Modified`; a case that differs from its recording on purpose
     * (the class's `KINDS`) is held to what changed.
     *
     * @param array<string, string> $headers
     */
    protected function recordFile(string $case, string $method, string $path, array $headers = [], string $viewer = 'visitor'): SiteResponse
    {
        $answer = $this->record($case, $method, $path, $headers, $viewer, null, self::withTheRequestTimeMarked(...));
        $this->holdTheChangedAnswer($case, $answer, $method, $path, $headers, $viewer);

        return $answer;
    }

    /**
     * A recorded request sent as written (see rawRequest()), the time of the request marked in place of `Last-Modified`.
     *
     * @param array<string, string> $headers
     */
    protected function recordRaw(string $case, string $method, string $path, array $headers = []): SiteResponse
    {
        $response = null;
        foreach (range(1, static::passes()) as $_) {
            $response = $this->rawRequest($method, $path, $headers);
            $this->assertRecordedAnswer(static::GROUP, $case, self::withTheRequestTimeMarked($response));
        }

        return $response;
    }

    /** @param array<string, string> $headers */
    private function holdTheChangedAnswer(string $case, SiteResponse $answer, string $method, string $path, array $headers, string $viewer): void
    {
        $kinds = defined(static::class . '::KINDS') ? constant(static::class . '::KINDS') : [];
        if (!isset($kinds[$case])) {
            return;
        }
        $kind = $kinds[$case];
        if (isset($kind['xhr'])) {
            // the headers of the same request that is not XHR, and the body the legacy page gave
            $plain = $this->site()->request($method, $path, $this->asViewer($viewer, array_diff_key($headers, ['X-Requested-With' => true])));
            [$answeredHeaders, $answeredBody] = explode("\n\n", self::normalise(self::withoutTheRequestTime(self::withTheRequestTimeMarked($answer)), $this->baseUrl()), 2);
            [$plainHeaders] = explode("\n\n", self::normalise(self::withoutTheRequestTime(self::withTheRequestTimeMarked($plain)), $this->baseUrl()), 2);
            $this->assertSame($plainHeaders, $answeredHeaders, $case . ': the headers of the same request that is not XHR');
            $recorded = (string) file_get_contents(self::recordedAnswersDirectory() . '/' . static::GROUP . '/' . $case . '.txt');
            $recordedBody = explode("\n\n", $recorded, 2)[1];
            $this->assertSame($recordedBody, $answeredBody, $case . ': the body is what it was');
            $this->assertCount(1, $answer->lines('cache-control'), $case . ': one Cache-Control line');

            return;
        }
        $this->assertTheAnswerThatChanged($case, $answer, $kind);
    }

    private static function withoutTheRequestTime(SiteResponse $r): SiteResponse
    {
        return new SiteResponse(
            $r->status,
            array_filter($r->headers, static fn ($name): bool => strcasecmp((string) $name, 'last-modified') !== 0, ARRAY_FILTER_USE_KEY),
            $r->body,
            array_values(array_filter($r->lines, static fn (string $line): bool => !str_starts_with(strtolower($line), 'last-modified:')))
        );
    }

    /**
     * Holds the answer of a case that differs from its recording ON PURPOSE to what changed and nothing else, by the case's kind (a class's `KINDS`, case =>
     * one of `name` (the download's name), `refused` (a 416 says it has no body), `page` (the 404 page of the legacy router), `status` (the status that
     * the corrected comparison gives, with the `bytes` it holds (`true` is the whole file) and the `download` name it is given), `xhr` (the headers of the same request that is not XHR)).
     *
     * @param array<string, mixed> $kind
     */
    protected function assertTheAnswerThatChanged(string $case, SiteResponse $answer, array $kind, string $nonXhrPath = ''): void
    {
        $marked = self::withTheRequestTimeMarked($answer);
        $recorded = (string) file_get_contents(self::recordedAnswersDirectory() . '/' . static::GROUP . '/' . $case . '.txt');
        if (isset($kind['name']) || isset($kind['refused'])) {
            if (isset($kind['name'])) {
                $recorded = (string) preg_replace('/^(content-disposition: attachment; filename=")[0-9a-f]{32}(")$/m', '${1}' . $kind['name'] . '${2}', $recorded);
            }
            if (isset($kind['refused'])) {
                $recorded = self::withHeader($recorded, 'content-length', '<the length of the body>');
            }
            $this->assertSame($recorded, self::normalise($marked, $this->baseUrl()), $case . ': the recording, but for ' . implode(' and ', array_keys($kind)));
            if (isset($kind['refused'])) {
                $this->assertSame('', $answer->body, $case . ': no body');
            }
        }
        if (isset($kind['page'])) {
            $this->assertSame($kind['page'], $answer->status, $case);
            if (empty($kind['head'])) {
                $this->assertStringContainsString('This page can&#039;t be found.', $answer->body, $case . ': the site\'s 404 page');
            }
            $this->assertNull($answer->header('Content-Disposition'), $case);
        }
        if (isset($kind['status'])) {
            $this->assertSame($kind['status'], $answer->status, $case);
            if ($kind['status'] === 304) {
                $this->assertSame('', $answer->body, $case . ': no body');
                $this->assertNull($answer->header('Last-Modified'), $case . ': a 304 says nothing of the file');
                $this->assertNull($answer->header('Content-Length'), $case);
                $this->assertNull($answer->header('Content-Range'), $case);
            }
            if (isset($kind['bytes'])) {
                $this->assertSame($kind['bytes'] === true ? self::bytes() : $kind['bytes'], $answer->body, $case);
            }
            if (isset($kind['download'])) {
                $this->assertSame('attachment; filename="' . $kind['download'] . '"', $answer->header('Content-Disposition'), $case);
            }
        }
    }

    /** The recording with a header added (in the order the recording keeps them: by name). */
    protected static function withHeader(string $recording, string $name, string $value): string
    {
        [$head, $body] = explode("\n\n", $recording, 2);
        $lines = explode("\n", $head);
        $status = array_shift($lines);
        $values = [];
        foreach ($lines as $line) {
            [$n, $v] = explode(': ', $line, 2);
            $values[$n][] = $v;
        }
        $values[$name] = [$value];
        ksort($values);
        $out = $status . "\n";
        foreach ($values as $n => $list) {
            foreach ($list as $v) {
                $out .= $n . ': ' . $v . "\n";
            }
        }

        return $out . "\n" . $body;
    }

    /** The address of a seeded file: `/file/<id>` and the rest. */
    protected function fileAddress(string $name, string $rest = ''): string
    {
        return '/file/' . $this->seedFiles()[$name] . $rest;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (array_keys(self::$files) as $key) {
            if (str_starts_with($key, static::class . '|')) {
                unset(self::$files[$key]);
            }
        }
        parent::tearDownAfterClass();
    }
}
