<?php

declare(strict_types=1);

/**
 * The Twig ports that draw something other than their `.tpl.php` original on purpose, keyed by the project-relative path of the
 * Twig file: the reason (a defect fixed, an escape added), the test, `Class::method`, that pins the new output, and the
 * `differences`: for each difference, `sha256(<normalised original> . "\0" . <normalised port>)`, which the failing test prints
 * for a difference that is not listed. An entry covers exactly the differences it lists, and no others in the file. The
 * equivalence test (Tests\Templating\TwigPortEquivalenceTest, by Tests\Templating\PortReportChecker) fails for an unlisted
 * difference, for a listed hash that no comparison produced (a stale exception), and for an entry whose test does not exist.
 *
 * @return array<string, array{reason: string, test: string, differences: list<string>}>
 */
return [
    'templates/default/js/idno.html.twig' => [
        'reason' => 'the environment script encodes `&`, `<`, `>`, `\'` and `"` of a value as \\u escapes (JSON_HEX_*), so that nothing in the page\'s address, which holds the visitor\'s words, can end the script or open a tag; the old template used json_encode() with no flags. The same JSON value, written differently',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheEnvironmentScriptIsEncodedForAScript',
        'differences' => [
            '647be562012032eec8e47c41e8b23d5b67c52834919330cb4c63ae687b4437fe',
        ],
    ],
    'templates/default/shell/identities.html.twig' => [
        'reason' => 'an identity (`rel="me"`) link is printed for an http(s) address and for a `mailto:`, `sms:` or `tel:` address with no white space or control character (RelMeAuth and IndieLogin use them); the old template printed whatever address a member had typed into `href`, so `javascript:`, `data:`, `vbscript:`, a bare word, and a `mailto:` with white space in it are no longer linked',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testAProfileAddressThatIsNotHttpMailSmsOrTelIsNotLinkedAsAnIdentity',
        'differences' => [
            '31e8c61e0d2c0d8d61da2b595b506d8cff5f42e92799b815746f5ca5c643694f',
        ],
    ],
];
