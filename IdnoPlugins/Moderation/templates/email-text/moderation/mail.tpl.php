<?php

    /* The text part of a mail from the Moderation plugin. It is not HTML and is not escaped. */

    $mail = $vars['mail'];

    echo $mail['heading'] . "\n" . str_repeat('=', mb_strlen((string) $mail['heading'])) . "\n\n";

foreach ($mail['paragraphs'] as $paragraph) {
    echo $paragraph . "\n\n";
}
if ((string) $mail['quote'] !== '') {
    echo '> ' . str_replace("\n", "\n> ", (string) $mail['quote']) . "\n\n";
}
if (!empty($mail['link']['url'])) {
    echo $mail['link']['label'] . ': ' . $mail['link']['url'] . "\n";
}
