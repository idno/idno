<?php

declare(strict_types=1);

namespace Idno\Mail;

/**
 * What every mail the site sends is made of, apart from the words: its colours, its text helpers and what a
 * mail's template says about the mail it is (the line a mail client shows beside the subject, and why the
 * reader got it). Pure: nothing here reads the site, so the Twig templates of `email` and `email-text` reach it
 * through functions of the template extension and a unit test needs nothing.
 *
 * A mail client shows a mail in one of four ways: with the colours it has, with the dark ones (it honours
 * prefers-color-scheme), with every colour turned over, or with only the light backgrounds and dark text turned
 * over. All four must be readable, so no colour is pure black or white on its own (a label and its background are
 * set together, on the same element), every text sits on a background this palette names, and the button has an
 * edge that is still there when its background meets a card that has turned dark.
 */
final class MailDesign
{
    /**
     * The colours of a mail by what they are for: [light, dark]. An element is given its light colours inline and
     * the class of its role (`idno-mail-<role>`); the shell writes the dark colours of every role into its style
     * block (darkCss()).
     */
    public const PALETTE = [
        'bg'        => ['background-color' => ['#f3f4f6', '#121214']],
        'card'      => ['background-color' => ['#ffffff', '#1d1d20'], 'border-color' => ['#e5e7eb', '#3a3a40']],
        'strong'    => ['color' => ['#111111', '#f4f4f5']],
        'text'      => ['color' => ['#374151', '#d4d4d8']],
        'muted'     => ['color' => ['#6b7280', '#a1a1aa']],
        'footer'    => ['color' => ['#4b5563', '#a1a1aa']],
        // Darker than the page's own link blue, which turned over is too dim on a card turned black
        'link'      => ['color' => ['#1e40af', '#93b4fd']],
        'button'    => ['background-color' => ['#111111', '#f4f4f5'], 'border-color' => ['#6b7280', '#f4f4f5'], 'color' => ['#ffffff', '#111111']],
        'box'       => ['background-color' => ['#f9fafb', '#27272b'], 'border-color' => ['#d1d5db', '#4b4b52'], 'color' => ['#374151', '#e4e4e7']],
        'rule'      => ['border-color' => ['#e5e7eb', '#3a3a40']],
        'preheader' => ['color' => ['#f3f4f6', '#121214']],
    ];

    /** Which text is read on which background, by role: what must have enough contrast in the light look and in the dark one */
    public const READ_ON = [
        'strong' => ['bg', 'card'], 'text' => ['card'], 'muted' => ['card'], 'footer' => ['bg'],
        'link' => ['card'], 'button' => ['button'], 'box' => ['box'],
    ];

    /** The typefaces of a mail: the ones the site's look uses, as far as mail clients have them */
    public const FONT = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

    /** The typefaces of preformatted text in a mail */
    public const FONT_MONO = "ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace";

    /** The width plain-text mail is wrapped to */
    public const TEXT_WIDTH = 72;

    /** @var array<string, string> what the template being drawn said about its mail (see describe()) */
    private static array $described = [];

    /**
     * A colour of the palette for an inline style: the light one.
     *
     * @param string $property 'color', 'background-color' or 'border-color'
     */
    public static function colour(string $role, string $property = 'color'): string
    {
        return self::PALETTE[$role][$property][0] ?? '';
    }

    /**
     * The style rules that give every role its dark colours: for mail clients that honour prefers-color-scheme, and
     * for Outlook.com and the Outlook apps, which rewrite a mail's colours themselves and mark the elements they
     * changed (data-ogsc for text, data-ogsb for backgrounds), so that there too the mail has the colours chosen here.
     */
    public static function darkCss(): string
    {
        $media = '';
        $outlook = '';
        foreach (self::PALETTE as $role => $properties) {
            $rules = '';
            foreach ($properties as $property => $colours) {
                $rules .= ' ' . $property . ': ' . $colours[1] . ' !important;';
                $hook = $property === 'color' ? 'data-ogsc' : 'data-ogsb';
                $outlook .= '    [' . $hook . '] .idno-mail-' . $role . ' { ' . $property . ': ' . $colours[1] . " !important; }\n";
            }
            $media .= '        .idno-mail-' . $role . ' {' . $rules . " }\n";
        }

        return "    @media (prefers-color-scheme: dark) {\n" . $media . "    }\n" . $outlook;
    }

    /**
     * Text for a mail header or a line of a mail: one line, no control characters, so nothing a person typed can start a
     * header of its own or look like a line of the mail's.
     */
    public static function headerText(mixed $text): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($text) ? (string) $text : '') ?? '');
    }

    /** A value as one line of plain text: no line breaks, runs of white space made one space */
    public static function oneLine(mixed $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', self::headerText($text)) ?? '');
    }

    /**
     * Put values into a sentence, in HTML: the sentence and the values are escaped, and the values are set in bold.
     *
     * @param array<array-key, mixed> $values one for each %s of the sentence
     */
    public static function emphasise(mixed $format, array $values): string
    {
        $values = array_map(static fn ($value): string => '<strong>' . htmlspecialchars(self::oneLine($value)) . '</strong>', array_values($values));

        return self::fill(htmlspecialchars(is_scalar($format) ? (string) $format : '', ENT_QUOTES), $values);
    }

    /**
     * Put values into a sentence, in plain text: they go in as they are, each on one line.
     *
     * @param array<array-key, mixed> $values one for each %s of the sentence
     */
    public static function sentence(mixed $format, array $values): string
    {
        return self::fill(is_scalar($format) ? (string) $format : '', array_map(self::oneLine(...), array_values($values)));
    }

    /**
     * The values of a sentence, in order: a sentence with more %s than values keeps the extra ones as they are (a
     * translation that is wrong must not make the mail fail).
     *
     * @param list<string> $values
     */
    private static function fill(string $format, array $values): string
    {
        $i = 0;

        return (string) preg_replace_callback('/%(\d+\$)?s|%%/', static function (array $match) use (&$i, $values): string {
            if ($match[0] === '%%') {
                return '%';
            }
            $at = $match[1] !== '' ? ((int) $match[1]) - 1 : $i++;

            return $values[$at] ?? $match[0];
        }, $format);
    }

    /**
     * Wrap plain text for a mail. Lines are counted in characters, not bytes, and a word longer than a line (an
     * address) is left whole on a line of its own.
     *
     * @param string $prefix put before every line ("> " for a quotation)
     */
    public static function wrapText(mixed $text, int $width = self::TEXT_WIDTH, string $prefix = ''): string
    {
        $width -= mb_strlen($prefix);
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', is_scalar($text) ? (string) $text : '') ?: [''] as $paragraph) {
            $line = '';
            foreach (preg_split('/[ \t]+/', trim($paragraph)) ?: [''] as $word) {
                if ($line !== '' && mb_strlen($line) + 1 + mb_strlen($word) > $width) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $line === '' ? $word : $line . ' ' . $word;
                }
            }
            $lines[] = $line;
        }

        return implode("\n", array_map(static fn (string $line): string => rtrim($prefix . $line), $lines));
    }

    /** A line of `=` as long as a heading, for the plain-text form of it */
    public static function rule(mixed $heading): string
    {
        return str_repeat('=', min(mb_strlen(self::oneLine($heading)), self::TEXT_WIDTH));
    }

    /**
     * Called by a mail's HTML template to say what the shell should know about the mail.
     *
     * @param array<string, mixed> $about 'subject'; 'preheader': a line a mail client shows beside the subject in its list
     *                                    and the mail itself hides; 'reason': why the reader got the mail, for the footer
     */
    public static function describe(array $about): void
    {
        foreach (['subject', 'preheader', 'reason'] as $key) {
            if (isset($about[$key]) && !isset(self::$described[$key])) {
                self::$described[$key] = self::oneLine($about[$key]);
            }
        }
    }

    /** Forget what was described: a mail is about to be drawn */
    public static function beginDescribing(): void
    {
        self::$described = [];
    }

    /**
     * What the template said and forgets it.
     *
     * @return array{subject: string, preheader: string, reason: string}
     */
    public static function takeDescription(): array
    {
        $about = self::$described + ['subject' => '', 'preheader' => '', 'reason' => ''];
        self::$described = [];

        return $about;
    }
}
