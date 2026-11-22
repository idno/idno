<?php

declare(strict_types=1);

namespace Idno\Portability;

/**
 * Reads an uploaded XML file without trusting it: a file over a size limit, one that is not well-formed
 * and one with a document type declaration (which is where entities are declared: an expansion bomb, an
 * external entity that reads a file of the server) are refused with a fixed message. The network is never
 * used and no entity is substituted. The file is read from its path, never as one string.
 *
 * A static helper, not a service.
 */
final class SafeXml
{
    /** The prolog (what comes before the root element) is read at most this far for a declaration. */
    private const PROLOG_BYTES = 65536;

    /**
     * @throws \RuntimeException with a message safe to show, naming no path
     */
    public static function load(string $path, int $maxBytes): \SimpleXMLElement
    {
        $size = @filesize($path);
        if ($size === false || !is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('The file cannot be read.');
        }
        if ($size === 0) {
            throw new \RuntimeException('The file is empty.');
        }
        if ($size > $maxBytes) {
            throw new \RuntimeException('The file is larger than the limit of ' . $maxBytes . ' bytes.');
        }
        self::refuseDeclarations($path);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new \DOMDocument();
            // No LIBXML_NOENT (no entity is substituted), no DTD loading, no network.
            $loaded = $document->load($path, LIBXML_NONET | LIBXML_NOCDATA);
            if ($loaded !== true || $document->documentElement === null) {
                throw new \RuntimeException('The file is not well-formed XML.');
            }
            if ($document->doctype !== null) {
                throw new \RuntimeException('The file declares a document type, which is not accepted.');
            }
            $element = simplexml_import_dom($document);
            if (!$element instanceof \SimpleXMLElement) {
                throw new \RuntimeException('The file is not well-formed XML.');
            }

            return $element;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * A document type declaration, and so every entity declaration, comes before the root element: look
     * at the prolog only, so that "<!DOCTYPE" inside a CDATA section of a post (a post about HTML) is no
     * reason to refuse the file.
     */
    private static function refuseDeclarations(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The file cannot be read.');
        }
        try {
            $head = (string) fread($handle, self::PROLOG_BYTES);
        } finally {
            fclose($handle);
        }
        if (str_starts_with($head, "\xEF\xBB\xBF")) {
            $head = substr($head, 3);
        }
        // UTF-16 text is not accepted: the declaration could not be found by looking for ASCII.
        if (str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF") || str_contains(substr($head, 0, 8), "\0")) {
            throw new \RuntimeException('The file is not UTF-8 or ASCII text.');
        }

        $position = 0;
        $length = strlen($head);
        while ($position < $length) {
            $position += strspn($head, " \t\r\n", $position);
            if (substr($head, $position, 2) === '<?') {
                $end = strpos($head, '?>', $position);
                $next = $end === false ? false : $end + 2;
            } elseif (substr($head, $position, 4) === '<!--') {
                $end = strpos($head, '-->', $position);
                $next = $end === false ? false : $end + 3;
            } elseif (strncasecmp(substr($head, $position, 9), '<!doctype', 9) === 0) {
                throw new \RuntimeException('The file declares a document type, which is not accepted.');
            } else {
                return; // the root element
            }
            if ($next === false) {
                // A prolog longer than what was read: refuse rather than guess what follows.
                throw new \RuntimeException('The file is not well-formed XML.');
            }
            $position = $next;
        }
    }
}
