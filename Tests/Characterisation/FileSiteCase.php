<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Http\Page\HttpDate;

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
    /**
     * The cases a class answers differently from their recordings on purpose, by kind (see holdTheChangedAnswer()). Declared here, `protected`, so that a
     * subclass declaring it `private` is a fatal error: a private copy was once invisible to the helper and none of the cases was ever held to its kind.
     *
     * @var array<string, array<string, mixed>>
     */
    protected const KINDS = [];

    /** The size of every file the site holds. */
    protected const SIZE = 300;

    /** When every seeded file was last written (2026-03-20 12:00:00 UTC), which is its `Last-Modified` */
    protected const WRITTEN = 1773921600;

    /** Why a download is not named as it was: the legacy page took the id a second time for the name */
    protected const WHY_DOWNLOAD = 'a download is named after the last segment of its address, where the legacy page named every one after the file\'s id';

    /** Why a refused range differs: it says it has no body */
    protected const WHY_REFUSED = 'a refused range says its answer has no body (Content-Length: 0)';

    /** Why a request the route does not take differs: the legacy 404 page, as every moved page answers it */
    protected const WHY_NOT_SERVED = 'the route does not take this request (another method, a malformed Host, a spelling it does not match): the legacy router answers the 404 page, as for every page that moved to a controller';

    /** Why an XHR request differs: the headers of the same request that is not XHR */
    protected const WHY_XHR = 'an XHR GET gets the headers of the same request that is not XHR';

    /** Why a conditional request differs: a file is dated by its own modification time and a 304 carries its caching lines */
    protected const WHY_CONDITIONAL = 'a file is dated by its own modification time (the legacy page dated it with the time of the request and compared the wrong way round): a copy is current when its date is not before that, and the 304 carries the file\'s caching lines and date';

    /** Why a file of a post the viewer may not read differs: it is a missing file */
    protected const WHY_HIDDEN = 'a file attached to a post the viewer may not read answers exactly what a file that is not there answers (it was a 403)';

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
        // (the administrator's API key is made here: a signed request of hers is asked for in a test)
        \Idno\Entities\User::getByHandle('ada')->getAPIkey();
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
        // (every file is dated by its own modification time: a fixed one, so that its Last-Modified is the same in every run)
        foreach ($ids as $id) {
            touch(rtrim((string) $site->config()->uploadpath, '/') . '/' . ltrim($store->path($id), '/'), 1773921600);
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
     * The answer with its `Last-Modified` replaced by a marker, so that it can be compared with a recording: the legacy page sent the time of the request
     * (a stored file had no change time it could see) and the page that replaced it sends the file's own modification time (`WRITTEN`); the marker stands for
     * both. A `Last-Modified` that is neither is kept as it was sent.
     */
    protected static function withTheRequestTimeMarked(SiteResponse $r): SiteResponse
    {
        $date = strtotime((string) $r->header('Date'));
        $mark = static function (string $value) use ($date): string {
            $at = strtotime($value);

            return $at === self::WRITTEN || ($date !== false && $at !== false && abs($at - $date) <= 10) ? '<the time of the request>' : $value;
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
     * The answer as it is recorded: its `Last-Modified` marked (see withTheRequestTimeMarked()), and the `Content-Security-Policy: sandbox` that every answer
     * that sends a file (200, 206 or 416) but a PDF carries asserted and taken off, so that the recordings, which are the legacy page's, hold what they held.
     */
    protected static function forTheRecording(SiteResponse $r): SiteResponse
    {
        $r = self::withTheRequestTimeMarked($r);
        $sendsAFile = in_array($r->status, [200, 206, 416], true) && $r->header('X-Content-Type-Options') === 'nosniff';
        if ($sendsAFile && $r->header('Content-Type') !== 'application/pdf') {
            \PHPUnit\Framework\Assert::assertSame('sandbox', $r->header('Content-Security-Policy'), 'a file is sent in a sandbox');
        }
        $strip = static fn (string $line): bool => !str_starts_with(strtolower($line), 'content-security-policy:');

        return new SiteResponse(
            $r->status,
            array_filter($r->headers, static fn ($name): bool => strcasecmp((string) $name, 'content-security-policy') !== 0, ARRAY_FILTER_USE_KEY),
            $r->body,
            array_values(array_filter($r->lines, $strip))
        );
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
        $answer = $this->record($case, $method, $path, $headers, $viewer, null, self::forTheRecording(...));
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
            $this->assertRecordedAnswer(static::GROUP, $case, self::forTheRecording($response));
        }

        return $response;
    }

    /** @param array<string, string> $headers */
    private function holdTheChangedAnswer(string $case, SiteResponse $answer, string $method, string $path, array $headers, string $viewer): void
    {
        $kinds = static::KINDS;
        if (!isset($kinds[$case])) {
            return;
        }
        $kind = $kinds[$case];
        if (isset($kind['xhr'])) {
            // the headers of the same request that is not XHR, and the body the legacy page gave
            $plain = $this->site()->request($method, $path, $this->asViewer($viewer, array_diff_key($headers, ['X-Requested-With' => true])));
            [$answeredHeaders, $answeredBody] = explode("\n\n", static::normalise(self::withoutTheRequestTime(self::forTheRecording($answer)), $this->baseUrl()), 2);
            [$plainHeaders] = explode("\n\n", static::normalise(self::withoutTheRequestTime(self::forTheRecording($plain)), $this->baseUrl()), 2);
            $this->assertSame($plainHeaders, $answeredHeaders, $case . ': the headers of the same request that is not XHR');
            $recorded = (string) file_get_contents(static::recordedAnswersDirectory() . '/' . static::GROUP . '/' . $case . '.txt');
            $recordedBody = explode("\n\n", $recorded, 2)[1];
            $this->assertSame($recordedBody, $answeredBody, $case . ': the body is what it was');
            $this->assertCount(1, $answer->lines('cache-control'), $case . ': one Cache-Control line');

            return;
        }
        if (isset($kind['hidden'])) {
            // a file of a post the viewer may not read answers exactly what a file that is not there answers, for the same request
            $this->assertSame(1, preg_match('/[0-9a-fA-F]{32}/', $path, $id), $case);
            $zeros = str_repeat('0', 32);
            $missing = $this->site()->request($method, str_replace($id[0], $zeros, $path), $this->asViewer($viewer, $headers));
            $this->assertSame(404, $answer->status, $case);
            $this->assertSame(
                static::normalise(self::forTheRecording($missing), $this->baseUrl()),
                str_replace($id[0], $zeros, static::normalise(self::forTheRecording($answer), $this->baseUrl())),
                $case . ': exactly the answer of a missing file'
            );

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
     * the corrected comparison gives, with the `bytes` it holds (`true` is the whole file), the `download` name it is given and, for a 304, the `caching`
     * profile its lines are), `like` (the recording of another case), `hidden` (the answer of a missing file), `xhr` (the headers of the same request that is not XHR)).
     *
     * @param array<string, mixed> $kind
     */
    protected function assertTheAnswerThatChanged(string $case, SiteResponse $answer, array $kind, string $nonXhrPath = ''): void
    {
        $marked = self::forTheRecording($answer);
        $recorded = (string) file_get_contents(static::recordedAnswersDirectory() . '/' . static::GROUP . '/' . $case . '.txt');
        if (isset($kind['name']) || isset($kind['refused'])) {
            if (isset($kind['name'])) {
                // (the legacy page named a download after the id as the address wrote it, upper case included: a recording may hold either case)
                $recorded = (string) preg_replace('/^(content-disposition: attachment; filename=")[0-9a-fA-F]{32}(")$/m', '${1}' . $kind['name'] . '${2}', $recorded);
            }
            if (isset($kind['refused'])) {
                $recorded = self::withHeader($recorded, 'content-length', '<the length of the body>');
            }
            $this->assertSame($recorded, static::normalise($marked, $this->baseUrl()), $case . ': the recording, but for ' . implode(' and ', array_keys($kind)));
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
        if (isset($kind['like'])) {
            $sibling = (string) file_get_contents(static::recordedAnswersDirectory() . '/' . static::GROUP . '/' . $kind['like'] . '.txt');
            $this->assertSame($sibling, static::normalise($marked, $this->baseUrl()), $case . ': the recording of ' . $kind['like']);
        }
        if (isset($kind['similar'])) {
            // the recording of another case, but for the address and the name the download is given
            $strip = static fn (string $text): string => (string) preg_replace('/^(link: [^\n]*rel="self"|content-disposition: [^\n]*)\n/m', '', $text);
            $sibling = (string) file_get_contents(static::recordedAnswersDirectory() . '/' . static::GROUP . '/' . $kind['similar'] . '.txt');
            $this->assertSame($strip($sibling), $strip(static::normalise($marked, $this->baseUrl())), $case . ': like ' . $kind['similar']);
        }
        if (isset($kind['status'])) {
            $this->assertSame($kind['status'], $answer->status, $case);
            if ($kind['status'] === 304) {
                $this->assertSame('', $answer->body, $case . ': no body');
                $this->assertSame(HttpDate::format(self::WRITTEN), $answer->header('Last-Modified'), $case . ': a 304 carries the file\'s date');
                $this->assertNull($answer->header('Content-Length'), $case);
                $this->assertNull($answer->header('Content-Range'), $case);
                $caching = $kind['caching'];
                $this->assertSame(match ($caching) {
                    'public' => 'public', 'restricted' => 'private, no-cache', default => 'private, no-store',
                }, $answer->header('Cache-Control'), $case . ': the caching lines of its profile');
                $this->assertSame(match ($caching) {
                    'public' => 'public', 'restricted' => 'no-cache', default => 'no-cache',
                }, $answer->header('Pragma'), $case);
                $this->assertSame(1, count($answer->lines('cache-control')), $case);
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
