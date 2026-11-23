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
    'templates/default/content/syndication/account.html.twig' => [
        'reason' => 'the name of a syndication account is put in the `data-on` and `data-off` attributes, which the onboarding shell\'s toggle script (bootstrap2-toggle) inserts as HTML: the name is HTML-escaped and then attribute-escaped, so it stays text when the script inserts it; the old template escaped it once, so a name with a tag in it became markup. Names without `&`, `<`, `>` or a quote are as they were',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testAnAccountNameInASyndicationToggleStaysTextWhenTheToggleInsertsItAsHtml',
        'differences' => [
            '294fd4cf78e64184e8ad4d959637869129512e8002655844fcd2289d48397dfa',
            '218382e50a7728c235b8682f8f4ef43c286cb5c79615138a9f5e3077534560ca',
            '4449d1c559f65a60839d78c78d52e274888dd0bddb5ce43a0532b3ce08b2fd57',
        ],
    ],
    'templates/default/content/syndication/links.html.twig' => [
        'reason' => 'a link to where a post was syndicated is made from what a service returned: the address is linked only if it is http or https (a refused one is no link at all: its icon and identifier are text) and the identifier is text; the old template printed both into the markup as they were, so a `javascript:` address was a link and an identifier with a tag was markup',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testASyndicationLinkIsEscapedAndOnlyHttpIsLinked',
        'differences' => [
            'fc1d8056fc3a73dc71b12bc361b0ac3ff5037f101172cbbdfedff3f971a0e532',
            '045baeb2ff5a9b5fc32af671a785f9e5b0f24c610384b7d5002718639be19b60',
        ],
    ],
    'templates/default/entity/annotations/rsvps.html.twig' => [
        'reason' => 'an RSVP whose answer is none of yes, maybe and no (a webmention may carry any word) is listed under "Other responses"; the old template stopped with an undefined array key',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testAnRsvpWithAnAnswerThatIsNotYesMaybeOrNoIsListedAsOther',
        'differences' => [
            '4b1e85b34227b0356aae3ece62c7bedbcbc88cb729a645a314da69829eae47d4',
            'b9b04e15a44fbb7de8fbbc9621a523fb8f8fdd0fbabae7f95facec2969fa4353',
        ],
    ],
    'templates/default/entity/shell.html.twig' => [
        'reason' => 'the address a post replies to is the author\'s word or another site\'s (a webmention or a Micropub post names it): only an http or https address is linked, and one that is refused is printed as text, with no link (an empty `href` would be the post itself for a microformats consumer); the old template printed any address, `javascript:` included, in `href`',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testAnInReplyToAddressThatIsNotHttpIsNotLinked',
        'differences' => [
            '9c8ac3f8c8ddaec33d9fd409e4e298ea02cbff815b93e00894704f44ec902fb1',
        ],
    ],
    'templates/default/file/picker/donejs.html.twig' => [
        'reason' => 'the message that tells the editor which file was uploaded is JSON for a script (every `/`, `<`, `>`, `&`, `\'` and `"` an escape): the old template printed the address and the file\'s id between double quotes as they were. The same value, written differently, and it reads back as the address',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheFilePickersMessageIsWrittenForAScript',
        'differences' => [
            'c505c73966a4b763f0d3b65b02e2a51e362649ad99b12b79c8323778fd18bd96',
        ],
    ],
    'templates/default/file/picker/image.html.twig' => [
        'reason' => 'the words the picker puts into its script (`Choose a different image`, translated) are written for a script with the `e(\'js\')` escape, so that a translation with an apostrophe or a tag cannot end the string or the script; the old template printed the translation between single quotes as it was. The same JavaScript string value, written differently',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheImagePickersScriptWritesItsWordsForAScript',
        'differences' => [
            '66c5b0be73e71c5ff97e7fc647f463393227c3743352d73cc6facbcef44a2590',
        ],
    ],
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
