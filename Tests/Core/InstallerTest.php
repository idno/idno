<?php

declare(strict_types=1);

namespace Tests\Core;

use Idno\Core\Installer;
use PHPUnit\Framework\TestCase;

/**
 * The installers' PHP version check must agree with index.php and composer.json: PHP 8.4.
 */
final class InstallerTest extends TestCase
{
    public function testPhpVersionCheckRequires84(): void
    {
        $this->assertSame('fail', Installer::checkPHPVersion('8.1.0'));
        $this->assertSame('fail', Installer::checkPHPVersion('8.3.99'));
        $this->assertSame('ok', Installer::checkPHPVersion('8.4.0'));
        $this->assertSame('ok', Installer::checkPHPVersion('8.5.1'));
    }

    public function testPhpVersionCheckDefaultsToTheRunningVersion(): void
    {
        $this->assertSame('ok', Installer::checkPHPVersion());
    }
}
