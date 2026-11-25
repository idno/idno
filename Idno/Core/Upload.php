<?php

/**
 * What the server accepts as an uploaded file, and what to tell a member when an upload
 * failed.
 *
 * PHP drops a file that is larger than upload_max_filesize and leaves only an error code
 * in $_FILES, and drops the whole request (fields and files) when it is larger than
 * post_max_size. Neither is an error as far as PHP is concerned, so every form that takes
 * a file has to look: use errorMessage() for a file's error code, and Page::post() itself
 * answers a request that was dropped whole (requestWasTooLarge()).
 *
 * @package    idno
 * @subpackage core
 */

namespace Idno\Core {

    class Upload
    {

        /**
         * The kinds of image a browser can show, by MIME type, with the name a member knows
         * them by.
         */
        const IMAGE_TYPES = [
            'image/jpeg'    => 'JPEG',
            'image/png'     => 'PNG',
            'image/gif'     => 'GIF',
            'image/webp'    => 'WebP',
            'image/avif'    => 'AVIF',
            'image/svg+xml' => 'SVG',
        ];

        /**
         * A size written the way php.ini writes one ("2M", "512K", "1G", "8388608") as a
         * number of bytes. Zero, a negative number and an empty value mean "no limit" and
         * give 0.
         *
         * @param  string|int|false|null $setting
         * @return int
         */
        public static function bytes($setting): int
        {
            $setting = trim((string) $setting);
            if ($setting === '') {
                return 0;
            }
            $number = (float) $setting;
            switch (strtolower(substr($setting, -1))) {
                case 'g':
                    $number *= 1024;
                    // no break
                case 'm':
                    $number *= 1024;
                    // no break
                case 'k':
                    $number *= 1024;
            }

            return $number > 0 ? (int) $number : 0;
        }

        /**
         * The largest request body the server reads, in bytes (post_max_size); 0 for no limit.
         */
        public static function maxRequestSize(): int
        {
            return static::bytes(ini_get('post_max_size'));
        }

        /**
         * The largest single file the server keeps, in bytes: upload_max_filesize, or
         * post_max_size when that is smaller. 0 for no limit.
         */
        public static function maxFileSize(): int
        {
            $limits = array_filter([static::bytes(ini_get('upload_max_filesize')), static::maxRequestSize()]);

            return $limits ? (int) min($limits) : 0;
        }

        /**
         * How many files one request can carry (max_file_uploads); 0 for no limit.
         */
        public static function maxFiles(): int
        {
            return max(0, (int) ini_get('max_file_uploads'));
        }

        /**
         * A number of bytes in the units people use: "2 MB", "1.5 MB", "512 KB".
         */
        public static function formatSize(int $bytes): string
        {
            $units = ['bytes', 'KB', 'MB', 'GB', 'TB'];
            $size = (float) max(0, $bytes);
            $unit = 0;
            while ($size >= 1024 && $unit < count($units) - 1) {
                $size /= 1024;
                $unit++;
            }
            $text = $unit === 0 ? (string) (int) $size : rtrim(rtrim(number_format($size, 1, '.', ''), '0'), '.');

            return $text . ' ' . $units[$unit];
        }

        /**
         * The names of image types as a phrase: "JPEG, PNG, GIF, WebP, AVIF or SVG".
         *
         * @param string[]|null $names
         */
        public static function typeList(?array $names = null): string
        {
            $names = array_values($names ?? static::IMAGE_TYPES);
            if (count($names) < 2) {
                return (string) reset($names);
            }
            $last = array_pop($names);

            return Idno::site()->language()->esc_('%s or %s', [implode(', ', $names), $last]);
        }

        /**
         * The value for the accept attribute of a file input that takes pictures.
         */
        public static function imageAccept(): string
        {
            return implode(',', array_keys(static::IMAGE_TYPES));
        }

        /**
         * Is this file one of the kinds of image a browser can show? Decided from the
         * content of the file (and, for SVG, which has no signature, from its name).
         *
         * @param string $path Where the file is now
         * @param string $name The name it was uploaded with
         */
        public static function isShowableImage(string $path, string $name = ''): bool
        {
            if ($path === '' || !is_file($path)) {
                return false;
            }
            if (\Idno\Entities\File::isSVG($path, $name)) {
                return true;
            }
            $information = @getimagesize($path);

            return is_array($information) && isset($information['mime']) && isset(static::IMAGE_TYPES[$information['mime']]) && $information['mime'] !== 'image/svg+xml';
        }

        /**
         * The limits as a sentence for a form: "Up to 2 MB per file, 8 MB in all."
         * Empty when the server sets no limit.
         */
        public static function limitText(bool $several = false): string
        {
            $language = Idno::site()->language();
            $file = static::maxFileSize();
            $total = static::maxRequestSize();
            if (!$file) {
                return '';
            }
            if ($several && $total && $total > $file) {
                return $language->esc_('Up to %s per file, %s in all.', [static::formatSize($file), static::formatSize($total)]);
            }

            return $language->esc_('Up to %s.', [static::formatSize($file)]);
        }

        /**
         * What to tell a member about a file that PHP gave an error code for. Empty for
         * UPLOAD_ERR_OK and for UPLOAD_ERR_NO_FILE (no file was chosen, which only the
         * form can judge).
         *
         * @param int    $code One of PHP's UPLOAD_ERR_ constants
         * @param string $name The name of the file, if known
         */
        public static function errorMessage($code, string $name = ''): string
        {
            $language = Idno::site()->language();
            $code = (int) $code;
            $file = $name !== '' ? '"' . $name . '"' : $language->_('The file');

            switch ($code) {
                case UPLOAD_ERR_OK:
                case UPLOAD_ERR_NO_FILE:
                    return '';
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    if ($limit = static::maxFileSize()) {
                        return $language->esc_('%s is too large: this site accepts files of up to %s. Nothing was saved.', [$file, static::formatSize($limit)]);
                    }

                    return $language->esc_('%s is larger than this site accepts. Nothing was saved.', [$file]);
                case UPLOAD_ERR_PARTIAL:
                    return $language->esc_('%s did not finish uploading. Nothing was saved: please try again.', [$file]);
                default:
                    // No temporary folder, a disk that can't be written, an extension: the site's problem
                    Idno::site()->logging()->error('An upload failed with PHP upload error ' . $code . ' (' . \Idno\Files\FileSystem::getUploadErrorCodeMessage(min(max($code, 0), UPLOAD_ERR_EXTENSION) ?: UPLOAD_ERR_CANT_WRITE) . ')');

                    return $language->esc_('This site could not store %s. Nothing was saved. The problem is with the site, not with your file: please tell its administrator.', [$file]);
            }
        }

        /**
         * Did PHP throw this request's body away because it was larger than post_max_size?
         * Then $_POST and $_FILES are both empty, although the browser sent a body.
         */
        public static function requestWasTooLarge(): bool
        {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !empty($_POST) || !empty($_FILES)) {
                return false;
            }
            $limit = static::maxRequestSize();
            $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

            return $limit > 0 && $length > $limit;
        }

        /**
         * What to tell a member whose request was thrown away whole.
         */
        public static function requestTooLargeMessage(): string
        {
            return Idno::site()->language()->esc_(
                'What you sent was larger than this site accepts at once (%s), so none of it arrived and nothing was saved. Please try again with a smaller file, or with fewer files.',
                [static::formatSize(static::maxRequestSize())]
            );
        }

    }

}
