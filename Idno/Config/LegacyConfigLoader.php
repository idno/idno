<?php

declare(strict_types=1);

namespace Idno\Config;

/**
 * Reads config.ini, config.json and environment variables into a BootConfig.
 *
 * This is a faithful port of the former Idno\Core\Config::loadIniFiles(),
 * including its precedence quirks, minus the per-host "{host}.ini" file.
 */
final class LegacyConfigLoader
{
    /**
     * Container factory: reads the live $_SERVER and $_ENV at runtime, so that no
     * configuration value is ever compiled into the cached container.
     */
    public static function fromGlobals(string $projectDir): BootConfig
    {
        return (new self())->load($projectDir, $_SERVER, $_ENV);
    }

    /**
     * @param array<string, mixed> $server The $_SERVER array
     * @param array<string, mixed> $env    The $_ENV array
     */
    public function load(string $projectDir, array $server, array $env): BootConfig
    {
        $config  = [];
        $default = true;

        foreach ([
            $projectDir,                    // Old location (deprecated)
            $projectDir . '/configuration', // New location
        ] as $dir) {
            $ini = @parse_ini_file($dir . '/config.ini', true);
            if (!empty($ini)) {
                $default = false;
                $config  = array_replace_recursive($ini, $config);
            }

            if (file_exists($dir . '/config.json')) {
                $json = file_get_contents($dir . '/config.json');
                if ($json) {
                    $json = json_decode($json, true);
                    if (!empty($json)) {
                        $default = false;
                        $config  = array_replace_recursive($config, $json);
                    }
                }
            }

            $this->applyEnvironmentSpecials($config, $env);
        }

        // IDNO_ prefixed variables take precedence over KNOWN_ prefixed ones.
        foreach ($server as $name => $val) {
            $name = (string) $name;
            if (str_starts_with($name, 'IDNO_')) {
                $config[strtolower(str_replace('IDNO_', '', $name))] = $val;
            }
        }
        // Backwards compatibility: KNOWN_ never overrides a value that is already set.
        foreach ($server as $name => $val) {
            $name = (string) $name;
            if (str_starts_with($name, 'KNOWN_')) {
                $configName = strtolower(str_replace('KNOWN_', '', $name));
                if (!isset($config[$configName])) {
                    $config[$configName] = $val;
                }
            }
        }

        // A site configured by the environment alone (a container) is installed: it names its database.
        if ($default && isset($config['database']) && is_string($config['database']) && trim($config['database']) !== '') {
            $default = false;
        }

        if (isset($config['uploadpath'])) {
            // End trailing slash insanity once and for all
            $config['uploadpath'] = rtrim((string) $config['uploadpath'], ' /') . '/';
        }
        unset($config['path']); // Path should always be derived
        unset($config['host']); // Host should always come from the URL

        return new BootConfig($config, $default);
    }

    /**
     * Database URL, S3 / Cloudcube and upload path variables, read from $_ENV.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $env
     */
    private function applyEnvironmentSpecials(array &$config, array $env): void
    {
        $dbUrl = $env['IDNO_DATABASE_URL'] ?? $env['KNOWN_DATABASE_URL'] ?? null;
        if ($dbUrl) {
            $config = array_replace($config, \Idno\Storage\ConnectionParameters::fromUrl((string) $dbUrl));
        }

        $cloudcube = array_key_exists('CLOUDCUBE_URL', $env);
        $awsS3     = array_key_exists('IDNO_AWS_S3_BUCKET', $env) || array_key_exists('KNOWN_AWS_S3_BUCKET', $env);
        $bucket    = '';
        $s3Path    = '';

        if ($cloudcube) {
            $parsed    = parse_url((string) $env['CLOUDCUBE_URL']);
            $hostParts = explode('.', $parsed['host'] ?? '');
            $bucket    = $hostParts[0];
            $config['aws_key']    = $env['CLOUDCUBE_ACCESS_KEY_ID'] ?? null;
            $config['aws_secret'] = $env['CLOUDCUBE_SECRET_ACCESS_KEY'] ?? null;
            $config['aws_bucket'] = $bucket;
            $config['aws_region'] = $bucket == 'cloud-cube-eu' ? 'eu-west-1' : 'us-east-1';
            $s3Path               = $parsed['path'] ?? '';
        } elseif ($awsS3) {
            $bucket = $env['IDNO_AWS_S3_BUCKET'] ?? $env['KNOWN_AWS_S3_BUCKET'];
            $config['aws_key']    = $env['IDNO_AWS_S3_ACCESS_KEY_ID'] ?? $env['KNOWN_AWS_S3_ACCESS_KEY_ID'] ?? null;
            $config['aws_secret'] = $env['IDNO_AWS_S3_SECRET_ACCESS_KEY'] ?? $env['KNOWN_AWS_S3_SECRET_ACCESS_KEY'] ?? null;
            $config['aws_bucket'] = $bucket;
            $config['aws_region'] = $env['IDNO_AWS_S3_REGION'] ?? $env['KNOWN_AWS_S3_REGION'] ?? null;
            $s3Path               = str_replace('//', '/', '/' . ($env['IDNO_AWS_S3_PATH_PREFIX'] ?? $env['KNOWN_AWS_S3_PATH_PREFIX'] ?? ''));
        }

        if (($cloudcube || $awsS3) && !empty($bucket)) {
            $config['filesystem'] = 'local';
            $config['uploadpath'] = "s3://{$bucket}{$s3Path}";
        }

        $awsRegion = $env['IDNO_AWS_S3_REGION'] ?? $env['KNOWN_AWS_S3_REGION'] ?? null;
        if ($awsRegion) {
            $config['aws_region'] = $awsRegion;
        }

        $uploadPath = $env['IDNO_UPLOAD_PATH'] ?? $env['KNOWN_UPLOAD_PATH'] ?? null;
        if ($uploadPath) {
            $config['uploadpath'] = $uploadPath;
        }
    }
}
