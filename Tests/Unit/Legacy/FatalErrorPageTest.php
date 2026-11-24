<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use Idno\Legacy\FatalErrorPage;
use PHPUnit\Framework\TestCase;

final class FatalErrorPageTest extends TestCase
{
    public function testTheRedactorRedactsTheQueryWhenItIsAvailable(): void
    {
        $this->assertSame('/p?a=1&access_token=[redacted]', FatalErrorPage::requestAddress('/p?a=1&access_token=abc', true));
    }

    public function testWithoutTheRedactorEverythingAfterTheQuestionMarkIsDropped(): void
    {
        $this->assertSame('/p', FatalErrorPage::requestAddress('/p?a=1&access_token=abc#frag', false));
        $this->assertSame('/p', FatalErrorPage::requestAddress('/p', false));
        $this->assertSame('/p', FatalErrorPage::requestAddress('/p', true));
    }
}
