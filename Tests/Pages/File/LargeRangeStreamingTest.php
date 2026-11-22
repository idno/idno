<?php

namespace Tests\Pages\File {

    /**
     * A range of a large stored file is written to the output a piece at a time: a player's usual
     * "Range: bytes=0-" on a big upload must not hold the range in PHP's memory. The range code
     * runs in a subprocess whose memory limit is smaller than the file.
     */
    class LargeRangeStreamingTest extends \PHPUnit\Framework\TestCase
    {
        const MEGABYTES = 64;

        private string $directory;
        private string $file;
        private string $fullHash;

        public function setUp(): void
        {
            $this->directory = sys_get_temp_dir() . '/idno-range-' . bin2hex(random_bytes(6));
            mkdir($this->directory);
            $this->file = $this->directory . '/large.bin';
            $piece      = str_repeat('0123456789abcdef', 65536);   // 1 MiB
            $handle     = fopen($this->file, 'wb');
            $hash       = hash_init('sha256');
            for ($i = 0; $i < self::MEGABYTES; $i++) {
                // Make every piece differ, so a wrong offset shows in the hash
                $block = substr($piece, 0, -4) . sprintf('%04d', $i);
                fwrite($handle, $block);
                hash_update($hash, $block);
            }
            fclose($handle);
            $this->fullHash = hash_final($hash);
        }

        public function tearDown(): void
        {
            @unlink($this->file);
            @rmdir($this->directory);
        }

        private function serve(int $start, int $end): array
        {
            $command = [PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'error_reporting=22527',
                __DIR__ . '/stream_large_range.php', $this->file, (string) $start, (string) $end];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            $code = proc_close($process);
            $this->assertSame(0, $code, "the range was not served under a 32M memory limit: $out $err");
            $result = json_decode($out, true);
            $this->assertIsArray($result, $out . $err);

            return $result;
        }

        public function testAnOpenEndedRangeOfALargeFileIsStreamed(): void
        {
            $size   = self::MEGABYTES * 1048576;
            $result = $this->serve(0, $size - 1);
            $this->assertSame($size, $result['count']);
            $this->assertSame($this->fullHash, $result['sha256']);
            $this->assertLessThan(32 * 1048576, $result['peak']);
        }

        public function testAMiddleRangeOfALargeFileIsStreamed(): void
        {
            $start  = 5 * 1048576 + 123;
            $end    = 50 * 1048576 + 456;
            $result = $this->serve($start, $end);
            $this->assertSame($end - $start + 1, $result['count']);
            $this->assertSame(
                hash('sha256', substr(file_get_contents($this->file), $start, $end - $start + 1)),
                $result['sha256']
            );
        }

        /**
         * @return array{0: string, 1: int, 2: array<string, int>} the first bytes, the byte count after "BEFORE", the report
         */
        private function serveBuffered(string $mode): array
        {
            $report  = $this->directory . '/report.json';
            $command = [PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'error_reporting=22527',
                __DIR__ . '/stream_buffered.php', $this->file, $mode, (string) (self::MEGABYTES * 1048576), $report];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $hash  = hash_init('sha256');
            $count = 0;
            $head  = '';
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($head === '') {
                    $head  = substr($chunk, 0, 6);
                    $chunk = substr($chunk, 6);
                }
                $count += strlen($chunk);
                hash_update($hash, $chunk);
            }
            $err  = stream_get_contents($pipes[2]);
            $code = proc_close($process);
            $this->assertSame(0, $code, "not served under a 32M memory limit with output buffers active: $err");
            $state = json_decode((string) file_get_contents($report), true);
            @unlink($report);
            $this->assertIsArray($state);

            return [$head, $count, $state + ['sha256' => hash_final($hash)]];
        }

        /**
         * @dataProvider modes
         */
        public function testALargeFileIsStreamedThroughNestedOutputBuffers(string $mode): void
        {
            [$head, $count, $state] = $this->serveBuffered($mode);

            $this->assertSame('BEFORE', $head, 'what was buffered before the file comes first');
            $this->assertSame(self::MEGABYTES * 1048576, $count);
            $this->assertSame($this->fullHash, $state['sha256']);
            $this->assertSame(0, $state['level'], 'every buffer was ended');
            $this->assertLessThan(32 * 1048576, $state['peak']);
        }

        /**
         * @return array<string, array{string}>
         */
        public static function modes(): array
        {
            return ['the whole file' => ['all'], 'a range' => ['range']];
        }
    }
}
