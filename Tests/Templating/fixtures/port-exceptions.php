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
        'reason' => 'a profile address that is not http or https (`javascript:`, `data:`, a bare word) is not linked as an identity: the old template printed a `rel="me"` link for any address a member had typed, with `href` set to it; an address that came from a member goes through http_url',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testAProfileAddressThatIsNotHttpIsNotLinkedAsAnIdentity',
        'differences' => [
            '800441b1acae971682179c5b4d5af065640c39576e302562b11bea7d1abb2613',
        ],
    ],
];
