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
            '39a9c95177c4d4465d959e42bec31537ba4a4f52ff034f95f1217d02e673713d',
            '46d2fe53eb688dfa4b7bddd0fc7ad2c8c1e4d64fe9241522d06535536f3dfd1c',
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
    'templates/default/forms/action.html.twig' => [
        'reason' => 'the address a form posts to is printed escaped; the old template printed it into the attribute as it was, so an address with a quote ended the attribute',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testFormValuesAreEscaped',
        'differences' => [
            '2db57aa5333bed98c6793551eb0ed1e4145b8402bb8b643b5084d9e2b9422504',
        ],
    ],
    'templates/default/forms/input/form-list.html.twig' => [
        'reason' => 'the label and the help text of a field are printed as text (escaped), as every value of a control is; the old template printed them as they were. A field that is not required has no `required` attribute (the old template inherited the list of required fields as the attribute\'s value, which PHP wrote as "Array" and reported as an error)',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testFormValuesAreEscaped',
        'differences' => [
            '1e5344e2d739ff833591ef5626612173e63374f2152ab474f43cf394b2868900',
        ],
    ],
    'templates/default/forms/input/input.html.twig' => [
        'reason' => 'every attribute of the control (its name, id, class, type, pattern and the script attributes) is printed escaped; the old template printed them as they were, so a value with a quote ended the attribute. Non-ASCII text in a value, a placeholder or an alternative text is printed as the characters themselves, where `htmlentities()` wrote a named entity for each',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testFormValuesAreEscaped',
        'differences' => [
            '013db917e42799a943d248eb539100daffa40007eb51a2bf999b8e090ff8d98a',
            'ada058f342e586b3e2a1bf471457c720d62fe85144417de8ce3f987068552305',
        ],
    ],
    'templates/default/forms/input/select.html.twig' => [
        'reason' => 'the name, id, class, script attributes and the value of each option of the select are printed escaped; the old template printed them as they were, so a value with a quote ended the attribute',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testFormValuesAreEscaped',
        'differences' => [
            '0afe13dffec5df3df650357a68ee96eb202b6834f87b256d013479a917c0e305',
            '9a918829b2e09f98a9e5205e99fadc1a6becd8c4d7f81c292c03362e0b471b3a',
        ],
    ],
    'templates/default/forms/input/longtext.html.twig' => [
        'reason' => 'the name, the class, the id and the height of the text area are printed escaped; the old template printed them as they were, so a value with a quote ended the attribute (the placeholder and the text were already escaped)',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testFormValuesAreEscaped',
        'differences' => [
            '5b3f0aa35cd6690a382e401a86aaf8538f87d1073805186ba7ce76bdeef3cc02',
        ],
    ],
    'templates/default/forms/input/richtext.html.twig' => [
        'reason' => 'the id of the editor is written for a script (a quoted JavaScript string in the attribute that starts the editor) and escaped in the attributes that name it; the old template printed it as it was in all three',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testFormValuesAreEscaped',
        'differences' => [
            'ace99d5de1ababede5fac2eb31114a485f18c8720641af899d9689515ea84697',
        ],
    ],
    'templates/default/forms/output/tags.html.twig' => [
        'reason' => 'a tag is printed as text; the old template printed it into the markup as it was, so a tag with a tag in it was markup',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testFormValuesAreEscaped',
        'differences' => [
            'e15d11d841ff64da79cd8c7a3eb102bb80ec67cfa278c45df8beadcdef509984',
            '25b60888f6aa221784eb5b89d832f8d6962a538947356f3b5fdcd19b827ef652',
        ],
    ],
    'templates/default/forms/output/url.html.twig' => [
        'reason' => 'the address of the link is the address only when it is http or https (`javascript:` and the like give a link to nowhere), and is escaped; the old template printed `FILTER_SANITIZE_URL`\'s answer, which keeps a quote and a `javascript:` address',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testFormValuesAreEscaped',
        'differences' => [
            '60491e359c264f8db64e6c59640c129d6e19a065959e332230fcdc357354036e',
            'bdab883bc8370fdddac18ef472c26d65c23932ca10d3dedb732cce38986b0112',
        ],
    ],
    'templates/default/forms/usersearch.html.twig' => [
        'reason' => 'the id, the class and the name of the search are escaped, and its address and the template it draws are written as JavaScript strings in the attribute that starts it; the old template printed all of them as they were',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testFormValuesAreEscaped',
        'differences' => [
            '7f477d4eb9be3ba2d686fe8470b6d549961a31926818c852bd523382fd8a0103',
        ],
    ],
    'templates/default/forms/link.html.twig' => [
        'reason' => 'in an XHR response (no page shell draws the hidden forms of the links) the form a link submits is printed after the link; the old template stored it in a global that nothing read, so a link in content inserted by a script did nothing',
        'test' => 'Tests\\Kernel\\BuiltInLookTest::testALinkActionInAnXhrResponseCarriesItsForm',
        'differences' => [
            'bdba23b8824c42de9474630f0e3143b6528b70d1f7b13912ed7fc3af79ef7ff4',
        ],
    ],
];
