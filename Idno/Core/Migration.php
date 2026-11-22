<?php

namespace Idno\Core {

    use Idno\Portability\ArchiveExporter;
    use Idno\Portability\BloggerImporter;
    use Idno\Portability\FeedExporter;
    use Idno\Portability\ImportedImages;
    use Idno\Portability\ImportedMedia;
    use Idno\Portability\SiteFacts;
    use Idno\Portability\WordPressImporter;

    /**
     * Import and export, as they were called before they became services.
     *
     * @deprecated These static methods stay for one release, for code that calls them: each one calls the
     *             service that does the work (Idno\Portability\ArchiveExporter, FeedExporter,
     *             WordPressImporter, BloggerImporter, ImportedMedia), which is what to call instead.
     */
    class Migration extends \Idno\Common\Component
    {

        /**
         * Prepares a folder containing all of this site's data.
         *
         * @deprecated Use Idno\Portability\ArchiveExporter::exportToFolder().
         * @return string|false the folder
         */
        static function exportToFolder($dir = false)
        {
            set_time_limit(0);  // Switch off the time limit for PHP

            return Idno::container()->get(ArchiveExporter::class)->exportToFolder($dir ?: null);
        }

        /**
         * Given the path to an Idno export folder, creates a zip archive of it and returns the path to that.
         * The archive is made in a directory of its own, in the temporary directory, that only the web server's
         * user can enter (mode 0700) and whose name cannot be guessed, never at a name anyone can predict.
         * The caller removes the archive and its directory when it is done (the directory is the archive's parent).
         *
         * @deprecated Use Idno\Portability\ArchiveExporter::zip().
         * @param  $path
         * @param  $save_path Not used.
         * @return string|false
         */
        static function archiveExportFolder($path, $save_path = false)
        {
            if (!is_dir($path) || !file_exists(rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'known.json')) {
                return false;
            }
            $exporter = Idno::container()->get(ArchiveExporter::class);
            $private  = rtrim(Idno::site()->config()->getTempDir(), '/\\') . DIRECTORY_SEPARATOR . 'idno-archive-' . bin2hex(random_bytes(16));
            if (!@mkdir($private, 0700)) {
                Idno::site()->logging()->debug('archiveExportFolder() could not make its private directory');

                return false;
            }
            $zip = $private . DIRECTORY_SEPARATOR . $exporter->archiveName();
            try {
                $exporter->zip($path, $zip);
            } catch (\Throwable $e) {
                Idno::site()->logging()->debug('archiveExportFolder() failed: ' . get_class($e));
                ArchiveExporter::removeTree($private);

                return false;
            }

            return $zip;
        }

        /**
         * Exports the site and returns the path to the archive of it.
         *
         * @deprecated Use Idno\Portability\ArchiveExporter::export(), which stores the archive for administrators.
         * @param  $dir Path to export
         * @return bool|string
         */
        static function createCompressedArchive($dir = false)
        {
            if ($path = self::exportToFolder($dir)) {
                if ($archive = self::archiveExportFolder($path)) {
                    self::cleanUpFolder($path);

                    return $archive;
                }
                self::cleanUpFolder($path);
            }

            return false;
        }

        /**
         * Given the path to an archive folder, recursively removes it
         *
         * @deprecated Use Idno\Portability\ArchiveExporter::removeTree().
         * @param $path
         */
        static function cleanUpFolder($path)
        {
            ArchiveExporter::removeTree((string)$path);
        }

        /**
         * Given the XML source of a feed (Blogger's Atom, or the RSS Idno exports), imports each post.
         *
         * @deprecated Use Idno\Portability\BloggerImporter::import(), which reads a file.
         * @param $xml
         */
        static function importFeedXML(string $xml): bool
        {
            return self::importString($xml, BloggerImporter::class);
        }

        /**
         * Replaces the pictures of a post's HTML that are on $src_url (or a subdomain) with copies kept by the site.
         *
         * @deprecated Use Idno\Portability\ImportedImages and ImportedMedia.
         */
        static function importImagesFromBodyHTML(string $body, string $src_url): string
        {
            $media = Idno::container()->get(ImportedMedia::class)->forDomain($src_url);

            return ImportedImages::bring($body, $media, Idno::container()->get(SiteFacts::class)->url())[0];
        }

        /**
         * Given the XML source of a Blogger export, imports each post into Idno.
         *
         * @deprecated Use Idno\Portability\BloggerImporter::import(), which reads a file.
         * @param $xml
         */
        static function importBloggerXML(string $xml): bool
        {
            return self::importString($xml, BloggerImporter::class);
        }

        /**
         * Given the XML source of a WordPress export, imports each post into Idno.
         *
         * @deprecated Use Idno\Portability\WordPressImporter::import(), which reads a file.
         * @param $xml
         */
        static function importWordPressXML(string $xml): bool
        {
            return self::importString($xml, WordPressImporter::class);
        }

        /**
         * Retrieve all posts as an RSS feed
         *
         * @deprecated Use Idno\Portability\FeedExporter::rss() (chunks) or stream() (a readable stream).
         * @param  bool|true $hide_private Should we hide private posts? Default: true.
         * @param  string    $user_uuid    User UUID to export for. Default: all users.
         * @param  bool|false $wxr_mode    Generate WXR (WordPress eXtended RSS) format. Default: false.
         * @return resource a readable stream on success, false on error
         */
        static function getExportRSS($hide_private = true, $user_uuid = '', $wxr_mode = false)
        {
            return Idno::container()->get(FeedExporter::class)->stream((bool)$hide_private, (string)$user_uuid, (bool)$wxr_mode);
        }

        /**
         * Writes the XML to a private temporary file, runs the importer on it as the logged-in user, and
         * removes the file.
         *
         * @param class-string<WordPressImporter|BloggerImporter> $importer
         */
        private static function importString(string $xml, string $importer): bool
        {
            $path = tempnam(Idno::site()->config()->getTempDir(), 'import_');
            if ($path === false) {
                return false;
            }
            try {
                file_put_contents($path, $xml);
                $user = Idno::site()->session()->currentUserUUID();
                if (empty($user)) {
                    return false;
                }

                return Idno::container()->get($importer)->import($path, $user)['posts'] > 0;
            } finally {
                @unlink($path);
            }
        }

    }

}
