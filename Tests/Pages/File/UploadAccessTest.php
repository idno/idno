<?php

namespace Tests\Pages\File {

    use Idno\Core\Idno;

    /**
     * The two pages a script uploads a file to store a file only for a member who may write to the site.
     */
    class UploadAccessTest extends \Tests\IdnoTestCase
    {
        /** A 1x1 PNG. */
        private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

        /** @return array<string, array{class-string<\Idno\Common\Page>}> */
        public static function pages(): array
        {
            return ['upload' => [\Idno\Pages\File\Upload::class], 'picker' => [\Idno\Pages\File\Picker::class]];
        }

        /** @dataProvider pages */
        public function testAMemberWhoMayNotWriteStoresNoFile(string $class): void
        {
            $path = tempnam(sys_get_temp_dir(), 'idno-upload');
            file_put_contents($path, base64_decode(self::PNG));
            $files = $_FILES;
            $_FILES = ['file' => ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => $path, 'error' => 0, 'size' => filesize($path)]];
            $deny = static function (\Idno\Core\Event $event): void {
                $event->setResponse(false);
            };
            $previous = $this->swapUser($this->admin());
            Idno::site()->events()->addListener('canWrite/site', $deny);
            ob_start();
            try {
                (new $class())->post();
            } finally {
                $output = ob_get_clean();
                Idno::site()->events()->removeListener('canWrite/site', $deny);
                $this->swapUser($previous ?: null);
                $_FILES = $files;
                @unlink($path);
            }
            $this->assertSame('', trim((string) $output), 'nothing was stored: ' . $output);
        }
    }
}
