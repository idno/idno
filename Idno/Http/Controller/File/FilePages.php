<?php

declare(strict_types=1);

namespace Idno\Http\Controller\File;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRenderer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The page of a stored file (`/file/<id>` and `/file/<id>/<name>`): the file's bytes, or the part of them a `Range` asks for, streamed to the client
 * a piece at a time so that a file of any size is never held in memory. `FileController` answers the requests the router gives it.
 *
 * Who may have the file is the site's rule (`StoredFiles`, applied before anything of the file is read): a file that does not exist, or that is attached to a post the viewer may not read, is the missing page (the same answer, so that an address does not tell which files exist); an
 * administrators-only file (an archive of the site) is a 403 with no body that no cache may keep, to anyone else. A file the viewer may have is answered with the session let go of first, the conditional request judged when the file's age is known (a copy
 * that is current is a 304 that carries the file's caching lines and date, before any range), one of the three sets of caching lines, and its `Last-Modified` (the stored file's own modification time: none when that is unknown), `Content-Type`
 * (a shown file's canonical type; a download's stored type if it is well formed, else `application/octet-stream`), `X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox` (not for a PDF, which a sandbox stops the browser showing) and `Accept-Ranges`. A type a browser could run (HTML, SVG, scripts) and
 * every type that is not a picture, a PDF, audio or video is a download, named after the last segment of the address. A satisfiable `Range` is a 206 with
 * exactly the bytes its `Content-Length` and `Content-Range` name; one that no byte of the file satisfies is a 416 whose `Content-Range` names the size
 * of the file, with no body; a header that is not one range of bytes is ignored. A HEAD sends the headers and reads nothing.
 */
final class FilePages
{
    /** The types a browser shows without being able to run them: everything else is a download */
    private const SHOWN = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'audio/mpeg', 'audio/ogg', 'audio/wav',
        'video/mp4', 'video/ogg', 'video/webm',
    ];

    /**
     * @param \Closure(): LoggerInterface $logger the site's logger, fetched when something is logged: it needs the legacy application, which a kernel
     *                                            that did not boot it does not have
     * @param (\Closure(): int)|null      $now    the moment of the request (a test gives one); `time()` when null
     * @param (\Closure(string): mixed)|null $write where the bytes go (a test gives one); null is the output, with every output buffer ended first
     */
    public function __construct(
        private readonly StoredFiles $files,
        private readonly PageRenderer $renderer,
        #[AutowireServiceClosure(LoggerInterface::class)]
        private readonly \Closure $logger,
        private readonly ?\Closure $now = null,
        private readonly ?\Closure $write = null,
    )
    {
    }

    /**
     * @param string $id   the file's id, as the address names it
     * @param string $rest what follows the id in the address, a slash and the name the file is given (empty when there is none)
     */
    public function serve(PageContext $page, string $id, string $rest): Response
    {
        $lookup = $this->files->lookUp($id);
        if ($lookup->outcome === FileOutcome::Missing || ($lookup->outcome === FileOutcome::Served && $lookup->file === null)) {
            return $this->renderer->notFound($page);
        }
        if ($lookup->outcome === FileOutcome::AdministratorsOnly) {
            $page->setStatus(403);
            // not even the refusal may be kept by a cache
            $page->setCaching(['Cache-Control' => 'private, no-store']);

            return new Response('', 403);
        }
        $file = $lookup->file;

        // The session is let go of before the bytes are sent. The file's caching lines and its date are asked for first, so that a 304 carries them as a
        // 200 would; a copy the client has is then judged before anything else of the file is asked for (when the file's age is known: a file never changes
        // under its id, so its own modification time dates it and a revalidation is answered "not modified")
        $this->files->releaseSession();
        $page->setCaching($file->caching->caching($this->now === null ? time() : ($this->now)()));
        if ($file->lastChange !== null) {
            $page->setLastModified($file->lastChange);
            $page->answerConditionally($file->lastChange);
        }

        // A picture, a PDF, audio or video is shown, as its own canonical type; anything else is a download, as its stored type when that is a well-formed one
        // and as an opaque one when it is not (a stored type that is not a type can never reach a header)
        $base = strtolower(trim(explode(';', $file->mimeType)[0]));
        $shown = in_array($base, self::SHOWN, true);
        $headers = ['Content-Type' => $shown ? $base : self::contentType($file->mimeType), 'X-Content-Type-Options' => 'nosniff'];
        if (!$shown) {
            $headers['Content-Disposition'] = 'attachment; filename="' . self::downloadName($rest) . '"';
        }
        $headers['Accept-Ranges'] = 'bytes';
        // Nothing a file holds may run as part of the site: no script, no form, a page of its own origin; a PDF is shown by the browser's viewer, which a
        // sandbox stops
        if ($base !== 'application/pdf') {
            $page->addHeader('Content-Security-Policy', 'sandbox');
        }

        $header = $page->request()->headers->get('Range');
        $range = $header !== null ? ByteRange::parse($header, $file->size) : null;

        if ($range === false) {
            ($this->logger)()->debug('Requested Range Not Satisfiable');
            $response = new Response('', 416);
            $response->headers->add($headers);
            $response->headers->set('Content-Range', 'bytes */' . $file->size);
            // (nothing of the file is sent, whatever its size)
            $response->headers->set('Content-Length', '0');

            return $response;
        }

        if ($range !== null) {
            [$start, $end] = $range;
            ($this->logger)()->debug("Partial content request for $start - $end bytes from {$file->size} available bytes");
            $response = new StreamedResponse(function () use ($file, $start, $end): void {
                $stream = $file->open();
                if (!is_resource($stream)) {
                    ($this->logger)()->error('Could not open stream.');

                    return;
                }
                FileStream::seekTo($stream, $start);
                FileStream::streamRange($stream, $start, $end, $this->write);
                fclose($stream);
            }, 206);
            $response->headers->add($headers);
            // Both positions are inclusive, so bytes 5-5 is one byte
            $response->headers->set('Content-Length', (string) ($end - $start + 1));
            $response->headers->set('Content-Range', "bytes $start-$end/{$file->size}");

            return $response;
        }

        $response = new StreamedResponse(function () use ($file): void {
            if ($file->passThrough()) {
                return;
            }
            $stream = $file->open();
            if (is_resource($stream)) {
                FileStream::streamAll($stream, $this->write);
                fclose($stream);
            }
        }, 200);
        $response->headers->add($headers);
        $response->headers->set('Content-Length', (string) $file->size);

        return $response;
    }

    /**
     * The `Content-Type` of a download: the stored type when it is a well-formed media type (a type, a subtype and plain parameters: no control character,
     * quote or space inside a token), else `application/octet-stream`; for a text type that names no charset, the one PHP sent a legacy page's
     * (`text/plain;charset=UTF-8`, with no space), so that the header is what it was.
     */
    private static function contentType(string $mimeType): string
    {
        $mimeType = trim($mimeType, " \t");
        if (preg_match('~\A[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+(?:[ \t]*;[ \t]*[a-z0-9_-]+=[a-z0-9_.-]+)*\z~i', $mimeType) !== 1) {
            return 'application/octet-stream';
        }
        if (stripos($mimeType, 'text/') === 0 && stripos($mimeType, 'charset') === false) {
            return $mimeType . ';charset=UTF-8';
        }

        return $mimeType;
    }

    /**
     * The name a download is given: the last segment of the address, with every character that is not a letter, a digit or one of `._-` replaced by `_`,
     * and `download` when the address has none.
     */
    private static function downloadName(string $rest): string
    {
        $name = basename($rest);
        $name = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        return $name === '' ? 'download' : $name;
    }
}
