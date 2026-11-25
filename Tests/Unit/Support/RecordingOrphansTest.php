<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingOrphans;

final class RecordingOrphansTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/idno-orphans-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/answers/used', 0775, true);
        mkdir($this->root . '/answers/dead', 0775, true);
        mkdir($this->root . '/tests', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->root . '/*/*/*') ?: [], glob($this->root . '/*/*') ?: []) as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        foreach (glob($this->root . '/*') ?: [] as $path) {
            rmdir($path);
        }
        rmdir($this->root);
    }

    public function testAGroupNoUsingClassNamesAndAFileNoCaseNamesAreFound(): void
    {
        file_put_contents($this->root . '/answers/used/kept.txt', 'x');
        file_put_contents($this->root . '/answers/used/stale.txt', 'x');
        file_put_contents($this->root . '/answers/dead/any.txt', 'x');
        file_put_contents($this->root . '/tests/UsingTest.php', "<?php\nuse Tests\\Support\\RecordedAnswers;\nclass UsingTest { use RecordedAnswers; function t() { \$this->assertRecordedAnswer('used', 'kept', \$r); } }\n");
        file_put_contents($this->root . '/tests/OtherTest.php', "<?php\n// 'dead' and 'stale' are named here, but this class records nothing\n");

        $this->assertSame(['dead/', 'used/stale.txt'], RecordingOrphans::find($this->root . '/answers', $this->root . '/tests'));
    }

    public function testEveryRecordingOfTheRepositoryIsNamedByAUsingClass(): void
    {
        $this->assertSame([], RecordingOrphans::find(dirname(__DIR__, 2) . '/Characterisation/fixtures/answers', dirname(__DIR__, 2)));
    }
}
