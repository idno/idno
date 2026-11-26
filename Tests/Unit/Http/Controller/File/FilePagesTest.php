<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\File;

use Idno\Http\Controller\File\FileCaching;
use Idno\Http\Controller\File\FileLookup;
use Idno\Http\Controller\File\FileOutcome;
use Idno\Http\Controller\File\FilePages;
use Idno\Http\Controller\File\ServedFile;
use Idno\Http\Controller\File\StoredFiles;
use Idno\Http\Page\DrawnPage;
use Idno\Http\Page\HttpDate;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageDrawer;
use Idno\Http\Page\PageNotModified;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The file page's one implementation (`FilePages`), on a double of what it asks the site and a real renderer over a double of the drawing: what each kind of
 * file is answered, the headers of the three caching profiles, a download's name, each form of a `Range`, a conditional request, and that a HEAD reads nothing.
 */
final class FilePagesTest extends TestCase
{
    /** The moment the page believes it is */
    private const NOW = 1_800_000_000;

    /** The 26 letters, twice and a half: 65 bytes */
    private const BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLM';

    private const CHANGED = 1_700_000_000;

    private FileStoreDouble $files;

    private FileDrawerDouble $drawer;

    /** @var list<array{0: string, 1: string}> what the page logged: level and message */
    private array $logged = [];

    /** @var list<string> the bytes the page wrote, a piece each */
    private array $written = [];

    private FilePages $pages;

    protected function setUp(): void
    {
        $this->files = new FileStoreDouble();
        $this->drawer = new FileDrawerDouble();
        $translation = new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return $text;
            }
        };
        $logger = new class($this->logged) extends AbstractLogger {
            /** @param list<array{0: string, 1: string}> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logged[] = [(string) $level, (string) $message];
            }
        };
        $this->pages = new FilePages(
            $this->files,
            new PageRenderer($this->drawer, $translation),
            static fn () => $logger,
            static fn (): int => self::NOW,
            function (string $piece): void {
                $this->written[] = $piece;
            }
        );
    }

    /** @param array<string, string> $headers */
    private function context(string $method = 'GET', array $headers = []): PageContext
    {
        $request = Request::create('/file/abc', $method);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return new PageContext($request, new SitePage('Idno\Pages\File\View', publicOnPrivateSite: true));
    }

    private function served(string $mime = 'image/png', FileCaching $caching = FileCaching::Public, bool $passThrough = false, ?string $bytes = null): ServedFile
    {
        $bytes ??= self::BYTES;
        $open = function () use ($bytes) {
            $this->files->opened++;
            $stream = fopen('php://memory', 'w+');
            fwrite($stream, $bytes);
            rewind($stream);

            return $stream;
        };
        $through = $passThrough ? function () use ($bytes): void {
            $this->files->passedThrough++;
            $this->written[] = $bytes;
        } : null;

        return new ServedFile(strlen($bytes), $mime, self::CHANGED, $caching, $open, $through);
    }

    private function serve(ServedFile $file, string $rest = '', string $method = 'GET', array $headers = []): Response
    {
        $this->files->lookup = new FileLookup(FileOutcome::Served, $file);

        return $this->pages->serve($this->context($method, $headers), 'abc', $rest);
    }

    /** The bytes the page writes when its response is sent. */
    private function sent(Response $response): string
    {
        $this->written = [];
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $response->sendContent();

        return implode('', $this->written);
    }

    public function testAFileThatIsNotThereIsTheMissingPage(): void
    {
        $this->files->lookup = new FileLookup(FileOutcome::Missing);

        $response = $this->pages->serve($this->context(), 'abc', '');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['pages/404'], array_column($this->drawer->templates, 0));
        $this->assertFalse($this->files->released);
    }

    public function testAnAdministratorsOnlyFileIsRefusedToAnyoneElseAndNoCacheMayKeepTheRefusal(): void
    {
        $this->files->lookup = new FileLookup(FileOutcome::AdministratorsOnly);
        $page = $this->context();

        $response = $this->pages->serve($page, 'abc', '');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame(['Cache-Control' => 'private, no-store'], $page->caching());
        $this->assertFalse($this->files->released, 'nothing of the file was read');
        $this->assertSame([], $page->headers(), 'nothing that describes the file is sent');
    }

    public function testAFileTheViewerMayNotHaveBecauseOfItsPostIsAMissingFile(): void
    {
        // (the adapter says a file attached to a post the viewer may not read is missing: the page answers it as it answers any missing file)
        $this->files->lookup = new FileLookup(FileOutcome::Missing);
        $page = $this->context();

        $response = $this->pages->serve($page, 'abc', '');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['pages/404'], array_column($this->drawer->templates, 0));
        $this->assertFalse($page->ownsCaching());
        $this->assertSame([], $page->headers());
    }

    public function testAFileWhoseAgeIsUnknownHasNoDateAndAnswersNoConditionalRequest(): void
    {
        $file = new ServedFile(65, 'image/png', null, FileCaching::Public, fn () => $this->served()->open(), null);
        $this->files->lookup = new FileLookup(FileOutcome::Served, $file);
        $page = $this->context('GET', ['If-Modified-Since' => HttpDate::format(self::NOW + 86400)]);

        $response = $this->pages->serve($page, 'abc', '');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('Last-Modified', $page->headers());
        $this->assertNull($response->headers->get('Last-Modified'));
    }

    public function testAShownFileIsSentAsItsCanonicalTypeInASandbox(): void
    {
        foreach (['IMAGE/PNG; x=y' => 'image/png', '  Audio/MPEG' => 'audio/mpeg', 'video/mp4' => 'video/mp4'] as $stored => $sent) {
            $page = $this->context();
            $this->files->lookup = new FileLookup(FileOutcome::Served, $this->served($stored));

            $response = $this->pages->serve($page, 'abc', '');

            $this->assertSame($sent, $response->headers->get('Content-Type'), $stored);
            $this->assertSame(['sandbox'], $page->headers()['Content-Security-Policy'], $stored);
            $this->assertNull($response->headers->get('Content-Disposition'));
        }
    }

    public function testAPdfIsShownWithNoSandboxAndADownloadIsInOne(): void
    {
        $page = $this->context();
        $this->files->lookup = new FileLookup(FileOutcome::Served, $this->served('application/pdf'));
        $this->pages->serve($page, 'abc', '');
        $this->assertArrayNotHasKey('Content-Security-Policy', $page->headers());

        $page = $this->context();
        $this->files->lookup = new FileLookup(FileOutcome::Served, $this->served('text/html'));
        $response = $this->pages->serve($page, 'abc', '/x.html');
        $this->assertSame(['sandbox'], $page->headers()['Content-Security-Policy']);
        $this->assertSame('attachment; filename="x.html"', $response->headers->get('Content-Disposition'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileTypes(): array
    {
        return [
            'a NUL byte' => ["text/html\0"],
            'a NUL inside' => ["image/p\0ng"],
            'a line break' => ["text/html\r\nX-Evil: 1"],
            'a control character' => ["text/html\x07"],
            'a quote' => ['text/html; x="a"'],
            'no subtype' => ['text'],
            'a space in a token' => ['te xt/html'],
            'an angle bracket' => ['text/<html>'],
            'nothing' => [''],
        ];
    }

    /**
     * @dataProvider hostileTypes
     */
    public function testAStoredTypeThatIsNotAMediaTypeNeverReachesAHeader(string $stored): void
    {
        $response = $this->serve($this->served($stored), '/x');

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'), json_encode($stored));
        $this->assertSame('attachment; filename="x"', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testAWholeFileIsStreamedWithItsLengthTypeAndTheHeadersOfAPublicFile(): void
    {
        $page = $this->context();
        $this->files->lookup = new FileLookup(FileOutcome::Served, $this->served());

        $response = $this->pages->serve($page, 'abc', '/photo.png');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame((string) strlen(self::BYTES), $response->headers->get('Content-Length'));
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertNull($response->headers->get('Content-Disposition'), 'an image is shown');
        $this->assertTrue($this->files->released, 'the session is released before the file is streamed');
        $this->assertSame(self::BYTES, $this->sent($response));
        $this->assertSame(1, $this->files->opened);
        $this->assertSame([HttpDate::format(self::CHANGED)], $page->headers()['Last-Modified']);
    }

    public function testAFileThatCanBePassedThroughIsPassedThroughAndNotOpenedForReading(): void
    {
        $response = $this->serve($this->served(passThrough: true));

        $this->assertSame(self::BYTES, $this->sent($response));
        $this->assertSame(1, $this->files->passedThrough);
        $this->assertSame(0, $this->files->opened);
        $this->assertSame((string) strlen(self::BYTES), $response->headers->get('Content-Length'));
    }

    public function testAPublicFilesExpiresIsAnHttpDate(): void
    {
        $page = $this->context();
        $this->files->lookup = new FileLookup(FileOutcome::Served, $this->served());

        $this->pages->serve($page, 'abc', '');

        $expires = $page->caching()['Expires'];
        $this->assertSame(gmdate('D, d M Y H:i:s', self::NOW + 30 * 86400) . ' GMT', $expires);
        $this->assertSame(HttpDate::format(self::NOW + 30 * 86400), $expires);
        $this->assertNotNull(HttpDate::parse($expires), 'it is a date a recipient reads');
        $this->assertSame(['Pragma' => 'public', 'Cache-Control' => 'public', 'Expires' => $expires], $page->caching());
    }

    public function testAFileOnlyItsReadersMayHaveIsKeptByNoSharedCache(): void
    {
        $page = $this->context();
        $this->files->lookup = new FileLookup(FileOutcome::Served, $this->served(caching: FileCaching::Restricted));

        $this->pages->serve($page, 'abc', '');

        $this->assertSame(['Cache-Control' => 'private, no-cache'], $page->caching());
    }

    public function testAnAdministratorsFileIsKeptByNoCacheAtAll(): void
    {
        $page = $this->context();
        $this->files->lookup = new FileLookup(FileOutcome::Served, $this->served('application/zip', FileCaching::AdministratorsOnly));

        $this->pages->serve($page, 'abc', '');

        $this->assertSame(['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache'], $page->caching());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function contentTypes(): array
    {
        return [
            'a text type with no charset gets the one PHP gave it' => ['text/plain', 'text/plain;charset=UTF-8'],
            'a text type in capitals too' => ['TEXT/HTML', 'TEXT/HTML;charset=UTF-8'],
            'a charset that is named stays' => ['text/plain; charset=iso-8859-1', 'text/plain; charset=iso-8859-1'],
            'an image is as stored' => ['image/png', 'image/png'],
            'a PDF is as stored' => ['application/pdf', 'application/pdf'],
            'a type with parameters but no charset' => ['text/csv; header=present', 'text/csv; header=present;charset=UTF-8'],
        ];
    }

    /**
     * @dataProvider contentTypes
     */
    public function testTheContentTypeIsTheStoredOneWithTheCharsetOfATextType(string $stored, string $sent): void
    {
        $response = $this->serve($this->served($stored));

        $this->assertSame($sent, $response->headers->get('Content-Type'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function typesAShownInline(): array
    {
        return [['image/jpeg'], ['image/png'], ['image/gif'], ['image/webp'], ['application/pdf'], ['audio/mpeg'], ['audio/ogg'], ['audio/wav'],
            ['video/mp4'], ['video/ogg'], ['video/webm'], ['IMAGE/PNG; x=y'], ['  Audio/MPEG']];
    }

    /**
     * @dataProvider typesAShownInline
     */
    public function testATypeThatCannotRunScriptsIsShown(string $type): void
    {
        $response = $this->serve($this->served($type));

        $this->assertNull($response->headers->get('Content-Disposition'), $type);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function typesADownloaded(): array
    {
        return [['text/html'], ['image/svg+xml'], ['application/javascript'], ['text/javascript'], ['application/octet-stream'], ['application/zip'],
            ['application/xhtml+xml'], ['text/plain'], ['image/png-not-really'], ['']];
    }

    /**
     * @dataProvider typesADownloaded
     */
    public function testAnyOtherTypeIsADownloadAndNeverRunByTheBrowser(string $type): void
    {
        $response = $this->serve($this->served($type), '/x');

        $this->assertSame('attachment; filename="x"', $response->headers->get('Content-Disposition'), $type);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function downloadNames(): array
    {
        return [
            'the last segment of the address' => ['/archive.zip', 'archive.zip'],
            'a name with a space' => ['/some name.html', 'some_name.html'],
            'a path of several segments' => ['/a/b/c.zip', 'c.zip'],
            'no name' => ['', 'download'],
            'only a slash' => ['/', 'download'],
            'what is not a letter, a digit or one of ._-' => ['/x";y\\z<>.html', 'x__y_z__.html'],
            'a name of capitals and digits' => ['/File-2.TAR.gz', 'File-2.TAR.gz'],
            'a name that is not ASCII' => ['/café.zip', 'caf__.zip'],
            'a zero' => ['/0', '0'],
        ];
    }

    /**
     * @dataProvider downloadNames
     */
    public function testADownloadIsNamedAfterItsAddress(string $rest, string $name): void
    {
        $response = $this->serve($this->served('application/zip'), $rest);

        $this->assertSame('attachment; filename="' . $name . '"', $response->headers->get('Content-Disposition'));
    }

    public function testARangeIsAnsweredWithExactlyItsBytes(): void
    {
        $response = $this->serve($this->served(), '', 'GET', ['Range' => 'bytes=5-14']);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('10', $response->headers->get('Content-Length'));
        $this->assertSame('bytes 5-14/65', $response->headers->get('Content-Range'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame(substr(self::BYTES, 5, 10), $this->sent($response));
        $this->assertSame(1, $this->files->opened);
        $this->assertTrue($this->files->released);
    }

    public function testTheLastBytesOfAFileAreTheEndOfIt(): void
    {
        $response = $this->serve($this->served(), '', 'GET', ['Range' => 'bytes=-5']);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 60-64/65', $response->headers->get('Content-Range'));
        $this->assertSame('5', $response->headers->get('Content-Length'));
        $this->assertSame('JKLM', substr($this->sent($response), 1));
    }

    public function testARangeThatIsNoRangeOfBytesIsIgnoredAndTheWholeFileIsSent(): void
    {
        foreach (['bytes=0-4,10-14', 'items=0-5', 'bytes=abc', ''] as $header) {
            $response = $this->serve($this->served(), '', 'GET', ['Range' => $header]);

            $this->assertSame(200, $response->getStatusCode(), $header);
            $this->assertNull($response->headers->get('Content-Range'), $header);
            $this->assertSame(self::BYTES, $this->sent($response), $header);
        }
    }

    public function testARangeNoByteOfTheFileIsInIsRefusedWithTheSizeAndNoBody(): void
    {
        $response = $this->serve($this->served(), '', 'GET', ['Range' => 'bytes=999999-']);

        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */65', $response->headers->get('Content-Range'));
        $this->assertSame('0', $response->headers->get('Content-Length'), 'the answer has no body, whatever the size of the file');
        $this->assertSame('', $response->getContent());
        $this->assertSame(0, $this->files->opened);
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame([['debug', 'Requested Range Not Satisfiable']], $this->logged);
    }

    public function testAStreamThatCannotBeOpenedForARangeIsLoggedAndSendsNothing(): void
    {
        $file = new ServedFile(65, 'image/png', self::CHANGED, FileCaching::Public, static fn (): mixed => false, null);

        $response = $this->serve($file, '', 'GET', ['Range' => 'bytes=0-9']);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('', $this->sent($response));
        $this->assertSame([['error', 'Could not open stream.']], array_slice($this->logged, -1));
    }

    public function testAHeadReadsNothing(): void
    {
        $unreadable = new ServedFile(
            65,
            'image/png',
            self::CHANGED,
            FileCaching::Public,
            static function (): never {
                self::fail('a HEAD must not open the file');
            },
            static function (): never {
                self::fail('a HEAD must not pass the file through');
            }
        );
        foreach ([[], ['Range' => 'bytes=0-9']] as $headers) {
            $request = Request::create('/file/abc', 'HEAD');
            foreach ($headers as $name => $value) {
                $request->headers->set($name, $value);
            }
            $this->files->lookup = new FileLookup(FileOutcome::Served, $unreadable);

            $response = $this->pages->serve(new PageContext($request, new SitePage('Idno\Pages\File\View')), 'abc', '');
            $response->prepare($request);
            ob_start();
            try {
                $response->send();
            } finally {
                $output = (string) ob_get_clean();
            }

            $this->assertSame('', $output);
            $this->assertSame($headers === [] ? 200 : 206, $response->getStatusCode());
            $this->assertSame($headers === [] ? '65' : '10', $response->headers->get('Content-Length'), 'a HEAD says how long the body would be');
        }
    }

    public function testARangeWithASatisfiedConditionIsNotModified(): void
    {
        $this->files->lookup = new FileLookup(FileOutcome::Served, $this->served());
        $since = HttpDate::format(self::CHANGED);

        foreach ([[], ['Range' => 'bytes=0-9']] as $range) {
            try {
                $this->pages->serve($this->context('GET', ['If-Modified-Since' => $since] + $range), 'abc', '');
                $this->fail('the copy the client has is current');
            } catch (PageNotModified) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $this->files->opened);
        $this->assertSame([], $this->written);
    }

    public function testACopyThatIsOlderThanTheFileIsSentTheFileAndSoIsOneBesideAnIfNoneMatch(): void
    {
        $this->files->lookup = new FileLookup(FileOutcome::Served, $this->served());

        $older = $this->pages->serve($this->context('GET', ['If-Modified-Since' => HttpDate::format(self::CHANGED - 1)]), 'abc', '');
        $this->assertSame(200, $older->getStatusCode());

        $beside = $this->pages->serve($this->context('GET', ['If-Modified-Since' => HttpDate::format(self::CHANGED + 86400), 'If-None-Match' => '"x"']), 'abc', '');
        $this->assertSame(200, $beside->getStatusCode(), 'If-Modified-Since is ignored beside an If-None-Match');
    }

    public function testAnAdministratorsFileIsRevalidatedLikeAnyOther(): void
    {
        $this->files->lookup = new FileLookup(FileOutcome::Served, $this->served('application/zip', FileCaching::AdministratorsOnly));
        $page = $this->context('GET', ['If-Modified-Since' => HttpDate::format(self::CHANGED)]);

        $this->expectException(PageNotModified::class);
        try {
            $this->pages->serve($page, 'abc', '');
        } finally {
            $this->assertSame(['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache'], $page->caching(), 'its 304 carries its caching lines');
        }
    }

    public function testAFilesDateAndCachingLinesAreOnTheContextWhenItsCopyIsJudgedCurrent(): void
    {
        $this->files->lookup = new FileLookup(FileOutcome::Served, $this->served());
        $page = $this->context('GET', ['If-Modified-Since' => HttpDate::format(self::CHANGED)]);

        try {
            $this->pages->serve($page, 'abc', '');
            $this->fail('the copy is current');
        } catch (PageNotModified) {
        }

        $this->assertSame([HttpDate::format(self::CHANGED)], $page->headers()['Last-Modified']);
        $this->assertSame('public', $page->caching()['Cache-Control']);
        $this->assertSame(0, $this->files->opened);
    }
}

final class FileStoreDouble implements StoredFiles
{
    public FileLookup $lookup;

    public bool $released = false;

    public int $opened = 0;

    public int $passedThrough = 0;

    public function __construct()
    {
        $this->lookup = new FileLookup(FileOutcome::Missing);
    }

    public function lookUp(string $id): FileLookup
    {
        return $this->lookup;
    }

    public function releaseSession(): void
    {
        $this->released = true;
    }
}

final class FileDrawerDouble implements PageDrawer
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $templates = [];

    public function templateType(): string
    {
        return 'default';
    }

    public function isApiRequest(): bool
    {
        return false;
    }

    public function finishSession(): void
    {
    }

    public function forwardTrace(?array $frames): ?string
    {
        return null;
    }

    public function displayUrl(): string
    {
        return 'https://example.com/';
    }

    public function urlWithVar(string $name, string $value, string $url): string
    {
        return $url;
    }

    public function drawTemplate(string $name, array $vars): string
    {
        $this->templates[] = [$name, $vars];

        return '<' . $name . '>';
    }

    public function drawPage(array $vars, int $status, string $shell = 'shell'): DrawnPage
    {
        return new DrawnPage('page', [['Content-type', 'text/html']]);
    }
}
