<?php

declare(strict_types=1);

namespace Idno\Install;

use Idno\Core\Version;
use Idno\Exceptions\ConfigurationException;

/**
 * The configuration file an installer writes: built from the template in one pass, refused when a
 * value could change another setting, and created only if no configuration file is there (it is
 * never overwritten).
 */
final class ConfigFile
{
    public function __construct(private readonly InstallPaths $paths)
    {
    }

    /**
     * The text of the configuration file.
     *
     * @param  array<string, mixed> $values database, dbname, dbuser, dbpass, dbhost, dbport, uploadpath, url
     * @throws ConfigurationException when the template is missing or a value cannot be written
     */
    public function build(array $values): string
    {
        $values = array_merge([
            'build' => Version::build(),
            'version' => Version::version(),
            'datetime' => date('r'),
            'database' => 'MySQL',
            'dbname' => 'idno',
            'dbuser' => '',
            'dbpass' => '',
            'dbhost' => 'localhost',
            'dbport' => '',
            'filesystem' => 'local',
            'uploadpath' => $this->paths->uploadDir() . '/',
            'url' => '',
        ], $values);

        $template = @file_get_contents($this->paths->template());
        if ($template === false || $template === '') {
            throw new ConfigurationException('Configuration template could not be loaded.');
        }
        $this->assertWritable($values);
        // A setting with no value is left out: with no address the site works its address out from
        // each request, and with no port the database's default is used.
        foreach (['url', 'dbport'] as $optional) {
            if ((string) $values[$optional] === '') {
                $template = preg_replace('/^' . $optional . ' = .*\R?/m', '', $template) ?? $template;
            }
        }

        // ONE pass, so that a value which names a placeholder is written as given
        $replacements = [];
        foreach ($values as $name => $value) {
            $replacements['%' . $name . '%'] = (string) $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * Refuses a value that cannot be written safely, before anything is created or written. A value
     * is written inside single quotes: a quote, a line break or another control character, or a
     * trailing backslash could end the value or start another line, and so add or change a setting.
     *
     * @param  array<string, mixed> $values
     * @throws ConfigurationException naming the setting, never the value
     */
    public function assertWritable(array $values): void
    {
        foreach ($values as $name => $value) {
            $value = (string) $value;
            if (preg_match('/[\'"\x00-\x1f\x7f]/', $value) === 1 || str_ends_with($value, '\\')) {
                throw new ConfigurationException('The installer cannot write the value of the setting ' . $name . ' to the configuration file: it contains a quote, a line break or another control character, or ends in a backslash. Put that value in configuration/config.ini by hand.');
            }
        }
    }

    /**
     * Creates configuration/config.ini with this text, only if the site has no configuration file:
     * false, with nothing written, when one exists (in either place the site reads) or appears
     * while this runs.
     *
     * @throws ConfigurationException when the file cannot be created or written
     */
    public function writeOnce(string $text): bool
    {
        if ($this->paths->existingConfigFiles() !== []) {
            return false;
        }
        $file = $this->paths->configFile();
        $handle = @fopen($file, 'x');
        if ($handle === false) {
            if (file_exists($file)) {
                return false;
            }
            throw new ConfigurationException('The configuration file could not be created: the web server cannot write to the folder configuration.');
        }
        $written = fwrite($handle, $text);
        fclose($handle);
        if ($written !== strlen($text)) {
            @unlink($file);
            throw new ConfigurationException('The configuration file could not be written: the disk may be full.');
        }

        return true;
    }
}
