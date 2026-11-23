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
            'b38311e543d8a03d3e6133d92d39c0df77e80ace594e6982c69d1d676fddf834',
            'da1cebc56d1e7b4d0dddd9564e946d10eb7cdcbcb498de58c5228024b7cd5490',
            'd85e3633d8a266d000cbed5c5fc61bd318fac71252f03af00dec792637f7410d',
            'afaeb7581e57d7c4cbe45182202ab35e6f23d1c31aa3c0e43b4cb23e63463fd9',
        ],
    ],
    'templates/default/admin/diagnostics.html.twig' => [
        'reason' => 'the page\'s own address, in the script that loads the detailed report, is written as one JavaScript string (`e(\'js\')`); the old template printed it as it was, so an address with `</script>` in its query (a visitor\'s word) ended the script. The same string value, written differently',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testTheAdminScriptsWriteThePagesAddressForAScript',
        'differences' => [
            'a45f640f7774adec2a73b68688b38f59be7992a75c20e2da28b1cdb3b161b4a1',
            '9d27cbfcedb57c8438d5d1c9511dcec14851b932924de96852bf3773d7f2082b',
            '6b1d51232f120d6b896713f61eb5f41fbefad8594cb0af4bff09490259286ad1',
            'e9888df7973538b96f093131196092b528decb3b97efe86358a39122a419cf26',
            '23c95232405ffd8dce260ccee97c6675a0372e7a9eb9203b80572d544d04dece',
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
    'templates/email/shell.html.twig' => [
        'reason' => 'the footer of the mail shell no longer carries the postal address of the company that once ran the project, and its link to Idno\'s home page no longer carries a tracking query built from the path of the request (the old shell read the request, which a queue worker does not have): the link is `https://idno.co/`; the shell also escapes the title and the preheader, which are text. Nothing else differs',
        'test' => 'Tests\\Characterisation\\MailFlowsTest::testTheMailShellHasNoPostalAddressAndNoRequestPath',
        'differences' => [
            '7f92051692150e0e497c44938ca17192769b7444d5c9e59e42b2a2df92c85942',
            '50b1287bf312b135cdeca2d431afc18cd52c35290b70325649afebee17aaabe9',
            '977d911dc19943c0cf350494d6f3ea7439a969ae7a282903fecd023401f37457',
            '64b475d45787c5e1d4273c96baad1aa75e88b7764734254314a4db86fd81ebab',
            'f5ff0e641f1f066c5d2126427b44de97779824b2e4068685a702673bdcc22619',
            '300d99afb6f8f180afb6da414826b0dcecaa71ffe1a65893d815bc59fb435def',
            '3c8f7b55f86bd693949c1e9f51f20961176227f053c1aa278fe16c3ea043d798',
            'd09b49875e5abdabc812566a8e9809db546625b388c42e82738db63f44a4b0a6',
            '4187f38537693596202fcda7d2e0e7f880f186beadf7664f619aad7f6c23fe68',
            '1b96474207bb7eadebf00d407c7e1c613c642691a0b6eea955237b3e7132e038',
            '4c56482fccd69ba7237bd5de20b8f92f26b4483fe4dee8b6fe9ac1d910706cba',
            '87ce7e212c63d95ffadeacaa2b7fb68878325b4dcf4180f8b7a1536523d5bb49',
            'a8946fe1bfec1df7a59c7dcb96cccf3eaf49a6fec145143b8b5a4728cdc44d20',
            '4a152aa7da08623dc0538250d5353ad120b121c80338300325827a203f84e717',
            '6948c226d2eb795a65f5ae8536fd5beed1cceb56211ca35da37a3d0cf209dabc',
            'bdcf768bc0a0c3b2d2ec4513363e45947a70a3d6db5b350fddfa74b09045346d',
            '5b2d88be3a4daf2db6ce049dd7b078f0c329abff85863545753c61bdc271a16e',
            '1e37bf067e607d18f8b20e8917d82b01da31a5d2b31889f4c68586ac70e51dd2',
        ],
    ],
    'templates/email/account/invite.html.twig' => [
        'reason' => 'the invitation prints its title, `Join us!`, which the old template never printed (its `<?php` had no `echo`), and the name of the inviter, a member\'s word, is escaped (the old template printed it as markup); the site\'s title in the invitation is escaped too. Nothing else differs',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testAnInviteEscapesTheInvitersName',
        'differences' => [
            'b8c7d9fe0053ccda42014422f76278402c47ff125682e59ef0057120e047922d',
            '611050fc4d249e0ffedae956f86c2b8433092caf861a345f69b6f7cb7779cae0',
        ],
    ],
    'IdnoPlugins/Checkin/templates/default/entity/Checkin.html.twig' => [
        'reason' => "the check-in's map script: what is written inside the script is written for a script (`|e('js')`: `/` and `:` become `\\/` and `\\u003A`, a hyphen `\\u002D`, an apostrophe `\\u0027`), as the old template printed the value between quotes as it was (the id of the map); and the body is purified when it is drawn, so a body stored by a client that did not clean it (a script, a handler) is no longer markup",
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testThePluginPostsScriptsWriteTheirValuesForAScript',
        'differences' => [
            '2e736fb88376aafb34479c957aaf2a8ca6d1a995640420f8b3919f21615fefcd',
            'cde44be5c5571e89c7df8544de413e87ba26f974a83b750fd47155f8ad6f5294',
            '6deab27c86d504ebe2305c77f243a2f0fc51683b49100cef282497e3e69ddafa',
            '9881685f4d135330fa37a95814790e2a3497f0eb53272d0783b5ff27c6f80e7c',
            'cd5680a3d1e02b95f9fc2bced78ca97cfcb47f654a5b8df487b324df8cc5041e',
            'd6e59fb31e0579595fe3c7895be2f4e8f6f8b01fbd559f909ee388db5aa78248',
            '9562f810390bdad5123e55b403522b080e9eaab81029729832579d64005bb0d7',
            'f7b0af10f5d6236f3eb238b50b9d32d46f4e761db7c87b70c552703fb1feddc8',
        ],
    ],
    'IdnoPlugins/Checkin/templates/default/entity/Checkin/edit.html.twig' => [
        'reason' => "a control drawn after another took over the earlier control's attributes: the old form passed the class, `required`, placeholder and id of the editor above to the hidden `forward-to` field and its class to the spinner of the footer, through the page's template object. Each control now has the attributes it is given",
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testALaterControlOfAnEditFormDoesNotInheritTheAttributesOfAnEarlierOne',
        'differences' => [
            'de5629530c89acb654a503a92706b2d2e03eb741a5bd2f8fb6387c337b8e7433',
            'd2f5184bad7023baa165eda036d73bbf71bd486a8c448ece1a2f80eb7116b590',
            '6df3741a30b05894f7ff8eef7ac59a6171d879fa2f6328861a69b438389f27ae',
            '2f75c6a9a1f7ea77c92aa52ec96c996d49130581e6fdc2e6172d6dec0a10a5ac',
            'b2751039f01481f0af069da93829a25709b5db38f0ab74bf3b563d62ee09558a',
            '19d549dcb4ebdbd8005d901476f6a6f964cac8cccd74e965b7e030224c84e966',
            '419d8facdfb0374ca38cc4e6b3b5af4f3979a42330eb7ede0146119d2f6f8508',
        ],
    ],
    'IdnoPlugins/Event/templates/default/entity/Event.html.twig' => [
        'reason' => 'the body is purified when it is drawn, so a body stored by a client that did not clean it (a script, a handler) is no longer markup',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testAStoredBodyIsPurifiedWhenItIsDrawn',
        'differences' => [
            'bd9c835dace039307b299aa6ab3973fe54bc947c45e3f3351fabe9e13fe79420',
            '4885e93b6e73c17f550f7841874f0b2de7325134ec5f1e3ea5868659c6779c8b',
        ],
    ],
    'IdnoPlugins/Event/templates/default/entity/Event/edit.html.twig' => [
        'reason' => "a control drawn after another took over the earlier control's attributes: the old form passed the class, `required`, placeholder and id of the editor above to the hidden `forward-to` field and its class to the spinner of the footer, through the page's template object. Each control now has the attributes it is given",
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testALaterControlOfAnEditFormDoesNotInheritTheAttributesOfAnEarlierOne',
        'differences' => [
            'cb54bf045c423e91e2c41eca445b390fa50178c9892ba4151fcebaa43a2e1778',
            '6aee05fa1e070090259465444bd058aae1167acdd8bf0a999b137e47e974abb2',
            'e0474d9a1a780adf2d38efe48e06883103aae43785b9d1eb72f097b639622941',
            'f6d518ae0120fbb1f16010b104360179236f12e12f80b23b8ec0ad15c14966bd',
        ],
    ],
    'IdnoPlugins/Event/templates/default/entity/RSVP/edit.html.twig' => [
        'reason' => "a control drawn after another took over the earlier control's attributes: the old form passed the class, `required`, placeholder and id of the editor above to the hidden `forward-to` field and its class to the spinner of the footer, through the page's template object. Each control now has the attributes it is given",
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testALaterControlOfAnEditFormDoesNotInheritTheAttributesOfAnEarlierOne',
        'differences' => [
            '280106d81db151625d070d0439fcd209550e093f00e7bb66c1ffcf759d62b0be',
            'ab888077aa70a85b21594f7c2ff134cabe51d854ab0932e219954aab20d277d6',
            '352512e3d27f8e2bd78732098803fb6bf674f502239b931557d56fd163b5d420',
        ],
    ],
    'IdnoPlugins/Like/templates/default/entity/Like/edit.html.twig' => [
        'reason' => "a control drawn after another took over the earlier control's attributes: the old form passed the class, `required`, placeholder and id of the editor above to the hidden `forward-to` field and its class to the spinner of the footer, through the page's template object. Each control now has the attributes it is given; and the address the form asks for a title is written inside the script for a script: what is written inside the script is written for a script (`|e('js')`: `/` and `:` become `\\/` and `\\u003A`, a hyphen `\\u002D`, an apostrophe `\\u0027`), as the old template printed the value between quotes as it was",
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testThePluginPostsScriptsWriteTheirValuesForAScript',
        'differences' => [
            '5e9a5309fdf5f876999179bbe33ce82c4e19a93e1ce3f9d3b47536c02e96e066',
            '7c85b12bfba14da8186d706748004e9ef871ab41e89af375e7fee2c917a9d105',
            '454336e0270191882325dc166faf79b37ccada99c23cf53697cff98ffa08569a',
            'e8eba3f7965a5c27f92561098183d68c88a1f92c8c5ef47880d442aaeba6a2f9',
            'b7a7da4874fd798b6ce8bf12ef924c0c35bdeecf3d4cdb9b32c4e4165f9215b0',
            '7e7e7ab2e083faf86f56c1abf21141eed78d086f09293309b52b794aac6ed25d',
            'a32bf206064c5a2ed48d4c890bff0f83c97b4e8d9ea46ff49e2049dbc96ba93f',
        ],
    ],
    'IdnoPlugins/Media/templates/default/entity/Media.html.twig' => [
        'reason' => "the address of an audio file is written inside the player's script for a script, and only when it is http or https (an address that is not is an empty string): what is written inside the script is written for a script (`|e('js')`: `/` and `:` become `\\/` and `\\u003A`, a hyphen `\\u002D`, an apostrophe `\\u0027`), as the old template printed the value between quotes as it was",
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testThePluginPostsScriptsWriteTheirValuesForAScript',
        'differences' => [
            'f634f263b8271be7c0914e3d43c65bd8a9fa6d1f83e21a830fe0ac20d6d51fd8',
            '991ee8431b17436af6b7a6cab46408062fc736e1f61c503905d1843aa88ad3ae',
            'd2bfe0190566d724c799c5575e1f8dd01ce9fecbc028386a371fdd446d332c1f',
            '92d26ad80140f4a62d567b65c9838f64e32b913b683a841845de566d85bfba61',
            '324e25bbadb07c43f89a9b5c2649ddd5795ab827ce4554bdddc14370e343d8d2',
            'db041556bfe642dbb5acc45945a855f270c10742f5b744116e9d972d94ddd290',
        ],
    ],
    'IdnoPlugins/Media/templates/default/entity/Media/edit.html.twig' => [
        'reason' => "a control drawn after another took over the earlier control's attributes: the old form passed the class, `required`, placeholder and id of the editor above to the hidden `forward-to` field and its class to the spinner of the footer, through the page's template object. Each control now has the attributes it is given",
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testALaterControlOfAnEditFormDoesNotInheritTheAttributesOfAnEarlierOne',
        'differences' => [
            'd7acbe19345bd2963fab46e65ca04ae50fdec09be1310be6f90d719a6f57ea02',
            'b30a5fda90db5c691136e5fcf01ccd3557fe1efc427e5ee141a1cef96632c9d2',
            '359ccd9b8ca70a863bb1d90b1b6bcd44b1be3543440448674a18eacbc892ceac',
            'e9bd4f9845dd259ae8d4a8afa32ef278b2e17ebe82e17d797841133ab01376d1',
            'fb87e75488f5d29f80f4e795899af302c95802ca31c426b4d1a6eeb66303dd75',
            'ce566f61c06625d428e4edf5345e5b7d213f45d396dae0e6a5243ebbde8c7c7e',
        ],
    ],
    'IdnoPlugins/Photo/templates/default/entity/Photo.html.twig' => [
        'reason' => 'the address of a picture is printed only when it is http or https; the old template changed the scheme of any address to http (`javascript:alert(1)` became `http:alert(1)`) and printed it',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testAPhotosPictureAddressThatIsNotHttpIsNotPrinted',
        'differences' => [
            'b8f5e92257bc01c9fe0ceb9636bd4112ee67fafba25a6a86e86da279e5f7e18b',
            'd284c13cfbb6935e62dddff27aceeb1447ad8c4852aa2c426c87fc7702e160a4',
            '6eb6d3793b91c41e2000805fa84d9bafcff17c773b3aec707530161b85d917ea',
        ],
    ],
    'IdnoPlugins/Photo/templates/default/entity/Photo/edit.html.twig' => [
        'reason' => "a control drawn after another took over the earlier control's attributes: the old form passed the class, `required`, placeholder and id of the editor above to the hidden `forward-to` field and its class to the spinner of the footer, through the page's template object. Each control now has the attributes it is given",
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testALaterControlOfAnEditFormDoesNotInheritTheAttributesOfAnEarlierOne',
        'differences' => [
            'deda31ec2ca8d55107a68db4e50448c56570ca8eddc816c5ae6b04eb00d77ec0',
            'b496ade498b361e8862351b87f74e66ed974319f15f3a8883468116abbe050d5',
            'adfa6310b239ee3aa62183e412e555d9f48fb21fd2383a95cb7aa9427f0950f6',
            'c4c37590d8502cdb6e9650fb73fb0cfbcaf255f9eb2359d06687f3d99cb4fa63',
            '773ed05fdaf4739d355cef90d385563702f6db08dfde1338943fe3905b7798b2',
            '23d1a3635886b8046f0009244d00f9568d4ceb4d412fc58b7c9a7d01ee8e9259',
            '8eaf41ed81b539cc541f21bb2ebeb46e5c7c9a6451d08733935cf43032d851f9',
            '05b35a2b2bb59f0d6d3daf93ec9dfe2accf2310a918ec524483c2b960b961fc7',
            'f3ad1e6bcf42112fd0994cddf399966bb73dfa9a88e6c3f4aea169cbff507079',
        ],
    ],
    'IdnoPlugins/StaticPages/templates/default/entity/StaticPage.html.twig' => [
        'reason' => "a page's title is text, its forward address is a link only when it is http or https and is text either way, the old template printed them as they were stored (in an element and in an attribute); the body is printed as stored, as before",
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testAStaticPagesTitleAndForwardAddressAreEscapedAndItsBodyIsAsWritten',
        'differences' => [
            '21b1b1561be3d988883c219df29fff5c235f81ef7fe31cf82371a5908675a793',
            'c030b88db83b2704309660288772b141fc80f3d768dd664765cb044f8d1ab2b6',
            '02586093aba5de8ac8b510d89e971e27f8ed2eeeb588e79d4bc395c9c6ec2c3b',
            'f0076044814d90bdcd7ef1533091718451099048f735eb63f2951f38874f5655',
        ],
    ],
    'IdnoPlugins/StaticPages/templates/default/entity/StaticPage/edit.html.twig' => [
        'reason' => "a control drawn after another took over the earlier control's attributes: the old form passed the class, `required`, placeholder and id of the editor above to the hidden `forward-to` field and its class to the spinner of the footer, through the page's template object. Each control now has the attributes it is given; and the address of the file picker is written inside the page's script for a script: what is written inside the script is written for a script (`|e('js')`: `/` and `:` become `\\/` and `\\u003A`, a hyphen `\\u002D`, an apostrophe `\\u0027`), as the old template printed the value between quotes as it was",
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testThePluginPostsScriptsWriteTheirValuesForAScript',
        'differences' => [
            'f8e894ef4b7dbec60b5ca2669850d05116d9ae47795f7e1b359e86ec358c7523',
            '9a5dae830cd4af1ff4d3463b9a7d3c99460981f64d7728af3c08e17e4f0a6158',
            '30e6e88b5c002a33b752be1ef088b1d08931ad4193d7df239d6d4e217b26db2c',
            '88b0b9a0954de9aa0567d1b8aa27066941c823440cf28d42c12eda49876a1beb',
            'db789cf917835e01fc12ceca1a5f630b4c125aefebec01563505232111bad88e',
            '458c3d0e8422ee662c82fb73e03d7fabf072e5e9aead98b3ae27a5eefbda24bd',
            '8f6c40fa599f42484a5aee3fe3dd2ae2b09304b626e83925639b7cfa2377cf00',
        ],
    ],
    'IdnoPlugins/Status/templates/default/entity/Status/edit.html.twig' => [
        'reason' => "a control drawn after another took over the earlier control's attributes: the old form passed the class, `required`, placeholder and id of the editor above to the hidden `forward-to` field and its class to the spinner of the footer, through the page's template object. Each control now has the attributes it is given",
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testALaterControlOfAnEditFormDoesNotInheritTheAttributesOfAnEarlierOne',
        'differences' => [
            'a702624d94dc735ac21efe4bbcd4e13596fa82f3c713d42cb2a71afca8c15433',
            'e06ceb3e26c9fd6879b375b01a0cd5a02465cc31df771b321a648203ceeebb45',
            '7f9bcc5b09ac93ee1cbe472b143850bdbebb68f100cce4ff328e1c13070f8ed2',
            'b6f83a38bfae7bdd2734441c272ce63cdd6e041df940e88cf3a0e80a352fc0e8',
            '218371edcf5cede0504c47696e30f7ac5a5ee5fff74759eefb05105edfdc3932',
            '5101b869d2fa0046a54dc5ec7690aee8a481013516eb06e7f6e41eb1bd590be2',
            'f2965a12f5dd724874c3aea6b97df505ab5636c38037106f725ba59400d79d4e',
            'fbe778466e031149c7c579254475111f10b83e3a806fef3a63b4143875344b7f',
            '452b1e719821299e1570444fd2c8a90fa36e54403701fee83d629c763c9048a7',
            '316d6f82e017bac3227402324de59f5662aac6480ad241a5f4d775fef1881a7e',
            'a70f33c5d13f2b93da2fe79c7e7273cc077e1eb8c90662a0a2256a219462685e',
            '82dddca0b777f3bc4ee0413a895486f7b9e76182e527c9299f89623169e74adb',
            'cf7f131fbfd14f3519fc6f41616d5b511df7b9b51878fe2e2648e0a41c3ecd03',
        ],
    ],
    'IdnoPlugins/Text/templates/default/entity/Entry/edit.html.twig' => [
        'reason' => "a control drawn after another took over the earlier control's attributes: the old form passed the class, `required`, placeholder and id of the editor above to the hidden `forward-to` field and its class to the spinner of the footer, through the page's template object. Each control now has the attributes it is given",
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testALaterControlOfAnEditFormDoesNotInheritTheAttributesOfAnEarlierOne',
        'differences' => [
            '804c3572efec093edcc4fc8b93a9a5b4ca28ad3ecb0bcfe734ee73b6faf578ef',
            'bc0ee95de0898a6433dfb8ca72a6252b8c4a56e1c46d91ba4e1654db1d599e36',
            '2c93f5df9d3aef47cec81d204cae5e05fd285151aab8c30d698039db6c00c060',
            'f97c913c5b6adc4d10da4bbb31de613daf32df5ad9813b40f96487ceff81df6d',
            '9e6221857229158afb2af587219ce2c9d934ce6cf534da41d2e6a4e6d5cd52b8',
            '6c8f133377ac77dc4ad0c400620ff4f38ba26738f6791efbde2a63d05f4ea0c8',
            '68425aba60848d361ba5628822508a2be8b52e0f2fffa55cf7325cb64ed74d04',
        ],
    ],
    'IdnoPlugins/Bridgy/templates/default/bridgy/account.html.twig' => [
        'reason' => 'the address of the page\'s own check, in the script that asks whether the connection changed, is written as one JavaScript string (`e(\'js\')`); the old template printed it as it was. The Bridgy key in the form is escaped; the old template printed it as it was, so a key with a quote in it ended its attribute. The same values, written differently',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testThePluginPagesWriteTheirAddressesForAScript',
        'differences' => [
            '4badffb832dbe9426efb18bc229cc06f20214f8e531f2a9d869f485da05c7192',
            'ed430716ef63f006699f55ec4af50cbca6e6a5a450a34743cd00689ec1a3d023',
            '9542fd4cd1df0aa91d3d656201366a7188722d7f59a308acbecdb7b081382b29',
        ],
    ],
    'IdnoPlugins/StaticPages/templates/default/staticpages/admin.html.twig' => [
        'reason' => 'the addresses of the two reordering requests, in the page\'s script, are written as one JavaScript string each (`e(\'js\')`); the old template printed them as they were. The same string values, written differently',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testThePluginPagesWriteTheirAddressesForAScript',
        'differences' => [
            '8f9e1b2353dfb60961b91ea3bd743186482878e6f821ec894ebcb8d958885ba6',
            '970cdc7fb511d3e8ec6292be275ebd145fe9fb247c15c5b3ceb9188bb072fb33',
            'b534c2d66a09f012dbb23c3035f0df7221d206690eca8d677fcb31b294e9c5a1',
            'c776ddeb3a8bd5c0ce4e6e26371a820958ba07d14282de7e5872117b29724b75',
            '050014a86cc60c5bd22c24c0b5d28044e59e195f0784c7233c09978131c42d15',
            'e17b59dd8365c95d548dec5bb52b1ed8664e79608f6d8bc661e5abacb9bbc1e9',
        ],
    ],
    'IdnoPlugins/Styles/templates/default/styles/admin.html.twig' => [
        'reason' => 'the label the script puts on the file button after a stylesheet is chosen is written as one JavaScript string (`e(\'js\')`); the old template printed the translation as it was, so a translation with a quote in it ended the string. The same string value, written differently',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testThePluginPagesWriteTheirAddressesForAScript',
        'differences' => [
            '0896cf8200e1af47ce46e1ec08a1aedab03ad5cd18998114e0aea50268b51720',
            '75fb5e77195c406f28f1887b526d8e2690c46b9034290b77b909f272e102dda8',
            '7f6f168ded005fb9aac0a585426f1640cb4857635cdd20a3e230a04b810fe9bd',
            '4c568f9949d81b3ba9c4bc8976578cc11bb8a2ae889c9cb672942926e7944c1a',
        ],
    ],
    'IdnoPlugins/IndiePub/templates/default/account/indiepub.html.twig' => [
        'reason' => 'the address of an access token\'s client is linked only when it is http or https (`http_url`); the old template linked whatever was stored, so a `javascript:` address stored by an authorization request ran when the link was followed. An address that is http or https is as it was',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testThePluginPagesEscapeWhatTheyPrint',
        'differences' => [
            'bd9289b096a717b196a357311cb2201b2917b6c9c8ba2f8d7d690610c5350e26',
            'dbed4cb5ecec4011b0bdb298857de7a5550bea611e22221be1db998462893d45',
        ],
    ],
    'IdnoPlugins/IndiePub/templates/default/indiepub/auth.html.twig' => [
        'reason' => 'the cancel link goes to the redirect address of the request only when it is http or https (`http_url`); the old template linked whatever the request carried, so a `javascript:` redirect address ran when a member pressed Cancel. An address that is http or https is as it was',
        'test' => 'Tests\\Templating\\TemplateEscapingTest::testThePluginPagesEscapeWhatTheyPrint',
        'differences' => [
            'fa120bd00771650327acd58eeb131913293470a7a76f52b5467efb79ea4fabff',
        ],
    ],
];
