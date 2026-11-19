<?php

declare(strict_types=1);

namespace Idno\Storage\Files;

use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;

/**
 * Builds the Flysystem filesystem that holds uploads from the boot configuration (`BootConfig::all()`):
 * an `uploadpath` of `s3://<bucket>/<prefix>/` is S3, any other non-empty one a local directory.
 */
final class FilesystemFactory
{
    /**
     * @param array<string, mixed> $config
     * @param S3Client|null        $s3     replaces the client built from the configuration (tests pass one on a mock HTTP client)
     * @throws \LogicException when there is no upload path
     */
    public static function fromConfig(array $config, ?S3Client $s3 = null): FilesystemOperator
    {
        $uploadPath = self::uploadPath($config);
        if (trim($uploadPath, ' /') === '') {
            throw new \LogicException('No upload path is configured.');
        }

        if (self::isS3($config)) {
            $location = substr(trim($uploadPath), strlen('s3://'));
            $slash    = strpos($location, '/');
            $bucket   = $slash === false ? $location : substr($location, 0, $slash);
            $prefix   = $slash === false ? '' : trim(substr($location, $slash), '/');
            if ($bucket === '') {
                throw new \LogicException('The S3 upload path names no bucket.');
            }

            return new Filesystem(new AsyncAwsS3Adapter($s3 ?? self::s3Client($config), $bucket, $prefix));
        }

        $visibility = PortableVisibilityConverter::fromArray([
            'file' => ['public' => 0644, 'private' => 0600],
            'dir'  => ['public' => 0755, 'private' => 0700],
        ], Visibility::PUBLIC);

        return new Filesystem(new LocalFilesystemAdapter(rtrim(trim($uploadPath), '/'), $visibility));
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function isS3(array $config): bool
    {
        return str_starts_with(trim(self::uploadPath($config)), 's3://');
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function uploadPath(array $config): string
    {
        $path = $config['uploadpath'] ?? '';

        return is_string($path) ? $path : '';
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function s3Client(array $config): S3Client
    {
        $options = [];
        if (!empty($config['aws_region'])) {
            $options['region'] = (string) $config['aws_region'];
        }
        // With no credentials in the configuration the SDK's default chain applies.
        if (!empty($config['aws_key']) && !empty($config['aws_secret'])) {
            $options['accessKeyId']     = (string) $config['aws_key'];
            $options['accessKeySecret'] = (string) $config['aws_secret'];
        }

        return new S3Client($options);
    }
}
