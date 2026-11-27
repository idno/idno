<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use Idno\Mail\MailDesign;
use PHPUnit\Framework\TestCase;

final class MailDesignTest extends TestCase
{
    private static function luminance(string $hex): float
    {
        $channels = [];
        foreach ([1, 3, 5] as $at) {
            $c = hexdec(substr($hex, $at, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function looks(): iterable
    {
        yield 'light' => [0];
        yield 'dark' => [1];
    }

    /**
     * A mail whose text cannot be read on its background in dark mode (or in light) is a mail nobody can read: every text role of the
     * palette, on the background it is drawn on, has the contrast WCAG AA asks of body text, and the button stands out from the card.
     *
     * @dataProvider looks
     */
    public function testEveryTextOfTheMailCanBeReadOnItsBackgroundInLightAndInDark(int $look): void
    {
        foreach (MailDesign::READ_ON as $text => $backgrounds) {
            foreach ($backgrounds as $background) {
                $ink = MailDesign::PALETTE[$text]['color'][$look];
                $paper = MailDesign::PALETTE[$background]['background-color'][$look];
                $this->assertGreaterThanOrEqual(4.5, self::contrast($ink, $paper), sprintf('%s (%s) on %s (%s)', $text, $ink, $background, $paper));
            }
        }
        $card = MailDesign::PALETTE['card']['background-color'][$look];
        $button = max(
            self::contrast(MailDesign::PALETTE['button']['background-color'][$look], $card),
            self::contrast(MailDesign::PALETTE['button']['border-color'][$look], $card)
        );
        $this->assertGreaterThanOrEqual(3.0, $button, 'the button stands out from the card');
    }
}
