<?php

namespace Tests {

    use Idno\Core\Email;

    /**
     * What every mail the site sends must be, whichever mail it is: checks shared by the
     * tests of the core mails and of the plugins' mails.
     */
    trait MailAssertions
    {

        /**
         * The subject and the two bodies of a message that has been put together
         *
         * @return array 'subject', 'html', 'text'
         */
        protected function mailParts(Email $email): array
        {
            $text = null;
            foreach ($email->message->getChildren() as $child) {
                if (stripos((string) $child->getContentType(), 'text/plain') === 0) {
                    $text = (string) $child->getBody();
                }
            }

            return ['subject' => (string) $email->message->getSubject(), 'html' => (string) $email->message->getBody(), 'text' => $text, 'type' => (string) $email->message->getBodyContentType()];
        }

        /**
         * The HTML body as a mail client that knows nothing of Outlook's conditional
         * comments reads it: without them
         */
        protected function withoutComments(string $html): string
        {
            return (string) preg_replace('/<!--.*?-->/s', '', $html);
        }

        /**
         * The addresses of the links of an HTML body, as a reader's mail client follows them
         *
         * @return string[]
         */
        protected function mailLinks(string $html): array
        {
            preg_match_all('/\shref="([^"]*)"/', $html, $matches);

            return array_values(array_unique(array_map('html_entity_decode', $matches[1])));
        }

        /**
         * A mail has a subject, an HTML body in the shell and a text body, and is fit for a
         * mail client.
         *
         * @param array  $mail    From mailParts()
         * @param string $siteUrl The address the site is configured with
         * @param string $name    What the mail is, for the failure messages
         * @param string $verbatim Text the mail prints as it is (an error message), which is not wrapped
         */
        protected function assertWellMadeMail(array $mail, string $siteUrl, string $name, string $verbatim = ''): void
        {
            $html = $mail['html'];
            $text = $mail['text'];

            // Subject: one line, says something, does not shout
            $this->assertNotSame('', trim($mail['subject']), $name . ' has a subject');
            $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $mail['subject'], $name . ': the subject is one line');
            $this->assertStringNotContainsString('!', $mail['subject'], $name . ': no exclamation mark in the subject');
            $this->assertNotSame(mb_strtoupper($mail['subject']), $mail['subject'], $name . ': the subject is not in capitals');

            // Both bodies
            $this->assertSame('text/html', $mail['type'], $name . ' has an HTML body');
            $this->assertNotNull($text, $name . ' has a text body');
            $this->assertNotSame('', trim((string) $text), $name . ': the text body says something');

            // The shell
            $this->assertStringStartsWith('<!DOCTYPE html>', $html, $name . ' is drawn in the shell');
            $this->assertMatchesRegularExpression('/<html lang="[A-Za-z-]+" dir="(ltr|rtl)"/', $html, $name . ' names its language and direction');
            $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
            $this->assertStringContainsString('<meta name="supported-color-schemes" content="light dark">', $html);
            $this->assertStringContainsString('prefers-color-scheme: dark', $html, $name . ' has a dark variant');
            $this->assertStringContainsString('<title>' . htmlspecialchars($mail['subject']) . '</title>', $html, $name . ': the title is the subject');
            $this->assertMatchesRegularExpression('/<div style="display: none;[^"]*mso-hide: all;">[^<]{10,}/', $html, $name . ' has a preheader, hidden in the mail itself');
            $this->assertStringContainsString('You got this email because', $html, $name . ' says why the reader got it');
            $this->assertStringContainsString('You got this email because', (string) preg_replace('/\s+/', ' ', $text));

            // Layout tables only, each marked as one; no file to fetch, no script
            preg_match_all('/<table\b[^>]*>/', $html, $tables);
            $this->assertNotEmpty($tables[0]);
            foreach ($tables[0] as $table) {
                $this->assertStringContainsString('role="presentation"', $table, $name . ': a layout table is marked as presentation');
            }
            foreach (['<img', '<script', '<link', '<iframe', '<object', '<embed', '<form', '<video', '<audio', '<svg', 'url(', '@import', '@font-face', 'javascript:'] as $mark) {
                $this->assertStringNotContainsStringIgnoringCase($mark, $html, $name . ' must not hold "' . $mark . '"');
            }
            // No attribute that fetches or runs anything, in any element (Outlook's comments included)
            $this->assertDoesNotMatchRegularExpression('/<[^>]*\s(src|srcset|background|poster|on[a-z]+)\s*=/i', $html, $name . ' has nothing to fetch and no event handler');

            // Well formed: every element that was opened is closed, as a parser reads it and by count
            $document = new \DOMDocument();
            $before = libxml_use_internal_errors(true);
            libxml_clear_errors();
            $document->loadHTML('<?xml encoding="UTF-8">' . $this->withoutComments($html));
            $errors = array_filter(
                libxml_get_errors(), function ($error) {
                    return $error->level >= LIBXML_ERR_ERROR;
                }
            );
            libxml_clear_errors();
            libxml_use_internal_errors($before);
            $this->assertSame(
                [], array_values(
                    array_map(
                        function ($error) {
                            return trim($error->message) . ' (line ' . $error->line . ')';
                        }, $errors
                    )
                ), $name . ' is well-formed HTML'
            );
            foreach (['table', 'tr', 'td', 'p', 'a', 'h1', 'div', 'strong'] as $element) {
                $this->assertSame(
                    preg_match_all('/<' . $element . '\b/', $html), preg_match_all('/<\/' . $element . '>/', $html),
                    $name . ': every <' . $element . '> is closed'
                );
            }

            // Every link is an address on this site, as it is configured
            $links = $this->mailLinks($html);
            $this->assertNotEmpty($links, $name . ' links to the site');
            foreach ($links as $link) {
                $this->assertStringStartsWith($siteUrl, $link, $name . ': a link is on the configured address of the site');
                $this->assertStringContainsString($link, (string) $text, $name . ': the text body holds the same address');
                $this->assertMatchesRegularExpression('/^' . preg_quote($link, '/') . '$/m', (string) $text, $name . ': in the text body an address is on a line of its own');
            }

            // The text body: wrapped, except for a line that is one long word (an address)
            foreach (explode("\n", (string) $text) as $line) {
                if (strpos(trim($line), ' ') !== false && ($verbatim === '' || strpos($verbatim, trim($line)) === false)) {
                    $this->assertLessThanOrEqual(Email::TEXT_WIDTH, mb_strlen($line), $name . ': text is wrapped: "' . $line . '"');
                }
            }
            $this->assertStringContainsString("\n-- \n", (string) $text, $name . ': the text body ends with the footer');
            $this->assertStringEndsWith($siteUrl . "\n", (string) $text);
        }
    }

}
