<?php

namespace Tests\Core {

    use Idno\Core\Email;

    /**
     * The colours of mail (Email::PALETTE) are readable in each of the four ways a mail
     * client may show them: light; dark, where the client honours prefers-color-scheme; and
     * the light colours turned over by the client itself, all of them or only the light
     * backgrounds and the dark text. The last two are worked out here the way such clients
     * are described to do it, which is an approximation: no test opens a real mail client.
     */
    class MailDarkModeTest extends \Tests\IdnoTestCase
    {

        /// What WCAG AA asks of body text, and of the edge of a control
        const TEXT = 4.5;
        const EDGE = 3.0;

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

        /// Is a colour a light one, as a client that turns colours over would judge it?
        private static function isLight(string $hex): bool
        {
            return self::luminance($hex) > 0.18;
        }

        /// A colour turned over: the same hue, as light as it was dark
        private static function turnedOver(string $hex): string
        {
            list($r, $g, $b) = [hexdec(substr($hex, 1, 2)) / 255, hexdec(substr($hex, 3, 2)) / 255, hexdec(substr($hex, 5, 2)) / 255];
            $max = max($r, $g, $b);
            $min = min($r, $g, $b);
            $l = ($max + $min) / 2;
            $d = $max - $min;
            $s = $d == 0 ? 0 : $d / (1 - abs(2 * $l - 1));
            $h = 0;
            if ($d != 0) {
                $h = $max === $r ? fmod(($g - $b) / $d, 6) : ($max === $g ? ($b - $r) / $d + 2 : ($r - $g) / $d + 4);
            }
            $l = 1 - $l;
            $c = (1 - abs(2 * $l - 1)) * $s;
            $x = $c * (1 - abs(fmod($h, 2) - 1));
            $m = $l - $c / 2;
            $sector = (int) floor($h < 0 ? $h + 6 : $h) % 6;
            $rgb = [[$c, $x, 0], [$x, $c, 0], [0, $c, $x], [0, $x, $c], [$x, 0, $c], [$c, 0, $x]][$sector];

            return sprintf('#%02x%02x%02x', round(($rgb[0] + $m) * 255), round(($rgb[1] + $m) * 255), round(($rgb[2] + $m) * 255));
        }

        /**
         * The palette as a mail client shows it
         *
         * @param  string $how 'light', 'dark', 'all turned over' or 'partly turned over'
         * @return array role => property => colour
         */
        private static function shown(string $how): array
        {
            $shown = [];
            foreach (Email::PALETTE as $role => $properties) {
                foreach ($properties as $property => $colours) {
                    $colour = $colours[$how === 'dark' ? 1 : 0];
                    if ($how === 'all turned over') {
                        $colour = self::turnedOver($colour);
                    } elseif ($how === 'partly turned over') {
                        // Light backgrounds become dark and dark text light; the rest is left alone
                        if (($property === 'background-color' && self::isLight($colour)) || ($property === 'color' && !self::isLight($colour))) {
                            $colour = self::turnedOver($colour);
                        }
                    }
                    $shown[$role][$property] = $colour;
                }
            }
            if ($how === 'partly turned over') {
                // A label is turned over with the background it is set on, not on its own
                foreach ($shown as $role => $properties) {
                    if (isset($properties['color'], $properties['background-color']) && $properties['background-color'] === Email::PALETTE[$role]['background-color'][0]) {
                        $shown[$role]['color'] = Email::PALETTE[$role]['color'][0];
                    }
                }
            }

            return $shown;
        }

        public static function looks(): array
        {
            return ['light' => ['light'], 'dark' => ['dark'], 'all turned over' => ['all turned over'], 'partly turned over' => ['partly turned over']];
        }

        /**
         * @dataProvider looks
         */
        public function testEveryTextCanBeReadOnItsBackground($how)
        {
            $shown = self::shown($how);
            foreach (Email::READ_ON as $text => $backgrounds) {
                foreach ($backgrounds as $background) {
                    $contrast = self::contrast($shown[$text]['color'], $shown[$background]['background-color']);
                    $this->assertGreaterThanOrEqual(self::TEXT, $contrast, sprintf('%s: %s (%s) on %s (%s) is %.2f:1', $how, $text, $shown[$text]['color'], $background, $shown[$background]['background-color'], $contrast));
                }
            }
        }

        /**
         * @dataProvider looks
         */
        public function testTheButtonIsStillAButtonAndTheCardStillACard($how)
        {
            $shown = self::shown($how);
            $card = $shown['card']['background-color'];
            $button = max(self::contrast($shown['button']['background-color'], $card), self::contrast($shown['button']['border-color'], $card));
            $this->assertGreaterThanOrEqual(self::EDGE, $button, sprintf('%s: the button, by its background or its border, stands out from the card (%.2f:1)', $how, $button));
            // The hidden preheader is the colour of the page, whatever a client makes of display: none
            $this->assertSame(Email::PALETTE['bg']['background-color'], Email::PALETTE['preheader']['color']);
        }

        public function testEveryRoleHasADarkColourForEachPropertyAndNoneIsLeftToTheMailClient()
        {
            $css = Email::darkCss();
            foreach (Email::PALETTE as $role => $properties) {
                foreach ($properties as $property => $colours) {
                    $this->assertCount(2, $colours, $role . ' ' . $property . ' has a light and a dark colour');
                    foreach ($colours as $colour) {
                        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $colour);
                    }
                    $this->assertStringContainsString('.idno-mail-' . $role . ' {', $css);
                    $this->assertStringContainsString('[' . ($property === 'color' ? 'data-ogsc' : 'data-ogsb') . '] .idno-mail-' . $role . ' { ' . $property . ': ' . $colours[1] . ' !important; }', $css);
                }
                // Pure black or white is never a text or a background on its own: a role that
                // has one sets the other of the pair too, so both are turned over together
                foreach (['color', 'background-color'] as $property) {
                    if (in_array($properties[$property][0] ?? '', ['#000000', '#ffffff'], true) && $role !== 'card') {
                        $this->assertArrayHasKey('color', $properties, $role);
                        $this->assertArrayHasKey('background-color', $properties, $role);
                    }
                }
            }
            foreach (Email::READ_ON as $text => $backgrounds) {
                $this->assertArrayHasKey('color', Email::PALETTE[$text]);
                foreach ($backgrounds as $background) {
                    $this->assertArrayHasKey('background-color', Email::PALETTE[$background]);
                }
            }
            // The dark page and card are dark greys, not black: a client that turns colours
            // over a second time, or a screen at low brightness, still shows the card's edge
            $this->assertNotSame('#000000', Email::PALETTE['bg']['background-color'][1]);
            $this->assertGreaterThan(1.05, self::contrast(Email::PALETTE['card']['background-color'][1], Email::PALETTE['bg']['background-color'][1]));
        }
    }

}
