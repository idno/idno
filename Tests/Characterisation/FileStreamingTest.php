<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * A large stored file is streamed by the page that serves it: 64 MiB, whole and in a range, from a web server whose memory limit (32M) is smaller than the file
 * and whose PHP buffers all output (`output_buffering` on, which would hold every byte of a file that was echoed), arrives with the bytes the file holds,
 * and a HEAD of it says how long it is and reads nothing.
 */
final class FileStreamingTest extends PrivateSiteTestCase
{
    private const MEGABYTES = 64;

    private const ID = '00000000000040008000000000000f01';

    protected function siteIni(): array
    {
        return ['memory_limit' => '32M', 'output_buffering' => 'On'];
    }

    /** The piece of the file that is a megabyte: every one differs, so that a wrong offset shows in the hash. */
    private static function block(int $number): string
    {
        return substr(str_repeat('0123456789abcdef', 65536), 0, -4) . sprintf('%04d', $number);
    }

    private function storeLargeFile(): void
    {
        $code = <<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->config()->host = (string) parse_url($site->config()->getURL(), PHP_URL_HOST);
        $path = sys_get_temp_dir() . '/idno-large-' . bin2hex(random_bytes(6));
        $handle = fopen($path, 'wb');
        $piece = str_repeat('0123456789abcdef', 65536);
        for ($i = 0; $i < %MEGABYTES%; $i++) {
            fwrite($handle, substr($piece, 0, -4) . sprintf('%04d', $i));
        }
        fclose($handle);
        $stream = fopen($path, 'rb');
        \Idno\Core\Idno::container()->get(\Idno\Storage\Files\FileStore::class)->storeAs(%ID%, $stream, ['filename' => 'large.bin', 'mime_type' => 'video/mp4']);
        fclose($stream);
        unlink($path);

        return true;
        PHP;
        $this->assertTrue($this->runInSite(str_replace(['%MEGABYTES%', '%ID%'], [(string) self::MEGABYTES, $this->literal(self::ID)], $code)));
    }

    /**
     * The status, the headers and the SHA-256 and length of the body of a request, which is read a piece at a time and never held.
     *
     * @param array<string, string> $headers
     *
     * @return array{int, array<string, string>, string, int}
     */
    private function fetch(string $method, array $headers = []): array
    {
        $hash = hash_init('sha256');
        $length = 0;
        $received = [];
        $curl = curl_init(rtrim($this->baseUrl(), '/') . '/file/' . self::ID);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => array_map(static fn (string $name, string $value): string => $name . ': ' . $value, array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($hash, &$length): int {
                hash_update($hash, $chunk);
                $length += strlen($chunk);

                return strlen($chunk);
            },
        ]);
        $this->assertTrue(curl_exec($curl) !== false, 'the request was answered: ' . curl_error($curl));
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [$status, $received, hash_final($hash), $length];
    }

    public function testALargeFileIsStreamedWholeUnderASmallMemoryLimit(): void
    {
        $this->storeLargeFile();
        $expected = hash_init('sha256');
        for ($i = 0; $i < self::MEGABYTES; $i++) {
            hash_update($expected, self::block($i));
        }
        $size = self::MEGABYTES * 1048576;

        [$status, $headers, $hash, $length] = $this->fetch('GET');

        $this->assertSame(200, $status);
        $this->assertSame((string) $size, $headers['content-length']);
        $this->assertSame($size, $length);
        $this->assertSame(hash_final($expected), $hash);
    }

    public function testAnOpenEndedRangeOfALargeFileIsStreamedUnderASmallMemoryLimit(): void
    {
        $this->storeLargeFile();
        $start = 10 * 1048576 + 5;
        $expected = hash_init('sha256');
        hash_update($expected, substr(self::block(10), 5));
        for ($i = 11; $i < self::MEGABYTES; $i++) {
            hash_update($expected, self::block($i));
        }
        $size = self::MEGABYTES * 1048576;

        [$status, $headers, $hash, $length] = $this->fetch('GET', ['Range' => 'bytes=' . $start . '-']);

        $this->assertSame(206, $status);
        $this->assertSame($size - $start, $length);
        $this->assertSame((string) ($size - $start), $headers['content-length']);
        $this->assertSame('bytes ' . $start . '-' . ($size - 1) . '/' . $size, $headers['content-range']);
        $this->assertSame(hash_final($expected), $hash);
    }

    public function testAHeadOfALargeFileSaysHowLongItIs(): void
    {
        $this->storeLargeFile();

        [$status, $headers, , $length] = $this->fetch('HEAD');

        $this->assertSame(200, $status);
        $this->assertSame((string) (self::MEGABYTES * 1048576), $headers['content-length']);
        $this->assertSame(0, $length);
    }
}
