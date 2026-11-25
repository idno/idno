<?php

    /*
     * A mail from the Moderation plugin (see \IdnoPlugins\Moderation\Mailer), drawn with the
     * parts every mail of the site is written with (templates/email/parts), as HTML and as
     * text. 'mail': 'heading', 'paragraphs', 'quote', 'closing' (paragraphs after the quotation), 'link' ('url', 'label', 'button'),
     * 'preheader', 'reason'. Every one is plain text, some of it typed by a member, and is
     * escaped by the parts.
     */

    use Idno\Core\Email;

    $mail = $vars['mail'];

    Email::describe(['preheader' => (string) ($mail['preheader'] ?? ''), 'reason' => (string) ($mail['reason'] ?? '')]);

    echo Email::part($t, 'heading', ['text' => (string) $mail['heading']]);
foreach ($mail['paragraphs'] as $paragraph) {
    echo Email::part($t, 'paragraph', ['text' => (string) $paragraph]);
}
    echo Email::part($t, 'quote', ['text' => (string) $mail['quote']]);
foreach ((array) ($mail['closing'] ?? []) as $paragraph) {
    echo Email::part($t, 'paragraph', ['text' => (string) $paragraph]);
}
if (!empty($mail['link']['url'])) {
    echo Email::part($t, empty($mail['link']['button']) ? 'link' : 'button', ['label' => (string) $mail['link']['label'], 'url' => (string) $mail['link']['url']]);
}
