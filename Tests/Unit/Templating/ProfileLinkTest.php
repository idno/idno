<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\ProfileLink;
use PHPUnit\Framework\TestCase;

final class ProfileLinkTest extends TestCase
{
    public function testAHandleIsTheAddressOfItsAccountAndNothingElseIsChanged(): void
    {
        $this->assertSame('https://twitter.com/ada_l', ProfileLink::expandHandle('@ada_l'));
        $this->assertSame('https://example.org/@ada', ProfileLink::expandHandle('https://example.org/@ada'));
        $this->assertSame('', ProfileLink::expandHandle(''));
        $this->assertSame('@', ProfileLink::expandHandle('@'), 'an @ with no name is not a handle');
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function addresses(): array
    {
        return [
            'a website' => ['https://example.org/ada/', 'https://example.org/ada/', 'fa fa-link', 'u-url', 'example.org/ada'],
            'a host with an icon' => ['https://www.github.com/ada/', 'https://www.github.com/ada/', 'fa fa-github-square', 'u-url', 'ada'],
            'the last matching host wins' => ['https://play.google.com/x', 'https://play.google.com/x', 'fa fa-android', 'u-url', 'play.google.com/x'],
            'a handle, as the account (as typed, it has no path to show)' => ['@ada_l', 'https://twitter.com/ada_l', 'fa fa-twitter', 'u-url', ''],
            'mail' => ['mailto:ada@example.com', 'mailto:ada@example.com', 'fa fa-envelope', 'u-email', 'ada@example.com'],
            'a phone' => ['tel:+15555550100', 'tel:+15555550100', 'fa fa-phone', 'p-tel', '+15555550100'],
            'a sip address' => ['sip:ada@example.com', 'sip:ada@example.com', 'fa fa-phone', 'p-tel', 'ada@example.com'],
            'a text message' => ['sms:+1555', 'sms:+1555', 'fa fa-mobile', 'p-tel', '+1555'],
            'facetime' => ['facetime:ada@example.com', 'facetime:ada@example.com', 'fa fa-video', 'p-facetime', 'ada@example.com'],
            'a bare host gets a scheme' => ['example.org/bare', 'http://example.org/bare', 'fa fa-link', 'u-url', 'example.org/bare'],
            'a script is a bare word' => ['javascript:alert(1)', 'http://javascript:alert(1)', 'fa fa-link', 'u-url', 'javascript:alert(1)'],
            'tags in the text are removed' => ['https://example.org/<i>x</i>', 'https://example.org/<i>x</i>', 'fa fa-link', 'u-url', 'example.org/x'],
        ];
    }

    /**
     * @dataProvider addresses
     */
    public function testHowAnAddressIsDrawn(string $typed, string $fixed, string $icon, string $class, string $display): void
    {
        $link = ProfileLink::describe($typed, $fixed);

        $this->assertSame(['url' => $fixed, 'icon' => $icon, 'class' => $class, 'display' => $display], $link);
    }

    public function testAHostOfTheListShowsOnlyTheNameOfTheProfile(): void
    {
        $this->assertSame('ada', ProfileLink::describe('https://twitter.com/ada/', 'https://twitter.com/ada/')['display']);
        $this->assertSame('', ProfileLink::describe('https://twitter.com', 'https://twitter.com')['display'], 'no slash, no name');
        $this->assertSame('notmedium.com/x', ProfileLink::describe('https://notmedium.com/x', 'https://notmedium.com/x')['display'], 'the host must be the host, not part of it');
    }
}
