<?php

declare(strict_types=1);

namespace Idno\Markdown;

/**
 * The YAML front matter of a Markdown document: a block between two `---` lines, one `key: "value"` line a field.
 *
 * Every value is written as a YAML double-quoted scalar, whatever it holds, so a value can never be read as anything but a
 * string: not as a comment (`#`), a list item (`-`), a mapping (`a: b`), another key (a line break) or the end of the block.
 */
final class FrontMatter
{
    /**
     * @param array<string, string|list<string>|null> $fields in order; a null value and an empty list are left out, an
     *                                                        empty string is written
     */
    public static function render(array $fields): string
    {
        $lines = ['---'];
        foreach ($fields as $key => $value) {
            if (preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', (string) $key) !== 1) {
                throw new \InvalidArgumentException('A front matter key is letters, digits and underscores, starting with a letter.');
            }
            if ($value === null || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $lines[] = $key . ':';
                foreach ($value as $item) {
                    $lines[] = '  - ' . self::scalar($item);
                }
                continue;
            }
            $lines[] = $key . ': ' . self::scalar($value);
        }
        $lines[] = '---';

        return implode("\n", $lines) . "\n";
    }

    /**
     * A string as a YAML double-quoted scalar, on one line.
     */
    public static function scalar(string $value): string
    {
        // What is not UTF-8 cannot be written in a YAML stream: the bytes that are not are replaced
        $value = mb_scrub($value, 'UTF-8');

        $escaped = preg_replace_callback(
            '/[\\\\"\x{0}-\x{1F}\x{7F}-\x{9F}\x{2028}\x{2029}\x{FEFF}]/u',
            static function (array $match): string {
                return match ($match[0]) {
                    '\\' => '\\\\',
                    '"' => '\\"',
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\t" => '\\t',
                    "\0" => '\\0',
                    "\u{2028}" => '\\L',
                    "\u{2029}" => '\\P',
                    "\u{FEFF}" => '\\uFEFF',
                    default => sprintf('\\x%02X', mb_ord($match[0], 'UTF-8')),
                };
            },
            $value
        );

        return '"' . $escaped . '"';
    }
}
