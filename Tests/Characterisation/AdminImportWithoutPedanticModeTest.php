<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Queue\AsyncSiteTestCase;

/**
 * What the import page does with a request that has no usable file, on a site as production runs it. The
 * rig's own site runs with pedantic_mode, which turns the PHP warning of an undefined variable into an
 * exception that the page catches; production does not, and the same request ends in a TypeError, which
 * the page does not catch. So this is a site of its own (as the queue tests have) with pedantic_mode off.
 *
 * The page answers at once, then waits ten seconds and imports (Pages\Admin\Import). Four requests are made
 * together, on four workers, and the test waits for what they do: three have no usable file (none, an
 * empty one, one that is not XML) and a fourth asks for the import type "Known", which the page has no case
 * for; that one ends in the failure mail, which is the proof that mail works on this site, so that the
 * absence of a mail for the others means something.
 */
final class AdminImportWithoutPedanticModeTest extends AsyncSiteTestCase
{
    protected function siteEnvironment(): array
    {
        return ['IDNO_PEDANTIC_MODE' => '0'];
    }

    /**
     * Makes the four requests and waits for what they do.
     *
     * @return array{mails: list<array<string, mixed>>, log: string}
     */
    private function scenario(): array
    {
        $cookie = $this->registerUser('alice'); // the first user is the administrator
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->from_email = "site@example.com"; $config->save(); return true;');
        $this->mailLog();

        $jobs = [
            'no file' => ['WordPress', null],
            'empty file' => ['WordPress', ['empty.xml', '']],
            'not XML' => ['WordPress', ['notes.txt', 'These are my notes, not an export.']],
            'Known' => ['Known', ['export.rss', '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>']],
        ];
        foreach ($jobs as $name => [$type, $file]) {
            $page = $this->site()->request('GET', 'admin/import/', ['Cookie' => $cookie]);
            $this->assertSame(200, $page->status, $name . $this->serverLog());
            [$boundary, $body] = $this->multipart($this->formToken($page->body, 'admin/import/') + ['import_type' => $type], $file);
            $response = $this->site()->request('POST', 'admin/import/', ['Cookie' => $cookie, 'Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body);
            $this->assertSame(302, $response->status, $name . ': ' . substr($response->body, 0, 500) . $this->serverLog());
            $this->assertSame($this->baseUrl() . 'admin/import/', $response->header('Location'), $name);
        }

        // Ten seconds of waiting in each of the four workers, then the work. A request for the mail record
        // is answered when a worker is free again, which is after that.
        $mails = [];
        $deadline = microtime(true) + 45;
        while ($mails === [] && microtime(true) < $deadline) {
            $mails = $this->mailLog();
            usleep(250_000);
        }
        sleep(2); // the others have had their time too
        $mails = [...$mails, ...$this->mailLog()];

        return ['mails' => $mails, 'log' => $this->serverLogContents()];
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

    public function testTodayABadImportEndsInAnUncaughtTypeErrorAndNoMail(): void
    {
        // The import page runs its import inside try/catch (\Exception) (Pages\Admin\Import). What a missing,
        // empty or unreadable file does is a TypeError (an \Error): in the import ("Return value must be of type
        // bool, none returned") or at the call (a missing file leaves $xml undefined, so null is passed). It
        // reaches the legacy exception handler, which logs it as critical, and the admin, who was told "We'll
        // email you when it's done", is never mailed. The next change must flip this to the test below.
        $result = $this->scenario();

        $this->assertStringContainsString('PHP [2] Undefined variable $xml in ' . dirname(__DIR__, 2) . '/Idno/Pages/Admin/Import.php:69', $result['log'], 'no file');
        $this->assertStringContainsString('critical - Idno\Core\Migration::importWordPressXML(): Argument #1 ($xml) must be of type string, null given', $result['log'], 'no file');
        $this->assertSame(2, substr_count($result['log'], 'critical - Idno\Core\Migration::importWordPressXML(): Return value must be of type bool, none returned'), 'an empty file, and a file that is not XML');
        $this->assertSame(3, substr_count($result['log'], 'critical - '), 'one uncaught error for each of the three');

        // The fourth, the type "Known", is answered the way a failed import is: one mail. The other three sent none.
        $this->assertSame(1, substr_count($result['log'], 'Import completed, but may not have been successful'));
        $this->assertCount(1, $result['mails'], json_encode(array_column($result['mails'], 'subject')));
        $this->assertSame('Idno - Problem with your import', $result['mails'][0]['subject']);
        $this->assertSame('alice@example.com', $result['mails'][0]['to'][0]['address']);
    }

    public function testABadImportMailsTheAdminThatItFailed(): void
    {
        $this->markTestIncomplete('The importers throw a TypeError when nothing is imported (Migration.php:296, 437, 447) and the page catches only \Exception (Pages\Admin\Import.php:73): no failure mail is sent for a missing, empty or unreadable file, only for an import type the page has no case for');

        $result = $this->scenario();

        $this->assertCount(4, $result['mails'], 'every request that does not import ends in the failure mail');
        $this->assertStringNotContainsString('critical - ', $result['log']);
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
