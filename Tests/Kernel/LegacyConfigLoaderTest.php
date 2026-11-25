<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Config\LegacyConfigLoader;
use PHPUnit\Framework\TestCase;

/**
 * Pins the precedence of the former Config::loadIniFiles().
 */
final class LegacyConfigLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    private function project(array $files = []): string
    {
        $dir = sys_get_temp_dir() . '/idno-config-test-' . bin2hex(random_bytes(6));
        mkdir($dir . '/configuration', 0777, true);
        $this->dirs[] = $dir;
        foreach ($files as $relative => $contents) {
            file_put_contents($dir . '/' . $relative, $contents);
        }
        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/{,configuration/}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($dir . '/configuration');
            @rmdir($dir);
        }
        $this->dirs = [];
    }

    private function load(string $dir, array $server = [], array $env = []): \Idno\Config\BootConfig
    {
        return (new LegacyConfigLoader())->load($dir, $server, $env);
    }

    public function testNoFilesIsDefault(): void
    {
        $this->assertTrue($this->load($this->project())->isDefault());
    }

    public function testReadsConfigurationDirectory(): void
    {
        $dir = $this->project(['configuration/config.ini' => "dbname = 'a'\n"]);
        $config = $this->load($dir);
        $this->assertSame('a', $config->get('dbname'));
        $this->assertFalse($config->isDefault());
    }

    public function testRootIniWinsOverConfigurationDirectory(): void
    {
        $dir = $this->project([
            'config.ini'               => "dbname = 'root'\n",
            'configuration/config.ini' => "dbname = 'conf'\n",
        ]);
        $this->assertSame('root', $this->load($dir)->get('dbname'));
    }

    public function testJsonOverridesIni(): void
    {
        $dir = $this->project([
            'configuration/config.ini'  => "dbname = 'a'\n",
            'configuration/config.json' => '{"dbname":"json"}',
        ]);
        $this->assertSame('json', $this->load($dir)->get('dbname'));
    }

    public function testIdnoEnvOverridesFiles(): void
    {
        $dir = $this->project(['configuration/config.ini' => "dbname = 'a'\n"]);
        $this->assertSame('env', $this->load($dir, ['IDNO_DBNAME' => 'env'])->get('dbname'));
    }

    public function testKnownEnvDoesNotOverrideIdnoEnv(): void
    {
        $config = $this->load($this->project(), ['IDNO_DBNAME' => 'i', 'KNOWN_DBNAME' => 'k']);
        $this->assertSame('i', $config->get('dbname'));
    }

    public function testKnownEnvUsedWhenNothingElseSet(): void
    {
        $this->assertSame('k', $this->load($this->project(), ['KNOWN_DBNAME' => 'k'])->get('dbname'));
    }

    public function testDatabaseUrlIsParsed(): void
    {
        $config = $this->load($this->project(), [], ['IDNO_DATABASE_URL' => 'mysql://u:p@h:3307/db']);
        $this->assertSame('mysql', $config->get('database'));
        $this->assertSame('db', $config->get('dbname'));
        $this->assertSame('u', $config->get('dbuser'));
        $this->assertSame('p', $config->get('dbpass'));
        $this->assertSame('h', $config->get('dbhost'));
        $this->assertSame(3307, $config->get('dbport'));
    }

    public function testAMysqlUrlIsReadAsBefore(): void
    {
        $config = $this->load($this->project(), [], ['KNOWN_DATABASE_URL' => 'mysql://u:p@h:3307/some/db']);
        $this->assertSame('mysql', $config->get('database'));
        $this->assertSame('db', $config->get('dbname'));
        $this->assertSame('u', $config->get('dbuser'));
        $this->assertSame('p', $config->get('dbpass'));
        $this->assertSame('h', $config->get('dbhost'));
        $this->assertSame(3307, $config->get('dbport'));
    }

    public function testASqliteUrlWithThreeSlashesIsRelative(): void
    {
        $config = $this->load($this->project(), [], ['IDNO_DATABASE_URL' => 'sqlite:///data/idno.sqlite']);
        $this->assertSame('sqlite', $config->get('database'));
        $this->assertSame('data/idno.sqlite', $config->get('dbname'));
        $this->assertNull($config->get('dbhost'));
    }

    public function testASqliteUrlWithFourSlashesIsAbsolute(): void
    {
        $config = $this->load($this->project(), [], ['IDNO_DATABASE_URL' => 'sqlite:////var/lib/idno.sqlite']);
        $this->assertSame('sqlite', $config->get('database'));
        $this->assertSame('/var/lib/idno.sqlite', $config->get('dbname'));
    }

    public function testASqliteMemoryUrl(): void
    {
        $config = $this->load($this->project(), [], ['IDNO_DATABASE_URL' => 'sqlite:///:memory:']);
        $this->assertSame('sqlite', $config->get('database'));
        $this->assertSame(':memory:', $config->get('dbname'));
    }

    public function testS3EnvMapsToUploadPath(): void
    {
        $config = $this->load($this->project(), [], [
            'IDNO_AWS_S3_BUCKET'            => 'b',
            'IDNO_AWS_S3_PATH_PREFIX'       => 'up',
            'IDNO_AWS_S3_ACCESS_KEY_ID'     => 'key',
            'IDNO_AWS_S3_SECRET_ACCESS_KEY' => 'secret',
            'IDNO_AWS_S3_REGION'            => 'eu-west-1',
        ]);
        $this->assertSame('s3://b/up/', $config->get('uploadpath'));
        $this->assertSame('local', $config->get('filesystem'));
        $this->assertSame('b', $config->get('aws_bucket'));
        $this->assertSame('key', $config->get('aws_key'));
        $this->assertSame('secret', $config->get('aws_secret'));
        $this->assertSame('eu-west-1', $config->get('aws_region'));
    }

    public function testUploadPathEnvOverridesS3AndFiles(): void
    {
        $dir = $this->project(['configuration/config.ini' => "uploadpath = '/from/ini'\n"]);
        $config = $this->load($dir, [], ['IDNO_UPLOAD_PATH' => '/from/env']);
        $this->assertSame('/from/env/', $config->get('uploadpath'));
    }

    public function testEnvironmentSpecialsBeatJsonFromSameDirectory(): void
    {
        $dir = $this->project(['configuration/config.json' => '{"dbname":"json"}']);
        $config = $this->load($dir, [], ['IDNO_DATABASE_URL' => 'mysql://u:p@h/fromurl']);
        $this->assertSame('fromurl', $config->get('dbname'));
        $this->assertSame('json', $this->load($dir)->get('dbname'));
    }

    public function testADatabaseNamedByTheEnvironmentAloneIsAnInstalledSite(): void
    {
        // A container is configured by its environment, with no configuration file.
        $this->assertFalse($this->load($this->project(), [], ['IDNO_DATABASE_URL' => 'mysql://u:p@h/db'])->isDefault());
        $this->assertFalse($this->load($this->project(), ['IDNO_DATABASE' => 'sqlite', 'IDNO_DBNAME' => 'data/idno.sqlite'])->isDefault());
        $this->assertFalse($this->load($this->project(), ['KNOWN_DATABASE' => 'mysql'])->isDefault());
    }

    public function testAnEnvironmentThatNamesNoDatabaseIsNotAnInstalledSite(): void
    {
        $this->assertTrue($this->load($this->project())->isDefault());
        $this->assertTrue($this->load($this->project(), ['IDNO_DOMAIN' => 'example.com', 'IDNO_PORT' => '80', 'IDNO_DBNAME' => 'idno', 'IDNO_DATABASE' => ' '])->isDefault());
        $this->assertTrue($this->load($this->project(), ['IDNO_DATABASE' => ['x']])->isDefault());
    }

    public function testIniBeatsKnownEnv(): void
    {
        $dir = $this->project(['configuration/config.ini' => "dbname = 'a'\n"]);
        $this->assertSame('a', $this->load($dir, ['KNOWN_DBNAME' => 'k'])->get('dbname'));
    }

    public function testEnvironmentSpecialsBeatKnownEnv(): void
    {
        $config = $this->load($this->project(), ['KNOWN_DBNAME' => 'k'], ['IDNO_DATABASE_URL' => 'mysql://u:p@h/fromurl']);
        $this->assertSame('fromurl', $config->get('dbname'));
    }

    public function testIdnoEnvBeatsEnvironmentSpecials(): void
    {
        $config = $this->load($this->project(), ['IDNO_DBNAME' => 'fromidno'], ['IDNO_DATABASE_URL' => 'mysql://u:p@h/fromurl']);
        $this->assertSame('fromidno', $config->get('dbname'));
    }

    public function testIdnoDatabaseUrlBeatsKnownDatabaseUrl(): void
    {
        $config = $this->load($this->project(), [], [
            'IDNO_DATABASE_URL'  => 'mysql://u:p@h/idnodb',
            'KNOWN_DATABASE_URL' => 'mysql://u:p@h/knowndb',
        ]);
        $this->assertSame('idnodb', $config->get('dbname'));
    }

    public function testUploadPathEnvBeatsS3UploadPath(): void
    {
        $config = $this->load($this->project(), [], [
            'IDNO_AWS_S3_BUCKET' => 'b',
            'IDNO_UPLOAD_PATH'   => '/explicit',
        ]);
        $this->assertSame('/explicit/', $config->get('uploadpath'));
    }

    public function testConfigurationJsonBeatsRootJson(): void
    {
        $dir = $this->project([
            'config.json'               => '{"dbname":"rootjson"}',
            'configuration/config.json' => '{"dbname":"confjson"}',
        ]);
        $this->assertSame('confjson', $this->load($dir)->get('dbname'));
    }

    public function testRootJsonBeatsRootIni(): void
    {
        $dir = $this->project([
            'config.ini'  => "dbname = 'rootini'\n",
            'config.json' => '{"dbname":"rootjson"}',
        ]);
        $this->assertSame('rootjson', $this->load($dir)->get('dbname'));
    }

    public function testRootJsonBeatsConfigurationIni(): void
    {
        $dir = $this->project([
            'config.json'              => '{"dbname":"rootjson"}',
            'configuration/config.ini' => "dbname = 'confini'\n",
        ]);
        $this->assertSame('rootjson', $this->load($dir)->get('dbname'));
    }

    public function testNonStringUploadPathFromJsonIsCoerced(): void
    {
        $dir = $this->project(['configuration/config.json' => '{"uploadpath":5}']);
        $this->assertSame('5/', $this->load($dir)->get('uploadpath'));
    }

    public function testBooleanUploadPathFromJsonIsCoerced(): void
    {
        $dir = $this->project(['configuration/config.json' => '{"uploadpath":true}']);
        $this->assertSame('1/', $this->load($dir)->get('uploadpath'));
    }

    public function testUploadPathGetsTrailingSlash(): void
    {
        $dir = $this->project(['configuration/config.ini' => "uploadpath = '/data/up'\n"]);
        $this->assertSame('/data/up/', $this->load($dir)->get('uploadpath'));
    }

    public function testPathAndHostCannotBeSet(): void
    {
        $dir = $this->project(['configuration/config.ini' => "path = '/x'\nhost = 'h'\n"]);
        $config = $this->load($dir, ['IDNO_PATH' => '/y', 'IDNO_HOST' => 'z']);
        $this->assertFalse($config->has('path'));
        $this->assertFalse($config->has('host'));
    }

    public function testArraysMerge(): void
    {
        $dir = $this->project(['configuration/config.ini' => "alwaysplugins[] = 'A'\nantiplugins[] = 'B'\n"]);
        $config = $this->load($dir);
        $this->assertSame(['A'], $config->get('alwaysplugins'));
        $this->assertSame(['B'], $config->get('antiplugins'));
    }

    public function testDomainAndPortEnvBecomeConfigKeys(): void
    {
        $config = $this->load($this->project(), ['IDNO_DOMAIN' => 'example.com', 'IDNO_PORT' => '8089']);
        $this->assertSame('example.com', $config->get('domain'));
        $this->assertSame('8089', $config->get('port'));
    }

    public function testPerHostIniIsIgnored(): void
    {
        $dir = $this->project(['configuration/example.com.ini' => "perhostkey = 'yes'\n"]);
        $config = $this->load($dir, ['HTTP_HOST' => 'example.com', 'SERVER_NAME' => 'example.com']);
        $this->assertFalse($config->has('perhostkey'));
        $this->assertTrue($config->isDefault());
    }
}
