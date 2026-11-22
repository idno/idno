<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Queue\AsyncSiteTestCase;

/**
 * What the import page does with a request that has no usable file, on a site as production runs it: a site of
 * its own (as the queue tests have) with pedantic_mode off and the asynchronous queue, so that the page queues
 * the import for the worker. (The rig's own site runs with pedantic_mode, which turns a PHP warning into an
 * exception that a page may catch where production would not.)
 *
 * Five requests are made: no file, an empty one, a source the page does not handle ("Known", which the page
 * used to offer and never read) and no source start nothing, and say why; a file that is not XML is queued,
 * and when the worker has run the admin is mailed that it failed, nothing is created, and no error escapes
 * as a critical line in the log.
 */
final class AdminImportWithoutPedanticModeTest extends AsyncSiteTestCase
{
    protected function siteEnvironment(): array
    {
        return ['IDNO_PEDANTIC_MODE' => '0'];
    }

    /**
     * Makes the requests, runs the worker and reads what happened.
     *
     * @return array{mails: list<array<string, mixed>>, log: string, refused: array<string, string>, queuedBefore: list<string>, queuedAfter: list<string>, failedAfter: list<string>}
     */
    private function scenario(): array
    {
        $cookie = $this->registerUser('alice'); // the first user is the administrator
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->from_email = "site@example.com"; $config->save(); return true;');
        $this->mailLog();

        $jobs = [
            'no file' => ['WordPress', null],
            'empty file' => ['WordPress', ['empty.xml', '']],
            'Known' => ['Known', ['export.rss', '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>']],
            'no source' => ['', ['export.xml', '<rss/>']],
            'not XML' => ['WordPress', ['notes.txt', 'These are my notes, not an export.']],
        ];
        $refused = [];
        foreach ($jobs as $name => [$type, $file]) {
            $page = $this->site()->request('GET', 'admin/import/', ['Cookie' => $cookie]);
            $this->assertSame(200, $page->status, $name . $this->serverLog());
            $fields = $this->formToken($page->body, 'admin/import/') + ($type === '' ? [] : ['import_type' => $type]);
            [$boundary, $body] = $this->multipart($fields, $file);
            $response = $this->site()->request('POST', 'admin/import/', ['Cookie' => $cookie, 'Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body);
            $this->assertSame(302, $response->status, $name . ': ' . substr($response->body, 0, 500) . $this->serverLog());
            $this->assertSame($this->baseUrl() . 'admin/import/', $response->header('Location'), $name);
            $refused[$name] = html_entity_decode($this->site()->request('GET', 'admin/import/', ['Cookie' => $cookie])->body, ENT_QUOTES);
        }
        $before = array_column($this->queuedEvents(), 'event');
        $this->assertSame([], $this->mailLog(), 'the page sends no mail: the worker does the work');

        $worker = $this->runWorkerOnce();
        $this->assertSame(0, $worker['exit'], $worker['output']);

        return [
            'mails' => $this->mailLog(),
            'log' => $this->serverLogContents() . $worker['output'],
            'refused' => $refused,
            'queuedBefore' => $before,
            'queuedAfter' => array_column($this->queuedEvents(), 'event'),
            'failedAfter' => array_column($this->failedEvents(), 'event'),
        ];
    }

    /**
     * @return list<array<string, mixed>> the mail the site has sent since the last call (/_test/mail/log: the web server
     *                                    keeps its record where its own temporary directory is)
     */
    private function mailLog(): array
    {
        $response = $this->site()->request('GET', '_test/mail/log');
        $this->assertSame(200, $response->status, $response->body);

        return $response->json();
    }

    public function testARequestWithNoUsableFileOrSourceStartsNothingAndSaysWhy(): void
    {
        $result = $this->scenario();

        $this->assertStringContainsString('You need to upload an import file to continue.', $result['refused']['no file']);
        $this->assertStringContainsString("We couldn't open the file you uploaded.", $result['refused']['empty file']);
        $this->assertStringContainsString('Choose where your file comes from', $result['refused']['Known']);
        $this->assertStringContainsString('Choose where your file comes from', $result['refused']['no source']);
        $this->assertSame(['portability/import'], $result['queuedBefore'], 'only the file that is not XML is queued');
    }

    public function testABadImportMailsTheAdminThatItFailed(): void
    {
        $result = $this->scenario();

        $this->assertCount(1, $result['mails'], json_encode(array_column($result['mails'], 'subject')));
        $this->assertSame('Idno - Problem with your import', $result['mails'][0]['subject']);
        $this->assertSame('alice@example.com', $result['mails'][0]['to'][0]['address']);
        $this->assertStringNotContainsString('critical - ', $result['log']);
        $this->assertStringNotContainsString('Undefined variable', $result['log']);
        $this->assertStringNotContainsString('TypeError', $result['log']);
        $this->assertSame([], $result['queuedAfter'], 'the event was handled');
        $this->assertSame([], $result['failedAfter'], 'and did not end in the failure transport: the job reports its failure by mail');
    }

    /**
     * @param array<string, string>        $fields
     * @param array{0: string, 1: string}|null $file name and contents of the "import" file
     *
     * @return array{0: string, 1: string}
     */
    private function multipart(array $fields, ?array $file): array
    {
        $boundary = 'idno' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        if ($file !== null) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"import\"; filename=\"{$file[0]}\"\r\nContent-Type: text/xml\r\n\r\n{$file[1]}\r\n";
        }

        return [$boundary, $body . "--$boundary--\r\n"];
    }
}
