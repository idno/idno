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
        'reason' => 'every attribute of the control (its name, id, class, type, pattern and the script attributes) is printed escaped; the old template printed them as they were, so a value with a quote ended the attribute. Non-ASCII text in a value, a placeholder or an alternative text is printed as the characters themselves, where `htmlentities()` wrote a named entity for each. An attribute given `false` is not printed (the old template wrote `min=""`), and a boolean attribute (`autofocus`, `checked`, `disabled`, `multiple`, `readonly`, `required`) given `false`, `0`, `\'0\'` or an empty string is not printed either: the old template wrote `required=""` or `required="0"`, which a browser reads as set',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testFormValuesAreEscaped',
        'differences' => [
            '013db917e42799a943d248eb539100daffa40007eb51a2bf999b8e090ff8d98a',
            'ada058f342e586b3e2a1bf471457c720d62fe85144417de8ce3f987068552305',
            '030ae34392dc6e28590d7fbfd477789e9a1841cfb3a3c6476027ea2d61f433cb',
            '321fa99267350bc0606cdfedc653ca9986837c301bec29c395698e0892f54c04',
            '9684d5079501619ec8e141f5592763c454cfd4c8dcb3b39834608ed021cb5c87',
            '8e34c29f180daa9f97968c397c8ab1fe98fee33397843135b4c67934047e24ee',
            '6f1867606955db3134374f449d1d00de49174025072113ced730344342b4e61e',
            '5c94a10995f3f3f172dcde9b61a4826d78ad0e873cbb04606a8f2210d4dcd085',
        ],
    ],
    'templates/default/forms/input/select.html.twig' => [
        'reason' => 'the name, id, class, script attributes and the value of each option of the select are printed escaped; the old template printed them as they were, so a value with a quote ended the attribute. A boolean attribute (`disabled`, `multiple`, `required` and the others) given `false`, `0`, `\'0\'` or an empty string is not printed: the old template wrote `required=""` or `multiple="0"`, which a browser reads as set',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testFormValuesAreEscaped',
        'differences' => [
            '0afe13dffec5df3df650357a68ee96eb202b6834f87b256d013479a917c0e305',
            '9a918829b2e09f98a9e5205e99fadc1a6becd8c4d7f81c292c03362e0b471b3a',
            '12babf5996cfe3b1395cfa223a8a53c76a57d25b1b985e6617ccb69340c86063',
            '5fbad199c73a45a4750bc74de52b185d1d55476d6996592d061cde8439583c54',
            'a7f2f3b9fad5973ee3e64a4b0759d0e6863c90afee99978fadce840f2d6b8675',
            '71512020081fc9be875bf38cc807ce63e0d1c1f4b6fc5c80156a48089cbf8f00',
            '3f34d616d6a4363befcef0aed8f355e1711a849a1528bc9a9c53aafac6510333',
            'd5c49662f204c5668e1ded7e09abfaaac5ae3d5b93ce72d8de468ac772481b80',
            'd756f007ae56793025f159bb7dd2b35c7cd74ca945a1310ab74a0de290423db8',
            'dc6078489e05ffe70b1470085ea90d83e2a95ccb8f49a8be7870e75ac0e3a7f7',
        ],
    ],
    'templates/default/forms/input/longtext.html.twig' => [
        'reason' => 'the name, the class, the id and the height of the text area are printed escaped; the old template printed them as they were, so a value with a quote ended the attribute (the placeholder and the text were already escaped). The height is a number (`|to_int`): a height that is not one would have put its text into the `style` attribute, and is `0` now. A text area given `0` or `\'0\'` as its text keeps it, where the old template dropped it',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testFormValuesAreEscaped',
        'differences' => [
            'c6aa30f5a8677216c504774c10b491b340158019b8736b9f3a2a024015110d21',
            '61904ffd7e837999c0b50e34a388b6c243b14446e6b8d00e74f8f4f385795a27',
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
    'templates/default/admin/homepage.html.twig' => [
        'reason' => 'the HTML comments that held each content type\'s icon, and the whole copy of the form that was commented out, are not drawn: nothing shows them, and they printed icon markup into the page\'s source',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheHomepageSettingsHoldNoCommentedOutMarkup',
        'differences' => [
            'f0a237fd13a5540dabf31121983c50e632a535cdb8b3c525a555aef4475e86a7',
            '114be580cdb31c2c5f8444508e1f2fc60c1b5b4b68ad0edc91f47e0f85e7a6a5',
            '6ab2c16c013ad345e451e9792b645337c354e3dae0219663d9526a2778976cc2',
            'f13338fdf0d9bf887e49d2370dc97534ed8d4e2b34aeb756ee22477f69c64c4f',
        ],
    ],
    'templates/default/admin/diagnostics.html.twig' => [
        'reason' => 'the page\'s own address, in the script that loads the detailed report, is written as one JavaScript string (`e(\'js\')`); the old template printed it as it was, so an address with `</script>` in its query (a visitor\'s word) ended the script. The same string value, written differently',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheAdminScriptsWriteThePagesAddressForAScript',
        'differences' => [
            '2714d01f5a7fcde444239e142d027e9d807768e4ce924e68cd24b5dbda588ace',
            '82d291d978dae98389388550d023995f1066aaae28d3ed47e83ab48431a57f29',
            '7946176d33e15599af18810291503fa2b726146efaf33279a16fb5d48a42e1ef',
            '5c066d79fc8d14afd19d194de3767c2bbc83c90a9707c36f01b13c59ba7b1cb3',
            'd11f5627eaddfd7367253fed9a2ec34d6c2438c7a2f6f62816d88f17ac0704e4',
        ],
    ],
    'templates/default/admin/logs.html.twig' => [
        'reason' => 'the page\'s own address, in the script that loads the logs, is written as one JavaScript string (`e(\'js\')`); the old template printed it as it was, so an address with `</script>` in its query (a visitor\'s word) ended the script. The same string value, written differently',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheAdminScriptsWriteThePagesAddressForAScript',
        'differences' => [
            '75d0307c9e633c513000dc6218288f2f00dad01d3a57edc959f5099c36a25043',
        ],
    ],
    'templates/default/admin/dependencies/plugin.html.twig' => [
        'reason' => 'the name of a plugin and the version it needs are escaped in the label; the old template printed them as they were. Names and versions without `&`, `<`, `>` or a quote are as they were',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheAdminPagesEscapeWhatTheyPrint',
        'differences' => [
            '8d23b9398a99e097582013ac4214f1f4113a9be0cecbf1062530101adca41c51',
        ],
    ],
    'templates/default/admin/statistics.html.twig' => [
        'reason' => 'the name of a statistics report, the title of its card, is escaped; the old template printed it as it was',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheAdminPagesEscapeWhatTheyPrint',
        'differences' => [
            '360dbe9de75cba2f52f842b071a6d79c271d038da4fa09beda95ec3ed479000b',
        ],
    ],
    'templates/default/admin/statistics/report.html.twig' => [
        'reason' => 'the labels and values of a statistics report (written by plugins) are escaped; the old template printed them as they were. Values without `&`, `<`, `>` or a quote are as they were',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheAdminPagesEscapeWhatTheyPrint',
        'differences' => [
            '267d4df7a797b3d60db9d7db39fb18349d0fb8ac9e0d1ddee650428c716705c2',
            '142f8f956a46c81b8537c140567c5145c226683b0f36332cdf36325638b410ee',
        ],
    ],
    'templates/default/admin/users.html.twig' => [
        'reason' => 'the email address of an invitation and each blocked address are escaped; the old template printed them as they were (a blocked address is whatever an administrator typed)',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheAdminPagesEscapeWhatTheyPrint',
        'differences' => [
            '1f21bda259115b03dd1d38b24a96ebfbda14f30f11aea22cf4db0800d93c021a',
            '83b71c9e661dd1df3085852063e576fc9ac3cba6c520b4bcae3381469b76a69f',
        ],
    ],
    'templates/default/forms/usersearch.html.twig' => [
        'reason' => 'the id, the class and the name of the search are escaped, and its address and the template it draws are written as JavaScript strings in the attribute that starts it; the old template printed all of them as they were. The search\'s script sends `X-Requested-With: XMLHttpRequest`, so that the results come with the forms of their action links (without it no form arrived and the links did nothing)',
        'test' => 'Tests\\Templating\\UserSearchActionLinksTest::testTheSearchScriptSendsTheHeaderThatMakesItsRequestAnXhrOne',
        'differences' => [
            'cb7ea49139da4347412b03bf0c7e00faaedfb27ecfd65d51e70a0e41b5b3576c',
            '5fafeb29d8c769f025dbf8bdf182b92adb949daa73c672767cdc1c034198ba4e',
            '8ca193a2f609609bb06b4231ab4ef4b1144a2009736d870bc6793262d7914552',
            '15b9f786b7cf1538ae837e41cbc44e9c8e3a1979c685d36635f3fd9f6b573bfe',
            'f37eceb10953dbcfa67225d06058cce1d2c6cfed28b5e092e0b6ca52032a4f09',
            '8e84b43e1e43d3dab1d0c472a01f07b5a976e465831db4398b051dfe0cd1da04',
            '6ed1f604e2dfa6002ae3616168e2c09d742efeb8820016f92bc27eb612b3fabd',
            '70983815b5ce24759c20fcda50edf07802fe43e9ba2c1ead9101542eb1de9ee3',
            '07525c74f1bb9baf3d927ff50aebdf872b8038af27354f71222b9b486df783f6',
            '95b271a507e8fab437ebd4ee43265a97ceff7f70fccd1702acef60f6c4d89eaf',
            'b8032613b2b5ff1e12134d606890eaa4cb9d450835627fad10cc02dddc5d1da7',
            'af8e7d484fe110a022d44985ab6b38eeb1cc72e964b4a36a15019053e8373906',
        ],
    ],
    'templates/default/forms/link.html.twig' => [
        'reason' => 'in an XHR response (no page shell draws the hidden forms of the links) the form a link submits is printed after the link; the old template stored it in a global that only the admin user search page read (`Idno\\Pages\\Search\\User`, which appends it to its results), so a link in other content a script inserted did nothing; a draw of the JSON look still fills that global, from the `classic` copy',
        'test' => 'Tests\\Kernel\\BuiltInLookTest::testALinkActionInAnXhrResponseCarriesItsForm',
        'differences' => [
            'bdba23b8824c42de9474630f0e3143b6528b70d1f7b13912ed7fc3af79ef7ff4',
        ],
    ],
    'templates/default/account/password/reset.html.twig' => [
        'reason' => 'the email address and the code come from the link a request followed: they are printed as text in their fields; the old template printed both as they were, so a value with a quote ended the attribute',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testThePasswordResetFormPrintsTheRequestsValuesAsText',
        'differences' => [
            'c77a353d655e438534999ace68f52e093d3560dae7bbea4d16af131ceafd8792',
        ],
    ],
    'templates/default/account/settings/following/bookmarklet.html.twig' => [
        'reason' => 'the bookmarklet\'s script is written with the site\'s address as one JavaScript string (`e(\'js\')`: every `/` and `:` an escape); the old template printed the address as it was. The same string value, written differently',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheAccountAndOnboardingScriptsWriteTheirValuesForAScript',
        'differences' => [
            '33bc0afb81427b0d43bfcc876d97903ac600c1f2f8127bfb78d305c857334c27',
        ],
    ],
    'templates/default/account/settings/following/mf2user.html.twig' => [
        'reason' => 'the email of a remote card keeps its characters: the old template cut the first seven of every address as if each began with `mailto:` (`ada@example.org` became `mple.org`); only an address that starts with `mailto:` loses that prefix. A photo with an alt text, which the parser gives as a list, is the list\'s `value` (the old template printed none; not compared, the test draws it)',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheFollowBookmarkletKeepsAnEmailThatIsNotAMailtoAddress',
        'differences' => [
            '02ac1ec69ad5d7e457fd797b24092a98d528da1b2cda3d9feea90489673dea15',
        ],
    ],
    'templates/default/account/settings/tools.html.twig' => [
        'reason' => 'the page\'s own address, in the script that asks for the API key, is written as one JavaScript string (`e(\'js\')`); the old template printed it as it was, so an address with `</script>` in its query (a visitor\'s word) ended the script. The same string value, written differently',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheAccountAndOnboardingScriptsWriteTheirValuesForAScript',
        'differences' => [
            'e26952f4b30f6c14b7e1b0294156865c8178c0ae84501276beff298e83333e88',
            'f4e1a97bf2785a9a95424f402eeaa2a325243581d3a7552d8eb1b679faae535b',
        ],
    ],
    'templates/default/onboarding/profile.html.twig' => [
        'reason' => 'the words the photo preview puts into its script (`Choose different photo`, translated) are written for a script with `e(\'js\')`, so that a translation with an apostrophe or a tag cannot end the string or the script; the old template printed the translation between single quotes as it was. The same JavaScript string value, written differently',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheAccountAndOnboardingScriptsWriteTheirValuesForAScript',
        'differences' => [
            '5b604bcfed66c9d2b983eb564f77628cf35ec9e5cea2c9eab0fbbf53dde71827',
            '395fa4223bb3ddd2bf7e891bd2714d3e0e2ba4892f1e89e08b8f17e9a000f8a3',
        ],
    ],
];
