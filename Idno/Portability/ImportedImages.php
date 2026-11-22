<?php

declare(strict_types=1);

namespace Idno\Portability;

/**
 * Brings the pictures a post's HTML shows into the site: each image address ImportedMedia accepts is
 * fetched and stored, and the address in the HTML is replaced by the stored file's. The address is
 * replaced where the HTML writes it, with its ampersands as they are (`&`) and as entities (`&amp;`).
 * A picture that cannot be fetched stays where it is.
 *
 * A helper of the importers, built by them, not a service.
 */
final class ImportedImages
{
    /**
     * @return array{0: string, 1: list<string>} the HTML, and the ids of the files stored for it
     */
    public static function bring(string $html, ImportedMedia $media, string $siteUrl): array
    {
        if ($html === '' || stripos($html, '<img') === false) {
            return [$html, []];
        }
        $sources = self::sources($html);
        $ids = [];
        foreach ($sources as $source) {
            if (!$media->accepts($source)) {
                continue;
            }
            $id = $media->import($source);
            if ($id === null) {
                continue;
            }
            $ids[] = $id;
            $address = $siteUrl . 'file/' . $id;
            $html = str_replace(
                [$source, htmlspecialchars($source, ENT_COMPAT | ENT_HTML5), htmlspecialchars($source, ENT_QUOTES | ENT_HTML5)],
                $address,
                $html
            );
        }

        return [$html, $ids];
    }

    /**
     * @return list<string> the distinct src attributes of the images, as an HTML parser reads them
     */
    private static function sources(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            // The prefix makes libxml read the fragment as UTF-8.
            if (!@$document->loadHTML('<?xml encoding="UTF-8">' . $html)) {
                return [];
            }
            $sources = [];
            foreach ($document->getElementsByTagName('img') as $image) {
                $source = trim($image->getAttribute('src'));
                if ($source !== '') {
                    $sources[$source] = true;
                }
            }

            return array_map('strval', array_keys($sources));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
