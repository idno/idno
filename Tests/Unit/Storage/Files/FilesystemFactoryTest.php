<?php

declare(strict_types=1);

namespace Tests\Unit\Storage\Files;

use AsyncAws\S3\S3Client;
use Idno\Config\LegacyConfigLoader;
use Idno\Storage\Files\FileStore;
use Idno\Storage\Files\FilesystemFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FilesystemFactoryTest extends TestCase
{
    private const ID = 'abcd0123456789abcdef0123456789ab';

    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if ($this->tmp !== null && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->tmp);
        }
    }

    /** @return resource */
    private function stream(string $bytes)
    {
        $s = fopen('php://memory', 'w+');
        fwrite($s, $bytes);
        rewind($s);

        return $s;
    }

    /**
     * @param array<string, string> $env
     * @return array<string, mixed>
     */
    private function configFor(array $env): array
    {
        return (new LegacyConfigLoader())->load('/nonexistent-idno-project', [], $env)->all();
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0: \ArrayObject<int, array{method: string, url: string}>, 1: FileStore}
     */
    private function s3Store(array $config): array
    {
        $requests = new \ArrayObject();
        $http     = new MockHttpClient(function (string $method, string $url) use ($requests) {
            $requests[] = ['method' => $method, 'url' => $url];

            return new MockResponse('', ['http_code' => 200]);
        });
        $s3 = new S3Client(['region' => $config['aws_region'] ?? 'us-east-1', 'accessKeyId' => 'k', 'accessKeySecret' => 's'], null, $http);
        $store = new FileStore(FilesystemFactory::fromConfig($config, $s3), 'example.com');

        return [$requests, $store];
    }

    public function testALocalUploadPathIsTheLocalAdapter(): void
    {
        $this->tmp = sys_get_temp_dir() . '/idno-fsfactory-' . bin2hex(random_bytes(6));
        mkdir($this->tmp);
        $config = ['uploadpath' => $this->tmp . '/'];

        $this->assertFalse(FilesystemFactory::isS3($config));
        $store = new FileStore(FilesystemFactory::fromConfig($config), 'example.com');
        $store->storeAs(self::ID, $this->stream('hi'), ['a' => 1]);

        $file = $this->tmp . '/example.com/a/b/c/d/' . self::ID . '.file';
        $this->assertSame('hi', file_get_contents($file));
        $this->assertSame('{"a":1}', file_get_contents($this->tmp . '/example.com/a/b/c/d/' . self::ID . '.data'));
    }

    public function testTheS3VariablesSelectTheS3Adapter(): void
    {
        $config = $this->configFor([
            'IDNO_AWS_S3_BUCKET'            => 'b',
            'IDNO_AWS_S3_PATH_PREFIX'       => 'up',
            'IDNO_AWS_S3_REGION'            => 'eu-west-1',
            'IDNO_AWS_S3_ACCESS_KEY_ID'     => 'key',
            'IDNO_AWS_S3_SECRET_ACCESS_KEY' => 'secret',
        ]);
        $this->assertTrue(FilesystemFactory::isS3($config));

        [$requests, $store] = $this->s3Store($config);
        $store->storeAs(self::ID, $this->stream('hi'), ['a' => 1]);

        $puts = array_values(array_filter($requests->getArrayCopy(), fn (array $r) => $r['method'] === 'PUT'));
        $this->assertNotEmpty($puts);
        $url = $puts[0]['url'];
        $this->assertStringContainsString('eu-west-1', $url);
        $this->assertStringContainsString('/up/example.com/a/b/c/d/' . self::ID . '.file', $url);
        $this->assertMatchesRegularExpression('#//(b\.s3\.|s3\.[^/]*/b/)#', $url);
    }

    public function testCloudcubeSelectsTheS3Adapter(): void
    {
        $config = $this->configFor([
            'CLOUDCUBE_URL'               => 'https://cloud-cube-eu.s3.amazonaws.com/abc',
            'CLOUDCUBE_ACCESS_KEY_ID'     => 'key',
            'CLOUDCUBE_SECRET_ACCESS_KEY' => 'secret',
        ]);
        $this->assertTrue(FilesystemFactory::isS3($config));
        $this->assertSame('eu-west-1', $config['aws_region']);

        [$requests, $store] = $this->s3Store($config);
        $store->storeAs(self::ID, $this->stream('hi'), []);

        $puts = array_values(array_filter($requests->getArrayCopy(), fn (array $r) => $r['method'] === 'PUT'));
        $this->assertNotEmpty($puts);
        $this->assertStringContainsString('cloud-cube-eu', $puts[0]['url']);
        $this->assertStringContainsString('/abc/example.com/a/b/c/d/' . self::ID . '.file', $puts[0]['url']);
    }

    public function testNoUploadPathThrows(): void
    {
        $this->expectException(\LogicException::class);
        FilesystemFactory::fromConfig([]);
    }

    public function testAnEmptyUploadPathThrows(): void
    {
        $this->expectException(\LogicException::class);
        FilesystemFactory::fromConfig(['uploadpath' => '/']);
    }

    public function testTheS3VariablesMapToTheClientOptions(): void
    {
        $config = $this->configFor([
            'IDNO_AWS_S3_BUCKET'            => 'b',
            'IDNO_AWS_S3_REGION'            => 'eu-west-1',
            'IDNO_AWS_S3_ACCESS_KEY_ID'     => 'key',
            'IDNO_AWS_S3_SECRET_ACCESS_KEY' => 'secret',
        ]);
        $this->assertSame(
            ['region' => 'eu-west-1', 'accessKeyId' => 'key', 'accessKeySecret' => 'secret'],
            FilesystemFactory::s3Options($config)
        );
    }

    public function testCloudcubeMapsToTheClientOptions(): void
    {
        $config = $this->configFor([
            'CLOUDCUBE_URL'               => 'https://cloud-cube-eu.s3.amazonaws.com/abc',
            'CLOUDCUBE_ACCESS_KEY_ID'     => 'ck',
            'CLOUDCUBE_SECRET_ACCESS_KEY' => 'cs',
        ]);
        $this->assertSame(
            ['region' => 'eu-west-1', 'accessKeyId' => 'ck', 'accessKeySecret' => 'cs'],
            FilesystemFactory::s3Options($config)
        );
    }

    public function testNoCredentialsInTheConfigLeavesTheSdkDefaultChain(): void
    {
        $config = $this->configFor(['IDNO_AWS_S3_BUCKET' => 'b', 'IDNO_AWS_S3_REGION' => 'eu-west-1']);
        $this->assertSame(['region' => 'eu-west-1'], FilesystemFactory::s3Options($config));
        $this->assertSame([], FilesystemFactory::s3Options(['aws_key' => 'k']));
    }
}
