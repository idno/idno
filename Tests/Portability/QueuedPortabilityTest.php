<?php

declare(strict_types=1);

namespace Tests\Portability;

use Tests\Queue\AsyncSiteTestCase;

/**
 * The import and export pages on a site with the asynchronous queue (a private site of the test's own): the
 * page queues the job and answers, the worker does the work as the administrator who asked, and ends with the
 * mail; nothing an import brings in is announced to the followers; an export that fails mails the failure and
 * frees the flag. (Without the asynchronous queue the pages do the work after their answer:
 * AdminPortabilityPagesTest.)
 */
final class QueuedPortabilityTest extends AsyncSiteTestCase
{
    private string $cookie = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cookie = $this->registerUser('alice'); // the first user is the administrator
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->from_email = "site@example.com"; $config->save(); return true;');
        $this->mailLog();
    }

    /**
     * @return list<array<string, mixed>> the mail the site has sent since the last call
     */
    private function mailLog(): array
    {
        $response = $this->site()->request('GET', '_test/mail/log');
        $this->assertSame(200, $response->status, $response->body);

        return $response->json();
    }

    /**
     * @param array{0: string, 1: string} $file
     */
    private function postImport(string $type, array $file): \Tests\Characterisation\SiteResponse
    {
        $page = $this->site()->request('GET', 'admin/import/', ['Cookie' => $this->cookie]);
        $boundary = 'idno' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($this->formToken($page->body, 'admin/import/') + ['import_type' => $type] as $name => $value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"import\"; filename=\"{$file[0]}\"\r\nContent-Type: text/xml\r\n\r\n{$file[1]}\r\n--$boundary--\r\n";

        return $this->site()->request('POST', 'admin/import/', ['Cookie' => $this->cookie, 'Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body);
    }

    private function postGenerate(): \Tests\Characterisation\SiteResponse
    {
        // The export page has no form while an export runs: the token is taken from the import page's.
        $page = $this->site()->request('GET', 'admin/import/', ['Cookie' => $this->cookie]);

        return $this->site()->request('POST', 'admin/export/generate/', ['Cookie' => $this->cookie], $this->formToken($page->body, 'admin/import/'));
    }

    /**
     * @return list<string> the titles of the public articles of the site
     */
    private function titles(): array
    {
        $titles = $this->runInSite('$titles = array_map(static fn ($entry) => (string) $entry->title, \IdnoPlugins\Text\Entry::get([], [], 100)); sort($titles); return $titles;');
        $this->assertIsArray($titles);

        return $titles;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    public function testWithAnAsynchronousQueueThePageEnqueuesAndTheWorkerDoesTheWork(): void
    {
        $alice = $this->runInSite('return \Idno\Entities\User::getByHandle("alice")->getUUID();');

        $response = $this->postImport('WordPress', ['export.xml', $this->fixture('wordpress-export.xml')]);

        $this->assertSame(302, $response->status);
        $this->assertSame($this->baseUrl() . 'admin/import/', $response->header('Location'));
        $events = $this->queuedEvents();
        $this->assertSame(['portability/import'], array_column($events, 'event'));
        $this->assertSame($alice, $events[0]['runAsContext'], 'it runs as the administrator who asked');
        $this->assertSame($alice, $events[0]['data']['user']);
        $this->assertSame('wordpress', $events[0]['data']['type']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $events[0]['data']['file'], 'the id of the stored upload');
        $this->assertSame([], $this->titles(), 'nothing is imported by the request');
        $this->assertSame([], $this->mailLog(), 'and no mail is sent by it');

        $worker = $this->runWorkerOnce();

        $this->assertSame(0, $worker['exit'], $worker['output']);
        $this->assertSame(['Low tide, high hopes', 'Notes from the tide pool — café edition 🌊'], $this->titles());
        $mails = $this->mailLog();
        $this->assertCount(1, $mails);
        $this->assertStringStartsWith('Your import into', $mails[0]['subject']);
        $this->assertSame('alice@example.com', $mails[0]['to'][0]['address']);
        $this->assertSame([], $this->queuedEvents());
        $this->assertSame([], $this->failedEvents());
        $this->assertFalse($this->site()->request('GET', 'file/' . $events[0]['data']['file'] . '/export.xml', ['Cookie' => $this->cookie])->status === 200, 'the upload is gone');
    }

    public function testAnImportThatFailsInTheWorkerMailsTheFailure(): void
    {
        $this->postImport('WordPress', ['notes.txt', 'These are my notes, not an export.']);

        $worker = $this->runWorkerOnce();

        $this->assertSame(0, $worker['exit'], $worker['output']);
        $mails = $this->mailLog();
        $this->assertCount(1, $mails);
        $this->assertStringEndsWith('did not finish', $mails[0]['subject']);
        $this->assertSame([], $this->titles());
        $this->assertSame([], $this->failedEvents(), 'the job reports its failure by mail and does not park the event');
    }

    public function testAnImportNeverAnnouncesWhatItImportsToTheFollowers(): void
    {
        $inbox = $this->newSink('followers');
        $this->addFollower('alice', $this->baseUrl() . '_test/sink/' . $inbox);

        $this->postImport('WordPress', ['export.xml', $this->fixture('wordpress-export.xml')]);
        $worker = $this->runWorkerOnce();

        $this->assertSame(0, $worker['exit'], $worker['output']);
        $this->assertSame(['Low tide, high hopes', 'Notes from the tide pool — café edition 🌊'], $this->titles(), 'the posts are imported');
        $this->assertSame([], $this->sink($inbox), 'nothing is delivered to the follower');
        $this->assertSame([], $this->queuedEvents(), 'and nothing is left queued for delivery');
        $this->assertSame([], $this->failedEvents());

        // Posting by hand is announced as before: the worker's process went back to normal when the import ended.
        $this->publishStatus($this->cookie, 'A post written after the import');
        $this->assertSame(0, $this->runWorkerOnce()['exit']);
        $this->assertCount(1, $this->sink($inbox), 'a new post is federated');
    }

    public function testAnExportIsQueuedOnceAndTheWorkerMakesAnArchiveOnlyAdministratorsCanFetch(): void
    {
        $response = $this->postGenerate();

        $this->assertSame(302, $response->status);
        $this->assertSame(['portability/export'], array_column($this->queuedEvents(), 'event'));
        $this->assertStringContainsString('is being created', $this->site()->request('GET', 'admin/export/', ['Cookie' => $this->cookie])->body, 'in progress');

        $second = $this->postGenerate();
        $this->assertSame(302, $second->status);
        $this->assertCount(1, $this->queuedEvents(), 'a second export is refused while one is queued');
        $this->assertStringContainsString('An export is already being made', $this->site()->request('GET', 'admin/export/', ['Cookie' => $this->cookie])->body);

        $worker = $this->runWorkerOnce();

        $this->assertSame(0, $worker['exit'], $worker['output']);
        $mails = $this->mailLog();
        $this->assertCount(1, $mails);
        $this->assertStringStartsWith('Your export of', $mails[0]['subject']);
        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $this->cookie]);
        $this->assertSame(1, preg_match('~file/([0-9a-f]{32})/([^"]+\.zip)"~', $page->body, $match), 'the page links to the archive');
        $archive = 'file/' . $match[1] . '/' . $match[2];
        $this->assertSame(200, $this->site()->request('GET', $archive, ['Cookie' => $this->cookie])->status);
        $this->assertSame(403, $this->site()->request('GET', $archive)->status, 'not to a visitor');
        $this->assertSame([], $this->failedEvents());
    }

    public function testAnExportThatFailsInTheWorkerMailsTheFailureAndFreesTheFlag(): void
    {
        $this->postGenerate();

        // The worker's upload directory cannot be made: the archive cannot be stored.
        $worker = $this->runWorkerOnce(['IDNO_UPLOADPATH' => '/nonexistent-idno-directory/uploads/']);

        $this->assertSame(0, $worker['exit'], $worker['output']);
        $mails = $this->mailLog();
        $this->assertCount(1, $mails);
        $this->assertStringStartsWith('Your export of', $mails[0]['subject']);
        $this->assertStringEndsWith('did not finish', $mails[0]['subject']);
        $this->assertSame([], $this->failedEvents());
        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $this->cookie]);
        $this->assertStringNotContainsString('is being created', $page->body, 'the flag is cleared');
        $this->assertStringContainsString('>Create archive</button>', $page->body, 'and another can be asked for');
    }
}
