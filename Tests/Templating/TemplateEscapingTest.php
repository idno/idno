<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * What the page shells print of values that a visitor or a member supplied: the page's address, a tag, a description, a profile
 * address. Each is drawn on a private site, with hostile values, and the markup is read back.
 */
final class TemplateEscapingTest extends RenderedSiteTestCase
{
    protected bool $compareGoldens = false;

    /**
     * The path of the request is the visitor's word and ends in the body's classes and the title: a tag page whose tag holds
     * `"><script>` prints no script element, in the title or in the class of the body.
     */
    public function testThePageTitleAndBodyClassesAreEscaped(): void
    {
        $this->seedSite();

        $response = $this->rawGet('/tag/x"><script>alert(1)');

        $this->assertStringContainsString('<body class="', $response, 'the page is drawn: the test is looking at it');
        $this->assertStringNotContainsString('<script>alert(1)', $response, 'a script element the path opened');
        $this->assertSame(1, preg_match('#<title[^>]*>(.*?)</title>#s', $response, $title), 'the page has a title');
        $this->assertStringNotContainsString('<script', $title[1]);
        $this->assertSame(1, preg_match('#<body class="([^"]*)">#', $response, $body), 'the body has one class attribute that the path did not end');
        $this->assertStringContainsString('page-tag-x&quot;&gt;&lt;script&gt;alert(1)', $body[1], 'the path is text in a class');
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $response, $jsonLd), 'the structured data is one script');
        $this->assertStringNotContainsString('<script', $jsonLd[1], 'whose JSON holds no tag');
    }

    /**
     * The page description of a page that is not a permalink is the page's word: the Open Graph tags print it as text.
     */
    public function testTheOpenGraphValuesAreEscaped(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/') . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['title' => 'A "title"', 'description' => 'Say "hello" <script>alert(1)</script>'])->draw('shell/opengraph');
        PHP);

        $this->assertStringContainsString('<meta property="og:description" content="Say &quot;hello&quot; &lt;script&gt;alert(1)&lt;/script&gt;" />', $drawn);
        $this->assertStringNotContainsString('<script', $drawn);
    }

    /**
     * The environment script holds the page's address, which holds the visitor's words: every value is encoded so that none can end
     * the script or open a tag, and it still reads back as the same values.
     */
    public function testTheEnvironmentScriptIsEncodedForAScript(): void
    {
        $this->seedSite();
        $uri = '/begin/?a=</script><script>alert(1)</script>&b="\'';

        $drawn = (string) $this->runInSite($this->pageCode($uri) . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->draw('js/idno');
        PHP);

        $this->assertSame(1, substr_count($drawn, '<script'), 'one script element');
        $this->assertSame(1, substr_count($drawn, '</script>'), 'which nothing in the address ends');
        $this->assertStringNotContainsString('alert(1)</script>', $drawn);
        $this->assertSame(1, preg_match('/var idno = (\{.*\});/', $drawn, $json));
        $environment = json_decode($json[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($uri, $environment['page']['currentUrlFragments']['path'], 'the same values, read back');
        $this->assertStringEndsWith($uri, $environment['page']['currentUrl']);
    }

    /**
     * A profile address that is not http, https, mailto, sms or tel is no identity link: the shell prints a `rel="me"` link for those only.
     */
    public function testAProfileAddressThatIsNotHttpMailSmsOrTelIsNotLinkedAsAnIdentity(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/profile/ada') . <<<'PHP'
        $user = \Idno\Entities\User::getByHandle('ada');
        $user->profile = ['url' => ['https://example.org/ada', '@ada_l', 'mailto:ada@example.com', 'SMS:+15555550100', 'tel:+15555550100', 'javascript:alert(1)', 'data:text/html,x', 'vbscript:x', 'mailto:a b@c', '']];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['user' => $user])->draw('shell/identities');
        PHP);

        $this->assertSame(5, substr_count($drawn, 'rel="me"'));
        $this->assertStringContainsString('href="mailto:ada@example.com"', $drawn);
        $this->assertStringContainsString('href="SMS:+15555550100"', $drawn);
        $this->assertStringContainsString('href="tel:+15555550100"', $drawn);
        $this->assertStringContainsString('href="https://example.org/ada"', $drawn);
        $this->assertStringContainsString('href="https://twitter.com/ada_l"', $drawn, 'a handle is the address of its account');
        $this->assertStringNotContainsString('javascript:', $drawn);
        $this->assertStringNotContainsString('data:', $drawn);
        $this->assertStringNotContainsString('vbscript:', $drawn);
        $this->assertStringNotContainsString('a b@c', $drawn);
    }

    /**
     * A member's page over HTTPS registers the service worker: the site's address is written for a script (an escape for each
     * character that a script could take for syntax), and the scope is the path of the site.
     */
    public function testTheServiceWorkerScriptIsWrittenForAScript(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite('$_SERVER["HTTPS"] = "on";' . $this->pageCode('/') . <<<'PHP'
        \Idno\Core\Idno::site()->session()->logUserOn(\Idno\Entities\User::getByHandle('ada'));
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->draw('shell/webapp');
        PHP);

        $this->assertStringContainsString('navigator.serviceWorker.register(\'https' . '\\u003A\/\/', $drawn);
        $this->assertStringContainsString('service-worker.min.js\', {', $drawn);
        $this->assertStringContainsString("scope: '\\/'", $drawn);
        $this->assertStringContainsString('<link rel="manifest" href="https://', $drawn);
    }

    /**
     * The worker's scope is the path of the site's address: `/blog/` for a site in a subdirectory, the path as written when it has no
     * trailing slash, and `/` for a site at the root. This branch is not compared with the original on the http rig, so the exact lines
     * are asserted.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function serviceWorkerScopes(): array
    {
        $bs = chr(92);

        return [
            'a subdirectory' => ['https://example.org/blog/', 'https' . $bs . 'u003A' . $bs . '/' . $bs . '/example.org' . $bs . '/blog' . $bs . '/service-worker.min.js', $bs . '/blog' . $bs . '/'],
            // the site's own addresses run into the path (`blogservice-worker.min.js`), as they always did: the address needs its slash
            'no trailing slash' => ['https://example.org/blog', 'https' . $bs . 'u003A' . $bs . '/' . $bs . '/example.org' . $bs . '/blogservice-worker.min.js', $bs . '/blog'],
            'the root' => ['https://example.org/', 'https' . $bs . 'u003A' . $bs . '/' . $bs . '/example.org' . $bs . '/service-worker.min.js', $bs . '/'],
        ];
    }

    /**
     * @dataProvider serviceWorkerScopes
     */
    public function testTheServiceWorkerScopeIsThePathOfTheSite(string $url, string $register, string $scope): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite('$_SERVER["HTTPS"] = "on"; \\Idno\\Core\\Idno::site()->config()->url = ' . $this->literal($url) . ';' . $this->pageCode('/') . <<<'PHP'
        \Idno\Core\Idno::site()->session()->logUserOn(\Idno\Entities\User::getByHandle('ada'));
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->draw('shell/webapp');
        PHP);

        $this->assertStringContainsString("            navigator.serviceWorker.register('" . $register . "', {\n                scope: '" . $scope . "'\n            })", $drawn);
    }

    /**
     * A followed site's name is the site's word: the confirmation page for a feed whose name is `</h1><script>x()</script>` prints it as
     * text in the heading. The page cannot be driven over HTTP (the reader fails before it draws: Reader::getFeedDetails() calls
     * DOMDocument::loadHTML() statically, which PHP 8 refuses, whatever the feed holds), so the template is drawn with the feed object
     * the page would give it; the test route of the hostile feed is checked to say what this test says it does.
     */
    public function testAFollowedFeedsTitleIsEscaped(): void
    {
        $this->seedSite();
        $served = $this->site()->request('GET', '_test/hostile/feed');
        $this->assertStringContainsString(htmlspecialchars(\Tests\Fixtures\HostilePageController::NAME, ENT_QUOTES), $served->body, 'the route serves a feed named with a script');

        $drawn = (string) $this->runInSite($this->pageCode('/following/confirm/') . <<<'PHP'
        $feed = new \Idno\Entities\Reader\Feed();
        $feed->setTitle('</h1><script>x()</script>');
        $feed->setURL('https://feed.example/');
        $feed->setFeedURL('https://feed.example/rss"><script>y()</script>');
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['feed' => $feed, 'items' => []])->draw('following/confirm');
        PHP);

        $this->assertStringContainsString('Follow &lt;/h1&gt;&lt;script&gt;x()&lt;/script&gt;?', $drawn, 'the name is text in the heading');
        $this->assertStringNotContainsString('<script>', $drawn);
        $this->assertSame(1, substr_count($drawn, '</h1>'), 'the heading is closed once, by the template');
        $this->assertStringContainsString('name="feed" value="https://feed.example/"', $drawn);
    }

    /**
     * A subscription is drawn from the feed it follows, whose name and address came from another site: a name that closes the paragraph
     * and a `javascript:` address print as text, and the address links nowhere. (Drawn with the feed a subscription holds: a feed is
     * found in the database, which the page has none of; the test route of the hostile feed is what the page-level test above fetches.)
     */
    public function testASubscriptionsTitleAndAddressAreEscaped(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/following/') . <<<'PHP'
        $feed = new \Idno\Entities\Reader\Feed();
        $feed->setTitle('</h1><script>x()</script>');
        $feed->setURL('javascript:alert(1)');
        $feed->setFeedURL('javascript:alert(2)"><script>y()</script>');
        $subscription = new class extends \Idno\Entities\Reader\Subscription {
            public $heldFeed = false;

            public function getFeedObject()
            {
                return $this->heldFeed;
            }
        };
        $subscription->heldFeed = $feed;
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['subscriptions' => [$subscription]])->draw('following/home') . $template->__(['object' => $subscription])->draw('entity/Subscription');
        PHP);

        $this->assertStringContainsString('&lt;/h1&gt;&lt;script&gt;x()&lt;/script&gt;', $drawn);
        $this->assertStringNotContainsString('<script>', $drawn);
        $this->assertStringNotContainsString('javascript:alert(1)"', $drawn);
        $this->assertStringContainsString('<a href="">', $drawn, 'an address that is not http or https links nowhere');
        $this->assertStringContainsString('javascript:alert(2)&quot;&gt;&lt;script&gt;y()&lt;/script&gt;', $drawn, 'the feed address is text');
    }

    /**
     * A webmention's author is whoever sent it: a name that closes the tag, an address and a picture that are scripts. On every list of
     * annotations (replies, likes, shares, mentions and RSVPs) they print as text, and the author links and shows no picture.
     */
    public function testAHostileWebmentionAuthorRendersInertOnEveryAnnotationList(): void
    {
        $this->seedSite();

        $out = $this->runInSite($this->pageCode('/status/x') . <<<'PHP'
        $status = null;
        foreach (\IdnoPlugins\Status\Status::get([], [], 50) as $candidate) {
            if ($status === null && $candidate->countAnnotations('reply') > 0) {
                $status = $candidate;
            }
        }
        $annotations = ['https://elsewhere.example/hostile' => [
            'permalink' => 'javascript:alert(3)',
            'owner_name' => '"><script>x()</script>',
            'owner_url' => 'javascript:alert(1)',
            'owner_image' => 'data:text/html,<script>y()</script>',
            'content' => '<script>z()</script>see https://ok.example/ "quoted" <img src=x onerror=w()>',
            'title' => '"><script>t()</script>',
            'rsvp' => 'yes',
            'time' => 1773000000,
        ]];
        $out = [];
        foreach (['replies', 'likes', 'shares', 'mentions', 'rsvps'] as $name) {
            $template = clone \Idno\Core\Idno::site()->template();
            $out[$name] = (string) $template->__(['annotations' => $annotations, 'object' => $status])->draw('entity/annotations/' . $name);
        }

        return $out;
        PHP);

        $this->assertIsArray($out);
        foreach ($out as $name => $drawn) {
            $this->assertNotSame('', trim((string) $drawn), $name . ' is drawn');
            $this->assertStringNotContainsString('<script', (string) $drawn, $name);
            $this->assertStringNotContainsString('<img src=x', (string) $drawn, $name);
            $this->assertStringNotContainsString('javascript:', (string) $drawn, $name);
            $this->assertStringNotContainsString('data:text', (string) $drawn, $name);
            $this->assertStringNotContainsString('<img class="idno-annotation-avatar"', (string) $drawn, $name . ' shows no picture for an address that is not http or https');
        }
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;x()&lt;/script&gt;', (string) $out['replies'], 'the name is text');
        $this->assertStringContainsString('rel="nofollow"', (string) $out['replies'], 'a link in a comment is nofollow');
    }

    /**
     * A member's note is escaped and its links are made: `see https://a.example/"><b>x` becomes text with a link in it and no `<b>`
     * element, in the feed of the home page and on the note's own page.
     */
    public function testAMembersStatusBodyIsEscapedAndLinked(): void
    {
        $this->seedSite();
        $cookie = $this->loginAsAdmin();
        $permalink = ltrim((string) parse_url($this->publishStatus($cookie, 'see https://a.example/"><b>x'), PHP_URL_PATH), '/');

        foreach (['' => 'the home page', $permalink => 'the permalink'] as $path => $where) {
            $page = $this->site()->request('GET', $path, ['Cookie' => $cookie]);
            $this->assertSame(200, $page->status, $where);
            $this->assertStringContainsString('see <a href="https://a.example/&quot;&gt;&lt;b&gt;x"', $page->body, $where . ': the address is linked, whole, in an attribute');
            $this->assertStringNotContainsString('<b>x', $page->body, $where . ': no b element from the post');
        }
    }

    /**
     * The image picker writes the translated words it puts into the page's script for a script: each character that a script could take
     * for syntax, and each space, is an escape (the old template printed the translation between single quotes as it was, so a
     * translation with an apostrophe ended the string).
     */
    public function testTheImagePickersScriptWritesItsWordsForAScript(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/filepicker/') . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->draw('file/picker/image');
        PHP);

        $this->assertStringContainsString("html('Choose\\u0020a\\u0020different\\u0020image')", $drawn);
        $this->assertStringNotContainsString("html('Choose a different image')", $drawn);
        $this->assertStringContainsString('Select an image', $drawn, 'the words of the page itself are as they were');
    }

    /**
     * What the file picker tells the editor that opened it is JSON for a script: the address of the file has no raw `/` or tag in it,
     * and it reads back as the address.
     */
    public function testTheFilePickersMessageIsWrittenForAScript(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/filepicker/') . <<<'PHP'
        $file = new \Idno\Entities\File();
        $file->file = ['_id' => 'abc"</script><script>x()</script>'];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['file' => $file])->draw('file/picker/donejs');
        PHP);

        $this->assertSame(1, substr_count($drawn, '</script>'), 'the id cannot end the script');
        $this->assertStringNotContainsString('<script>x()', $drawn);
        $this->assertSame(1, preg_match('/data: \{url: ("[^\n]*")\}\}, "\*"\);/', $drawn, $url));
        $this->assertSame(rtrim($this->baseUrl(), '/') . '/file/abc"</script><script>x()</script>', json_decode($url[1], true), 'the address, read back');
    }

    /**
     * The address a post replies to is the author's word, or another site's (a webmention or Micropub post names it): only an http or
     * https address is linked.
     */
    public function testAnInReplyToAddressThatIsNotHttpIsNotLinked(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/status/x') . <<<'PHP'
        $reply = new \IdnoPlugins\Status\Status();
        $reply->setOwner(\Idno\Entities\User::getByHandle('ada'));
        $reply->body = 'In reply';
        $reply->inreplyto = ['javascript:alert(1)', 'https://elsewhere.example/a?x=1&y=2'];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['object' => $reply])->draw('entity/shell');
        PHP);

        $this->assertStringNotContainsString('javascript:', $drawn);
        $this->assertStringNotContainsString('href=""', $drawn, 'an address that is refused is not linked: an empty href would be the post itself');
        $this->assertSame(1, substr_count($drawn, 'u-in-reply-to'), 'one link, for the address that is http');
        $this->assertStringContainsString('<a href="https://elsewhere.example/a?x=1&amp;y=2" rel="in-reply-to"', $drawn);
    }

    /**
     * An RSVP whose answer is not yes, maybe or no (a webmention may carry any word) is listed under "Other responses"; the old template
     * stopped with an undefined array key.
     */
    public function testAnRsvpWithAnAnswerThatIsNotYesMaybeOrNoIsListedAsOther(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/status/x') . <<<'PHP'
        $status = current(array_filter(\IdnoPlugins\Status\Status::get([], [], 50), static fn ($post) => $post->countAnnotations('reply') > 0));
        $annotation = static fn (string $name, string $answer, int $time): array => [
            'permalink' => 'https://example.org/' . $name, 'owner_name' => $name, 'owner_url' => 'https://example.org/', 'owner_image' => '',
            'content' => $name . ' says ' . $answer, 'time' => $time, 'rsvp' => $answer,
        ];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['object' => $status, 'annotations' => [
            'https://example.org/1' => $annotation('ann', 'perhaps', 1773000100),
            'https://example.org/2' => $annotation('bea', 'no', 1773000200),
            'https://example.org/3' => $annotation('cid', 'yes', 1773000300),
        ]])->draw('entity/annotations/rsvps');
        PHP);

        $this->assertSame(3, preg_match_all("#<h4>(.*?)</h4>#", $drawn, $titles));
        $this->assertSame(['Attending', 'Not attending', 'Other responses'], $titles[1] ?? [], 'the answers in their order, the unknown one last');
        $this->assertGreaterThan(strpos($drawn, 'Other responses'), strpos($drawn, 'ann says perhaps'));
    }

    /**
     * A link to where a post was syndicated is made from what a service returned: its address links only if it is http or https, and
     * its identifier is text.
     */
    public function testASyndicationLinkIsEscapedAndOnlyHttpIsLinked(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/status/x') . <<<'PHP'
        $status = current(array_filter(\IdnoPlugins\Status\Status::get([], [], 50), static fn ($post) => $post->countAnnotations('reply') > 0));
        $status->posse = ['zz' => [
            ['url' => 'javascript:alert(1)', 'identifier' => '<b>x</b> & "y"'],
            ['url' => 'data:text/html,x', 'identifier' => 'second'],
            ['url' => 'https://x.example/?a=1&b=2', 'identifier' => 'ok'],
        ]];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['object' => $status])->draw('content/syndication/links');
        PHP);

        $this->assertStringNotContainsString('javascript:', $drawn);
        $this->assertStringNotContainsString('<b>x</b>', $drawn);
        $this->assertStringContainsString('<a href="https://x.example/?a=1&amp;b=2"', $drawn);
        $this->assertSame(1, substr_count($drawn, 'class="u-syndication"'), 'only the http address is a link');
        $this->assertStringNotContainsString('href=""', $drawn);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt; &amp; &quot;y&quot;', $drawn, 'the identifier of the refused link is still shown, as text');
        $this->assertStringContainsString('&quot;y&quot; <i class="fa', $drawn, 'and a space follows it, so that the next item does not run into it');
        $this->assertStringContainsString('second <a href="https://x.example', $drawn, 'also the one before a link');
    }

    /**
     * A member's profile addresses are fields of the profile: the text is escaped (an address with a tag or a quote in it), and an
     * address that is a script links to `http://javascript:...`, as the address fixer has always made it, never to a script.
     */
    public function testAProfileFieldIsEscapedAndNeverLinksToAScript(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/profile/ada') . <<<'PHP'
        $user = \Idno\Entities\User::getByHandle('ada');
        $user->profile = ['url' => ['javascript:alert(1)', 'https://x.example/"><script>x()</script>', 'mailto:a@b.example?subject=<i>']];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['user' => $user])->draw('entity/User/profile/fields');
        PHP);

        $this->assertStringNotContainsString('<script>', $drawn);
        $this->assertStringNotContainsString('<i>', $drawn);
        $this->assertStringNotContainsString('href="javascript:', $drawn);
        $this->assertSame(3, substr_count($drawn, 'rel="me"'));
    }

    /**
     * The contact links of a profile are drawn for mailto, sms, tel and facetime addresses only: an address with another scheme (the old
     * template drew it, with an undefined icon, and stopped) is not a contact link.
     */
    public function testTheContactLinksPrintOnlyTheSchemesTheyKnow(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/profile/ada') . <<<'PHP'
        $user = \Idno\Entities\User::getByHandle('ada');
        $user->profile = ['url' => ['mailto:a@example.com', 'javascript:alert(1)', 'tel:+15555550100', 'https://example.org/', 'example.org/bare', 'sms:+1555', 'facetime:a@example.com']];
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['user' => $user])->draw('entity/User/profile/contact');
        PHP);

        $this->assertSame(4, substr_count($drawn, 'rel="me"'));
        $this->assertStringNotContainsString('javascript:', $drawn);
        $this->assertStringContainsString('class="u-email btn"', $drawn);
        $this->assertStringContainsString('class="p-facetime btn"', $drawn);
    }

    /**
     * The ActivityPub link in the head of a post's page is the actor's address when the last icon the page looked up was drawn as a
     * default avatar (the lookup leaves its user on the page's template, and the head uses it as the page's user), and the post's own
     * address when every picture was a real one (nothing was left). Both branches, with the owner's picture set and not set.
     */
    public function testTheActivityPubLinkOfAPostsHeadFollowsWhetherTheOwnerHasAPicture(): void
    {
        $paths = $this->seedSite();
        $link = static function (string $body): string {
            self::assertSame(1, preg_match('#<link rel="alternate" type="application/activity\+json" href="([^"]*)"#', $body, $match), 'the page has one ActivityPub link');

            return html_entity_decode($match[1], ENT_QUOTES);
        };

        $withoutPicture = $this->site()->request('GET', ltrim($paths['status'], '/'));
        $this->assertSame(200, $withoutPicture->status);
        $this->assertSame(rtrim($this->baseUrl(), '/') . '/actor/ada', $link($withoutPicture->body), 'the owner has no picture: a default avatar is drawn, and its user is the page\'s');

        $this->runInSite(<<<'PHP'
        $ada = \Idno\Entities\User::getByHandle('ada');
        $ada->icon = '0123456789abcdef0123456789abcdef';
        $ada->save(true);

        return true;
        PHP);
        $withPicture = $this->site()->request('GET', ltrim($paths['status'], '/'));
        $this->assertSame(200, $withPicture->status);
        $this->assertStringStartsWith(rtrim($this->baseUrl(), '/') . '/view/', $link($withPicture->body), 'a real picture leaves no user behind: the link is the post\'s own address');
        $this->assertStringNotContainsString('/actor/ada', $link($withPicture->body));
    }

    /**
     * The delete link of an RSVP names the RSVP by its address, as the links of the other annotation lists do: posted by the owner of
     * the post, it removes that RSVP and no other. (The old list renumbered the RSVPs when it sorted them, so its link named "0", which
     * the delete page read as no address, and the delete did nothing.)
     */
    public function testAnRsvpsDeleteLinkRemovesThatRsvpAndNoOther(): void
    {
        $paths = $this->seedSite();
        $cookie = $this->loginAsAdmin();
        $id = $this->literal((string) $paths['ids']['event']);
        $this->runInSite(<<<PHP
        \$event = \\Idno\\Common\\Entity::getByID($id);
        \$make = static fn (string \$name, int \$time): array => ['permalink' => 'https://example.org/' . \$name, 'owner_name' => \$name, 'owner_url' => 'https://example.org/' . \$name, 'owner_image' => '', 'content' => \$name . ' will come', 'time' => \$time, 'title' => '', 'rsvp' => 'yes'];
        \$event->annotations = ['rsvp' => [
            \$event->getURL() . '/annotations/first' => \$make('first', 1773000100),
            \$event->getURL() . '/annotations/second' => \$make('second', 1773000200),
        ]];
        \$event->save(true);

        return true;
        PHP);

        $page = $this->site()->request('GET', ltrim($paths['event'], '/'), ['Cookie' => $cookie]);
        $this->assertSame(200, $page->status);
        $this->assertSame(2, preg_match_all('#<a data-form-id="[^"]*"[^>]*href="([^"]*/annotation/delete\?permalink=([^"]*))"#', $page->body, $links), 'one delete link for each RSVP');
        $decoded = array_map(static fn (string $value): string => base64_decode(strtr($value, '-_,', '+/='), true) ?: '', $links[2]);
        $this->assertStringEndsWith('/annotations/first', $decoded[0]);
        $this->assertStringEndsWith('/annotations/second', $decoded[1], 'the link names the RSVP by its address');

        $action = substr(html_entity_decode($links[1][1], ENT_QUOTES), strlen(rtrim($this->baseUrl(), '/')) + 1);
        $response = (new \Tests\Acceptance\Browser($this->site()))->submit(ltrim($paths['event'], '/'), $action, [], $cookie);
        $this->assertContains($response->status, [200, 302, 303], $this->serverLog());

        $left = $this->runInSite(<<<PHP
        return array_keys(\\Idno\\Common\\Entity::getByID($id)->getAnnotations('rsvp') ?: []);
        PHP);
        $this->assertCount(1, $left, 'one RSVP is left');
        $this->assertStringEndsWith('/annotations/first', $left[0], 'the other one, not the one deleted');
    }

    /**
     * The picture and address of an annotation's author come from whoever sent it: an address that is a script, and a picture with a quote
     * in it, are not printed in the image link.
     */
    public function testAHostileAnnotationImageIsInert(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/status/x') . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['annotation' => ['owner_url' => 'javascript:alert(1)', 'owner_image' => 'https://x.example/a.png" onerror="y()', 'owner_name' => '"><script>z()</script>']])->draw('entity/annotations/image');
        PHP);

        $this->assertStringNotContainsString('javascript:', $drawn);
        $this->assertStringNotContainsString('onerror', $drawn);
        $this->assertStringNotContainsString('<script', $drawn);
        $this->assertSame(1, substr_count($drawn, '<img'), 'one image');
        $this->assertStringContainsString('src=""', $drawn, 'an address that is not http(s) prints nothing');
        $this->assertStringContainsString('alt="&quot;&gt;&lt;script&gt;z()&lt;/script&gt;"', $drawn);
    }

    /**
     * The onboarding shell's toggle script inserts `data-on` and `data-off` as HTML: the name of a syndication account in them is
     * HTML-escaped inside the attribute, so what the script inserts is the name as text.
     */
    public function testAnAccountNameInASyndicationToggleStaysTextWhenTheToggleInsertsItAsHtml(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/begin/connect/') . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['service' => 'zz', 'username' => 'u', 'name' => 'Carol <b>"C"</b> & co', 'disabled' => '', 'selected' => false])->draw('content/syndication/account');
        PHP);

        $this->assertSame(1, preg_match('/data-on="([^"]*)"/', $drawn, $match));
        $inserted = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        $this->assertStringNotContainsString('<b>', $inserted, 'what the toggle inserts holds no element made from the name');
        $this->assertStringContainsString('Carol', $inserted);
        $this->assertStringContainsString('&lt;b&gt;', $inserted, 'the name, escaped for HTML');
        $this->assertStringContainsString('<i class="fa fa-share-square-o"></i>', $inserted, 'the icon is the markup it was');
    }

    /**
     * Every value a control prints (a name, an id, a class, a placeholder, a value, the text of an option or of a tag, a form's address)
     * can be supplied by a member, a request or another site: a value with `"><script>` in it is read back as the same text from the
     * attribute or the element that holds it, and opens no element. The same fragment is drawn for a value that is not ASCII (printed as
     * itself, not as an entity) and for a text area whose value is "0" (a body of "0" is kept).
     */
    public function testFormValuesAreEscaped(): void
    {
        $this->seedSite();
        $hostile = '"><script>alert(1)</script>\'';

        $drawn = $this->runInSite($this->pageCode('/status/edit') . <<<'PHP'
        $hostile = '"><script>alert(1)</script>\'';
        $draw = static function (string $name, array $vars) {
            $template = clone \Idno\Core\Idno::site()->template();

            return (string) $template->__($vars)->draw($name);
        };
        $out = [];
        $out['input'] = $draw('forms/input/input', ['name' => $hostile, 'id' => $hostile, 'value' => $hostile, 'placeholder' => $hostile, 'alt' => $hostile, 'class' => $hostile, 'pattern' => $hostile, 'onclick' => $hostile]);
        $out['text'] = $draw('forms/input/text', ['name' => 'x', 'value' => $hostile, 'placeholder' => $hostile]);
        $out['hidden'] = $draw('forms/input/hidden', ['name' => 'x', 'value' => $hostile]);
        $out['non-ASCII'] = $draw('forms/input/text', ['name' => 'x', 'value' => "caf\u{e9} \u{2603}"]);
        $out['select'] = $draw('forms/input/select', ['name' => $hostile, 'id' => $hostile, 'options' => [$hostile => $hostile], 'value' => [$hostile], 'class' => $hostile, 'onchange' => $hostile]);
        $out['longtext'] = $draw('forms/input/longtext', ['name' => $hostile, 'value' => "</textarea>" . $hostile, 'unique_id' => $hostile, 'class' => $hostile, 'placeholder' => $hostile]);
        $out['longtext, zero'] = $draw('forms/input/longtext', ['name' => 'body', 'value' => '0']);
        $out['richtext'] = $draw('forms/input/richtext', ['name' => $hostile, 'value' => "</textarea>" . $hostile, 'unique_id' => $hostile]);
        $out['richtext, zero'] = $draw('forms/input/richtext', ['name' => 'body', 'value' => '0']);
        $out['tags'] = $draw('forms/output/tags', ['tags' => [$hostile, 'ok']]);
        $out['tag'] = $draw('forms/output/tags', ['tags' => $hostile]);
        $out['url'] = $draw('forms/output/url', ['value' => 'javascript:alert(1)', 'label' => '']);
        $out['url with a quote'] = $draw('forms/output/url', ['value' => 'https://example.org/"><script>x</script>', 'label' => $hostile]);
        $out['email'] = $draw('forms/output/email', ['value' => 'ada@example.com', 'label' => $hostile]);
        $out['text output'] = $draw('forms/output/text', ['value' => $hostile]);
        $out['form'] = $draw('forms/action', ['action' => $hostile, 'method' => 'post', 'body' => '<p>body</p>']);
        $out['token'] = $draw('forms/token', ['action' => $hostile]);
        $out['search'] = $draw('forms/usersearch', ['name' => $hostile, 'id' => $hostile, 'class' => $hostile, 'source-url' => $hostile, 'render-template' => $hostile]);
        $out['form list'] = $draw('forms/input/form-list', ['form' => ['a' => 'text', 'b' => 'text'], 'labels' => ['a' => 'A <i>one</i>', 'b' => $hostile], 'help' => ['a' => $hostile], 'defaults' => ['a' => $hostile, 'b' => ''], 'required' => ['a'], 'placeholders' => [], 'values' => []]);

        return $out;
        PHP);
        $this->assertIsArray($drawn);

        $elements = function (string $html, string $tag): array {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>');
            libxml_clear_errors();

            return iterator_to_array($dom->getElementsByTagName($tag));
        };
        foreach ($drawn as $label => $html) {
            $this->assertCount($label === 'search' ? 1 : 0, $elements($html, 'script'), $label . ' opens no script element but its own');
        }

        // An input: every attribute reads back as what was given
        [$input] = $elements($drawn['input'], 'input');
        foreach (['name', 'id', 'value', 'placeholder', 'alt', 'pattern', 'onclick'] as $attribute) {
            $this->assertSame($hostile, $input->getAttribute($attribute), 'the ' . $attribute . ' of an input');
        }
        $this->assertSame('idno-input ' . $hostile, $input->getAttribute('class'));
        $this->assertSame($hostile, $elements($drawn['text'], 'input')[0]->getAttribute('value'));
        $this->assertSame($hostile, $elements($drawn['text'], 'input')[0]->getAttribute('placeholder'));
        $this->assertSame($hostile, $elements($drawn['hidden'], 'input')[0]->getAttribute('value'));
        $this->assertStringContainsString('value="caf' . "\u{e9} \u{2603}" . '"', $drawn['non-ASCII'], 'non-ASCII text is printed as itself');

        // A select: the name, the id, the option's value and its text
        [$select] = $elements($drawn['select'], 'select');
        $this->assertSame($hostile, $select->getAttribute('name'));
        $this->assertSame($hostile, $select->getAttribute('id'));
        $this->assertSame($hostile, $select->getAttribute('onchange'));
        $option = $elements($drawn['select'], 'option')[1];
        $this->assertSame($hostile, $option->getAttribute('value'));
        $this->assertSame($hostile, $option->textContent);
        $this->assertTrue($option->hasAttribute('selected'));

        // The text areas: the value stays inside, whatever it holds
        [$longtext] = $elements($drawn['longtext'], 'textarea');
        $this->assertSame($hostile, $longtext->getAttribute('name'));
        $this->assertSame($hostile, $longtext->getAttribute('id'));
        $this->assertSame($hostile, $longtext->getAttribute('placeholder'));
        $this->assertSame('</textarea>' . $hostile, $longtext->textContent);
        $this->assertSame('0', $elements($drawn['longtext, zero'], 'textarea')[0]->textContent, 'a body of "0" is kept');
        $textareas = $elements($drawn['richtext'], 'textarea');
        $this->assertCount(1, $textareas);
        $this->assertSame('</textarea>' . $hostile, $textareas[0]->textContent);
        $this->assertSame($hostile, $textareas[0]->getAttribute('name'));
        $this->assertSame($hostile, $textareas[0]->getAttribute('id'));
        $editor = array_values(array_filter($elements($drawn['richtext'], 'div'), static fn (\DOMElement $div): bool => $div->hasAttribute('x-data')));
        $this->assertSame("tiptapEditor('" . '"><script>alert(1)</script>\\\'' . "')", $editor[0]->getAttribute('x-data'), 'the id is one JavaScript string');
        $this->assertSame('0', $elements($drawn['richtext, zero'], 'textarea')[0]->textContent);

        // Tags and outputs
        $links = $elements($drawn['tags'], 'a');
        $this->assertSame($hostile, $links[0]->textContent);
        $this->assertStringEndsWith('/tag/' . urlencode($hostile), $links[0]->getAttribute('href'));
        $this->assertSame($hostile, $elements($drawn['tag'], 'a')[0]->textContent);
        $this->assertSame('', $elements($drawn['url'], 'a')[0]->getAttribute('href'), 'an address that is not http or https is no link');
        $this->assertSame('javascript:alert(1)', $elements($drawn['url'], 'a')[0]->textContent);
        $this->assertStringNotContainsString('<script', $elements($drawn['url with a quote'], 'a')[0]->getAttribute('href'), 'a quote in an address does not end the attribute');
        $this->assertSame($hostile, $elements($drawn['url with a quote'], 'a')[0]->textContent);
        $this->assertSame($hostile, $elements($drawn['email'], 'a')[0]->textContent);
        $this->assertSame(htmlspecialchars($hostile, ENT_QUOTES), $drawn['text output']);

        // The form and its token: the address is text in the form's attribute and in the token's field
        $this->assertSame($hostile, $elements($drawn['form'], 'form')[0]->getAttribute('action'));
        $fields = array_values(array_filter($elements($drawn['token'], 'input'), static fn (\DOMElement $field): bool => $field->getAttribute('name') === '__bTa'));
        $this->assertSame($hostile, $fields[0]->getAttribute('value'));

        // The user search: its id and class, and the address and template its script is started with
        $div = $elements($drawn['search'], 'div')[0];
        $this->assertSame($hostile, $div->getAttribute('id'));
        $this->assertSame('users-search ' . $hostile, $div->getAttribute('class'));
        $this->assertSame("userSearch('" . '"><script>alert(1)</script>\\\'' . "', '" . '"><script>alert(1)</script>\\\'' . "')", $div->getAttribute('x-data'));

        // A list of fields: the labels and help are text, and only a required field is marked so
        $this->assertStringContainsString('A &lt;i&gt;one&lt;/i&gt;', $drawn['form list']);
        $this->assertSame(1, substr_count($drawn['form list'], ' required '), 'one field is required');
        $this->assertSame($hostile, $elements($drawn['form list'], 'p')[0]->textContent, 'the help is text');
    }

    /**
     * A remote page's h-card is another site's word: its name, photo and addresses print as text on the follow bookmarklet's page, and a
     * `javascript:` photo or address is neither an image nor a link. Fetched through the page, from the test route of the hostile card
     * (the remote client lets the site's own origin through in the test environment).
     */
    public function testARemoteHcardIsEscapedOnTheFollowBookmarklet(): void
    {
        $this->seedSite();
        $cookie = $this->loginAsAdmin();
        $card = $this->baseUrl() . '_test/hostile/hcard';

        $response = $this->site()->request('GET', 'account/settings/following/bookmarklet?u=' . rawurlencode($card), ['Cookie' => $cookie]);

        if ($response->status !== 200) {
            // The page cannot fetch the route here: the template is drawn with the card the page would give it, so that the escaping is still tested
            $this->markTestIncomplete('the page answered ' . $response->status . ': ' . substr(trim((string) preg_replace('/\s+/', ' ', strip_tags($response->body))), 0, 300) . $this->serverLog());
        }
        $this->assertHostileCardIsInert($response->body);
    }

    /**
     * The same card drawn by the template, as the page passes it: the name, a `javascript:` photo, `javascript:` addresses.
     */
    public function testAHostileHcardDrawnByTheFollowBookmarkletTemplateIsInert(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/account/settings/following/bookmarklet') . <<<'PHP'
        $name = \Tests\Fixtures\HostilePageController::NAME;
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['mf2' => ['properties' => ['name' => [$name], 'photo' => ['javascript:alert(2)'], 'url' => ['javascript:alert(1)', 'https://example.org/"><script>y()</script>'], 'email' => ['mailto:a"><script>z()</script>'], 'nickname' => [$name]]]])->draw('account/settings/following/mf2user');
        PHP);

        $this->assertHostileCardIsInert($drawn);
        $this->assertStringContainsString('&lt;/h1&gt;&lt;script&gt;x()&lt;/script&gt;', $drawn, 'the name is text');

        // A photo with an alt text is parsed as a list: its address is the photo (the old template printed none)
        $withAlt = (string) $this->runInSite($this->pageCode('/account/settings/following/bookmarklet') . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['mf2' => ['properties' => ['name' => ['Ada'], 'photo' => [['value' => 'https://example.org/ada.png', 'alt' => '']], 'url' => ['https://example.org/ada'], 'email' => [''], 'nickname' => ['']]]])->draw('account/settings/following/mf2user');
        PHP);
        $this->assertStringContainsString('src="https://example.org/ada.png"', $withAlt);
        $this->assertStringNotContainsString('<script', $drawn);
    }

    private function assertHostileCardIsInert(string $html): void
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>');
        libxml_clear_errors();
        $scripts = [];
        foreach ($dom->getElementsByTagName('script') as $script) {
            $scripts[] = $script->textContent;
        }
        $this->assertSame([], array_values(array_filter($scripts, static fn (string $text): bool => str_contains($text, 'x()') || str_contains($text, 'y()') || str_contains($text, 'z()'))), 'no script element the card opened');
        foreach (['src', 'href'] as $attribute) {
            foreach ($dom->getElementsByTagName('*') as $element) {
                if ($element->hasAttribute($attribute)) {
                    $this->assertDoesNotMatchRegularExpression('/^\s*javascript:/i', $element->getAttribute($attribute), 'a ' . $attribute . ' that runs a script');
                }
            }
        }
    }

    /**
     * What a request supplied to the password reset form (the email address and the code come from the link) is text in the fields: the old
     * template printed both as they were, so a value with a quote ended the attribute.
     */
    public function testThePasswordResetFormPrintsTheRequestsValuesAsText(): void
    {
        $this->seedSite();
        $hostile = '"><script>alert(1)</script>\'';

        $drawn = (string) $this->runInSite($this->pageCode('/account/password/reset/') . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();

        return $template->__(['email' => '"><script>alert(1)</script>\'', 'code' => '"><script>alert(1)</script>\''])->draw('account/password/reset');
        PHP);

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><body>' . $drawn . '</body>');
        libxml_clear_errors();
        $this->assertSame(0, $dom->getElementsByTagName('script')->length);
        $values = [];
        foreach ($dom->getElementsByTagName('input') as $input) {
            $values[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $this->assertSame($hostile, $values['email']);
        $this->assertSame($hostile, $values['code']);
    }

    /**
     * An address a remote card gives as its email keeps all its characters: the old template cut the first seven of every address, as if each
     * began with `mailto:`, so `ada@example.org` became `mple.org`. An address with `mailto:` loses that prefix, as before.
     */
    public function testTheFollowBookmarkletKeepsAnEmailThatIsNotAMailtoAddress(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/account/settings/following/bookmarklet') . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();
        $card = static fn (string $email): array => ['mf2' => ['properties' => ['name' => ['Ada'], 'photo' => [''], 'url' => ['https://example.org/ada'], 'email' => [$email], 'nickname' => ['']]]];

        return json_encode([(string) $template->__($card('ada@example.org'))->draw('account/settings/following/mf2user'), (string) $template->__($card('mailto:ada@example.org'))->draw('account/settings/following/mf2user')]);
        PHP);

        [$plain, $prefixed] = json_decode($drawn, true, 512, JSON_THROW_ON_ERROR);
        $this->assertMatchesRegularExpression('/type="email"[^>]*value="ada@example.org"/s', $plain);
        $this->assertMatchesRegularExpression('/type="email"[^>]*value="ada@example.org"/s', $prefixed);
    }

    /**
     * The values the account and onboarding pages write into a script are written for one: the page's own address in the API key's request,
     * the site's address in the bookmarklet (and in the continue button, which prints it as before), and the translated words of the profile page's preview. The old
     * templates printed each as it was (the address of a request, which holds what a visitor typed, could end the script).
     */
    public function testTheAccountAndOnboardingScriptsWriteTheirValuesForAScript(): void
    {
        $this->seedSite();
        $uri = '/account/settings/tools/?a=</script><script>alert(1)</script>&b="\'';

        $out = $this->runInSite($this->pageCode($uri) . <<<'PHP'
        $draw = static function (string $name, array $vars = []): string {
            $template = clone \Idno\Core\Idno::site()->template();

            return (string) $template->__($vars)->draw($name);
        };

        return ['tools' => $draw('account/settings/tools'), 'bookmarklet' => $draw('account/settings/following/bookmarklet'), 'connect' => $draw('onboarding/connect'), 'profile' => $draw('onboarding/profile', ['user' => \Idno\Entities\User::getByHandle('ada')])];
        PHP);
        $this->assertIsArray($out);

        $this->assertSame(1, substr_count($out['tools'], '<script'), 'the page has its one script, and the address did not open another');
        $this->assertStringNotContainsString('alert(1)</script>', $out['tools']);
        $this->assertStringContainsString("fetch('http\\u003A\\/\\/", $out['tools'], 'the address is one JavaScript string');
        $this->assertStringContainsString("location.href='http\\u003A\\/\\/", $out['bookmarklet']);
        $this->assertMatchesRegularExpression("#window.location = 'http://[^']*begin/publish'; return false;#", $out['connect'], 'the address of the next step is one JavaScript string in the attribute');
        $this->assertStringContainsString("html('Choose\\u0020different\\u0020photo')", $out['profile']);
    }

    /**
     * Every template of `forms/input/` is drawn with a hostile value in each place a control prints one (the list is the directory's, so a
     * control added later is drawn too), and the picture field with a hostile id and name, which it prints in four places: nothing opens a
     * script element or an attribute of its own.
     */
    public function testEveryFormInputIsDrawnWithHostileValues(): void
    {
        $this->seedSite();
        $names = array_map(static fn (string $file): string => basename($file, '.html.twig'), glob(dirname(__DIR__, 2) . '/templates/default/forms/input/*.html.twig') ?: []);
        sort($names);
        $this->assertContains('image-file', $names);
        $this->assertContains('datetime-local', $names);

        $drawn = $this->runInSite($this->pageCode('/status/edit') . str_replace('%NAMES%', $this->literal(json_encode($names, JSON_THROW_ON_ERROR)), <<<'PHP'
        $hostile = '"><script>alert(1)</script>\'';
        $out = [];
        foreach (json_decode(%NAMES%, true) as $name) {
            $vars = ['name' => $hostile, 'id' => $hostile, 'value' => $hostile, 'class' => $hostile, 'placeholder' => $hostile, 'alt' => $hostile, 'unique_id' => $hostile, 'options' => [$hostile => $hostile], 'title' => $hostile, 'onclick' => $hostile];
            if ($name === 'image-file') {
                $vars = ['name' => $hostile, 'id' => $hostile, 'object' => new \IdnoPlugins\Photo\Photo()];
            }
            if ($name === 'form-list') {
                $vars = ['form' => ['a' => 'text'], 'labels' => ['a' => $hostile], 'help' => ['a' => $hostile], 'defaults' => ['a' => $hostile], 'required' => ['a'], 'placeholders' => ['a' => $hostile], 'values' => ['a' => $hostile]];
            }
            $template = clone \Idno\Core\Idno::site()->template();
            $out[$name] = (string) $template->__($vars)->draw('forms/input/' . $name);
        }

        return $out;
        PHP));
        $this->assertIsArray($drawn);
        $this->assertSame($names, array_keys($drawn), 'every control was drawn');
        foreach ($drawn as $name => $html) {
            if ($name !== 'captcha') {
                $this->assertNotSame('', trim($html), $name . ' draws something');
            }
            $this->assertStringNotContainsString('<script>alert(1)', $html, $name . ' opens no script of the value\'s');
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>');
            libxml_clear_errors();
            foreach ($dom->getElementsByTagName('script') as $script) {
                $this->assertStringNotContainsString('alert(1)', $script->textContent, $name . ' puts the value into a script element');
            }
        }
        $image = $drawn['image-file'];
        $this->assertGreaterThanOrEqual(4, substr_count($image, htmlspecialchars('"><script>alert(1)</script>\'', ENT_QUOTES)), 'the id and name are text in each place the picture field prints them');
    }

    /**
     * A boolean attribute is printed when it is set and not otherwise: `true` sets it; `false`, `null`, `0`, `'0'` and an empty string do not
     * (the old template wrote `required=""` for `false`, which a browser reads as set). The same for the select. A text area's height is a number.
     */
    public function testABooleanAttributeIsPrintedOnlyWhenItIsSet(): void
    {
        $this->seedSite();

        $drawn = $this->runInSite($this->pageCode('/status/edit') . <<<'PHP'
        $draw = static function (string $name, array $vars): string {
            $template = clone \Idno\Core\Idno::site()->template();

            return (string) $template->__($vars)->draw($name);
        };
        $booleans = ['autofocus', 'checked', 'disabled', 'multiple', 'readonly', 'required'];
        $out = [];
        foreach (['true' => true, 'false' => false, 'null' => null, 'zero' => 0, 'the string zero' => '0', 'an empty string' => ''] as $label => $value) {
            $out['input, ' . $label] = $draw('forms/input/input', ['name' => 'f', 'value' => 'v'] + array_fill_keys($booleans, $value));
            $out['select, ' . $label] = $draw('forms/input/select', ['name' => 's', 'value' => '', 'options' => ['a' => 'A']] + array_fill_keys(array_diff($booleans, ['multiple']), $value));
            $out['select multiple, ' . $label] = $draw('forms/input/select', ['name' => 's', 'value' => '', 'options' => ['a' => 'A'], 'multiple' => $value]);
        }
        $out['value zero'] = $draw('forms/input/input', ['name' => 'f', 'value' => 0, 'maxlength' => 0, 'min' => '0']);
        $out['height'] = $draw('forms/input/longtext', ['name' => 'b', 'height' => '1px;position:fixed;inset:0;']);
        $out['height, a number'] = $draw('forms/input/longtext', ['name' => 'b', 'height' => 300]);

        return $out;
        PHP);
        $this->assertIsArray($drawn);

        $attributes = static function (string $html, string $tag): array {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>');
            libxml_clear_errors();
            $element = $dom->getElementsByTagName($tag)->item(0);
            $found = [];
            foreach ($element->attributes as $attribute) {
                $found[$attribute->name] = $attribute->value;
            }

            return $found;
        };
        $booleans = ['autofocus', 'checked', 'disabled', 'multiple', 'readonly', 'required'];
        foreach ($booleans as $attribute) {
            $this->assertArrayHasKey($attribute, $attributes($drawn['input, true'], 'input'), 'an input with ' . $attribute . ' set');
            if ($attribute !== 'multiple') {
                $this->assertArrayHasKey($attribute, $attributes($drawn['select, true'], 'select'));
            }
        }
        $this->assertArrayHasKey('multiple', $attributes($drawn['select multiple, true'], 'select'));
        foreach (['false', 'null', 'zero', 'the string zero', 'an empty string'] as $label) {
            foreach ($booleans as $attribute) {
                $this->assertArrayNotHasKey($attribute, $attributes($drawn['input, ' . $label], 'input'), $attribute . ' of an input is not set by ' . $label);
                $this->assertArrayNotHasKey($attribute, $attributes($drawn['select, ' . $label], 'select'), $attribute . ' of a select is not set by ' . $label);
            }
            $this->assertArrayNotHasKey('multiple', $attributes($drawn['select multiple, ' . $label], 'select'), 'a select is not multiple for ' . $label);
        }
        $value = $attributes($drawn['value zero'], 'input');
        $this->assertSame('0', $value['value'], 'a value of zero is printed');
        $this->assertSame('0', $value['maxlength']);
        $this->assertSame('0', $value['min']);

        $style = $attributes($drawn['height'], 'textarea')['style'];
        $this->assertSame('height:1px', $style, 'a height is a number: what follows it is not a declaration');
        $this->assertSame('height:300px', $attributes($drawn['height, a number'], 'textarea')['style']);
    }

    /**
     * The diagnostics and logs pages ask for their own address in a script, and the address holds what a visitor typed: it is written as
     * one JavaScript string, so a query with `</script>` in it opens no tag. (The old templates printed it as it was.)
     */
    public function testTheAdminScriptsWriteThePagesAddressForAScript(): void
    {
        $this->seedSite();
        $uri = '/admin/diagnostics/?a=</script><script>alert(1)</script>&b="\'';

        $out = $this->runInSite($this->pageCode($uri) . <<<'PHP'
        $draw = static function (string $name, array $vars = []): string {
            $template = clone \Idno\Core\Idno::site()->template();

            return (string) $template->__($vars)->draw($name);
        };

        return ['diagnostics' => $draw('admin/diagnostics', ['basics' => ['status' => 'Ok', 'report' => []]]), 'logs' => $draw('admin/logs')];
        PHP);
        $this->assertIsArray($out);

        foreach (['diagnostics', 'logs'] as $page) {
            $this->assertSame(1, substr_count($out[$page], '<script'), $page . ': the page has its one script, and the address did not open another');
            $this->assertStringNotContainsString('alert(1)</script>', $out[$page]);
            $this->assertStringContainsString("'http\\u003A\\/\\/", $out[$page], $page . ': the address is one JavaScript string');
        }
    }

    /**
     * What an administrator or a plugin typed and the admin pages print: a plugin's name and version in a dependency label, the labels and
     * values of the statistics, an invitation's address and a blocked address, a plugin's and a theme's descriptions, the site's title in
     * the settings, the tables the upgrade left. Each is escaped (the old templates printed the first four as they were). The one value
     * printed as it comes is a diagnostics message, which is HTML the page and the plugins that listen for `diagnostics/basics` write.
     */
    public function testTheAdminPagesEscapeWhatTheyPrint(): void
    {
        $this->seedSite();
        $hostile = '"><script>alert(1)</script>\'';

        $out = $this->runInSite($this->pageCode('/admin/') . <<<'PHP'
        $hostile = '"><script>alert(1)</script>\'';
        $site = \Idno\Core\Idno::site();
        $draw = static function (string $name, array $vars = []) use ($site): string {
            $template = clone $site->template();

            return (string) $template->__($vars)->draw($name);
        };
        $config = $site->config();
        $invitation = new \Idno\Entities\Invitation();
        $invitation->email = $hostile;
        $invitation->created = 1773000000;
        $config->config['blocked_emails'] = [$hostile];
        $config->config['title'] = $hostile;
        $config->config['smtp_host'] = $hostile;
        $out = [];
        $out['dependency'] = $draw('admin/dependencies/plugin', ['plugin' => $hostile, 'version' => $hostile, 'plugins_stored' => [], 'plugins_loaded' => []]);
        $out['statistics'] = $draw('admin/statistics', ['statistics' => [$hostile => [$hostile => $hostile, 'Nested' => [$hostile => $hostile]]]]);
        $out['users'] = $draw('admin/users', ['count' => 1, 'invitations' => [$invitation]]);
        $out['settings'] = $draw('admin/home');
        $out['email'] = $draw('admin/email', ['smtp_username' => $hostile, 'smtp_password' => $hostile]);
        $plugin = ['Plugin description' => ['name' => $hostile, 'version' => $hostile, 'author' => $hostile, 'author_url' => $hostile, 'description' => $hostile], 'shortname' => $hostile];
        $out['plugins'] = $draw('admin/plugins', ['plugins_loaded' => [], 'plugins_visible' => [$plugin]]);
        $theme = ['Theme description' => ['name' => $hostile, 'version' => $hostile, 'author' => $hostile, 'author_url' => $hostile, 'description' => $hostile], 'shortname' => $hostile];
        $out['themes'] = $draw('admin/themes', ['themes_stored' => [$hostile => $theme], 'theme' => '']);
        $out['legacy tables'] = $draw('admin/legacytables', ['tables' => [$hostile => 3], 'rejects' => [['collection' => $hostile, 'legacy_uuid' => $hostile, 'reason' => $hostile]], 'reject_count' => 1, 'reappeared' => [$hostile], 'restored' => true, 'restored_message' => $hostile]);
        $out['diagnostics'] = $draw('admin/diagnostics', ['basics' => ['status' => 'Failure', 'report' => ['x' => ['status' => 'Warning', 'message' => 'See <a href="https://example.org/update">the update</a>']]]]);

        return $out;
        PHP);
        $this->assertIsArray($out);

        foreach ($out as $page => $html) {
            $this->assertStringNotContainsString('<script>alert(1)', $html, $page . ': a script element a value opened');
            $this->assertStringNotContainsString('"><script', $html, $page . ': a value that ended its attribute');
            if ($page !== 'diagnostics') {
                $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, $page . ': the value is shown as text');
            }
        }
        $this->assertStringContainsString('See <a href="https://example.org/update">the update</a>', $out['diagnostics'], 'a diagnostics message is the HTML its writer made');
    }

    /**
     * The homepage settings page drew the icon of each content type inside an HTML comment, and a whole copy of its form inside another: neither
     * shows, and neither is drawn now.
     */
    public function testTheHomepageSettingsHoldNoCommentedOutMarkup(): void
    {
        $this->seedSite();

        $drawn = (string) $this->runInSite($this->pageCode('/admin/homepage/') . <<<'PHP'
        $template = clone \Idno\Core\Idno::site()->template();
        $types = \Idno\Common\ContentType::getRegistered();

        return $template->__(['content_types' => $types, 'default_content_types' => [$types[0]->getEntityClass()]])->draw('admin/homepage');
        PHP);

        $this->assertStringContainsString('name="default_feed_content[]"', $drawn, 'the page is drawn: the test is looking at it');
        $this->assertStringContainsString('checked="checked"', $drawn);
        $this->assertStringNotContainsString('<!--', $drawn);
    }

    /**
     * The email form shows the stored SMTP user name and password to the administrator, as it always has, and nowhere else: each is printed
     * once, in its own field, and neither is a variable of the page, which the JSON and JSONP output of a page prints (JSONP is readable by
     * another site).
     */
    public function testTheEmailFormPrintsTheStoredSecretsOnlyInTheirFieldsAndNeverAsAVariableOfThePage(): void
    {
        $this->seedSite();
        $admin = $this->loginAsAdmin();
        $this->runInSite(<<<'PHP'
        $config = \Idno\Core\Idno::site()->config();
        $config->config['smtp_host'] = 'smtp.example.org';
        $config->config['smtp_username'] = 'mailer-user-7731';
        $config->config['smtp_password'] = 'mailer-secret-9142';
        $config->save();

        return true;
        PHP);

        $html = $this->site()->request('GET', 'admin/email/', ['Cookie' => $admin])->body;

        $this->assertSame(1, substr_count($html, 'mailer-secret-9142'), 'the password is printed once');
        $this->assertSame(1, substr_count($html, 'mailer-user-7731'), 'the user name is printed once');
        $this->assertMatchesRegularExpression('/name="smtp_password"[^>]*value="mailer-secret-9142"/s', $html);
        $this->assertMatchesRegularExpression('/name="smtp_username"[^>]*value="mailer-user-7731"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/(data-[a-z-]+|<script)[^>]*mailer-secret-9142/s', $html);
        $response = $this->site()->request('GET', 'admin/email/?_t=json', ['Cookie' => $admin]);
        $this->assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
        $this->assertStringContainsString('"title"', $response->body, 'the JSON output is the page\'s variables: the test is looking at them');
        $this->assertStringNotContainsString('mailer-secret-9142', $response->body, 'the JSON output of the page has no password');
        $this->assertStringNotContainsString('mailer-user-7731', $response->body, 'the JSON output of the page has no user name');
    }

    /**
     * The plugins page lists the plugins the site does not hide, by name.
     */
    public function testThePluginsPageListsTheVisiblePluginsByName(): void
    {
        $this->seedSite();
        $admin = $this->loginAsAdmin();
        $this->runInSite(<<<'PHP'
        $config = \Idno\Core\Idno::site()->config();
        $config->config['hiddenplugins'] = ['Status'];
        $config->save();

        return true;
        PHP);

        $html = $this->site()->request('GET', 'admin/plugins/', ['Cookie' => $admin])->body;

        $this->assertStringNotContainsString('id="plugin-status"', $html, 'a hidden plugin is not listed');
        $this->assertGreaterThan(3, preg_match_all('/<div class="idno-plugin-name">\s*([^<]*?)\s*<span/', $html, $names));
        $names = $names[1];
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names, 'the plugins are listed by name');
    }

    /**
     * Draws a mail template in the site, as the mail adapter does: the HTML part (inside the email shell or not) and the plain text.
     * The site has no request (the queue worker has none either) unless $uri is given.
     *
     * @param  array<string, mixed> $vars
     * @return array{html: string, text: string}
     */
    private function drawMailInSite(string $template, array $vars, string $title = 'Fixture Site'): array
    {
        $this->seedSite();
        $code = <<<'PHP'
        \Idno\Core\Idno::site()->config()->title = %TITLE%;
        \Idno\Core\Idno::site()->config()->from_email = 'site@example.com';
        $draw = static function (string $type) {
            $template = clone \Idno\Core\Idno::site()->template();
            $template->setTemplateType($type);

            return (string) $template->__(json_decode(%VARS%, true))->draw(%NAME%);
        };

        return ['html' => $draw('email'), 'text' => $draw('email-text')];
        PHP;
        $drawn = $this->runInSite(str_replace(
            ['%TITLE%', '%VARS%', '%NAME%'],
            [$this->literal($title), $this->literal(json_encode($vars, JSON_THROW_ON_ERROR)), $this->literal($template)],
            $code
        ), true);
        $this->assertIsArray($drawn);

        return $drawn;
    }

    /**
     * The error mail shows what the failing request supplied (its user agent, query string and referrer, the message): as text in the HTML
     * part, and as it is in the plain text part.
     */
    public function testTheOopsMailEscapesRequestValues(): void
    {
        $vars = [
            'site' => 'https://example.org/', 'message' => 'Failed <b>hard</b> & more', 'user' => 'ada',
            'agent' => '<a href=//evil>x</a>', 'qs' => 'a="><script>x()</script>', 'referrer' => 'https://evil.example/"><a href=//evil>go</a>',
        ];

        $mail = $this->drawMailInSite('admin/oops', $vars);

        $this->assertStringNotContainsString('<a href=//evil', $mail['html']);
        $this->assertSame(0, substr_count($mail['html'], '<a '), 'no link is made of a value');
        $this->assertStringNotContainsString('<script', $mail['html']);
        $this->assertStringNotContainsString('<b>hard</b>', $mail['html']);
        $this->assertStringContainsString('&lt;a href=//evil&gt;x&lt;/a&gt;', $mail['html']);
        $this->assertStringContainsString('Referrer: https://evil.example/&quot;&gt;&lt;a href=//evil&gt;go&lt;/a&gt;', $mail['html']);
        $this->assertStringContainsString('Agent: <a href=//evil>x</a>', $mail['text'], 'the text part has the values as they are');
        $this->assertStringContainsString('QS: a="><script>x()</script>', $mail['text']);
        $this->assertStringContainsString('Referrer: https://evil.example/"><a href=//evil>go</a>', $mail['text']);
        $this->assertStringContainsString('Failed <b>hard</b> & more', $mail['text']);
    }

    /**
     * The inviter's name is a member's word: text in the HTML part (which also prints its title, "Join us!"), verbatim in the text part.
     */
    public function testAnInviteEscapesTheInvitersName(): void
    {
        $mail = $this->drawMailInSite('account/invite', ['inviter' => '<b>x', 'email' => 'friend@example.org', 'code' => 'abc']);

        $this->assertStringContainsString('<strong>&lt;b&gt;x</strong>', $mail['html']);
        $this->assertStringNotContainsString('<b>x', $mail['html']);
        $this->assertStringContainsString('Join us!', $mail['html'], 'the title the template always meant to print');
        $this->assertStringContainsString('Your friend *<b>x* has invited you', $mail['text']);
        $this->assertStringStartsWith("Join us!\n", $mail['text']);
    }

    /**
     * The site's title with an ampersand and apostrophes (and a tag) is escaped in the HTML parts and appears as it is in every plain
     * text part: a text mail is never HTML, so nothing in it is an entity.
     */
    public function testATextMailIsNeverHtmlEscaped(): void
    {
        $title = 'Fish & \'Chips\' <Co>';
        $this->seedSite();
        $cases = [
            'account/invite' => ['inviter' => 'A & B', 'email' => 'f@example.org', 'code' => 'abc'],
            'account/password' => ['email' => 'f@example.org', 'code' => 'abc'],
            'admin/emailtest' => [],
            'admin/export' => ['unrendered' => 2],
            'admin/export_failure' => [],
            'admin/import' => ['imported' => 3, 'skipped' => 1],
            'admin/import_failure' => ['stopped' => true, 'imported' => 2],
            'admin/oops' => ['site' => 'https://example.org/?a=1&b=2', 'message' => "It's <b>&</b>", 'user' => 'a&b', 'agent' => "A'B", 'qs' => 'a=1&b=2', 'referrer' => ''],
        ];
        foreach ($cases as $name => $vars) {
            $mail = $this->drawMailInSite($name, $vars, $title);
            $this->assertDoesNotMatchRegularExpression('/&(amp|lt|gt|quot|#0?39|#039);/', $mail['text'], $name . ': an entity in a text part');
            $this->assertStringNotContainsString('<html', $mail['text'], $name);
            $this->assertStringNotContainsString('<div', $mail['text'], $name);
        }
        $invite = $this->drawMailInSite('account/invite', $cases['account/invite'], $title);
        $this->assertStringContainsString('Your friend *A & B* has invited you to join ' . $title . ':', $invite['text']);
        $this->assertStringContainsString('Get started with *' . $title . '* by setting up your account.', $invite['text']);
        $this->assertStringContainsString('join <a href=', $invite['html']);
        $this->assertStringContainsString('Fish &amp; &#039;Chips&#039; &lt;Co&gt;</a>', $invite['html'], 'the HTML part escapes the title');
        $oops = $this->drawMailInSite('admin/oops', $cases['admin/oops'], $title);
        $this->assertStringContainsString("It's <b>&</b>", $oops['text']);
        $this->assertStringContainsString('https://example.org/?a=1&b=2', $oops['text']);
    }

    /**
     * PHP that makes the process a request for $uri served by a page: what the shell's templates read.
     */
    private function pageCode(string $uri): string
    {
        $uri = $this->literal($uri);

        return <<<PHP
        \$uri = $uri;
        \$_SERVER['REQUEST_URI'] = \$uri;
        \\Idno\\Core\\Idno::site()->setCurrentPage(new \\Idno\\Pages\\Onboarding\\Begin());
        \\Idno\\Core\\Idno::container()->get('request_stack')->push(new \\Symfony\\Component\\HttpFoundation\\Request([], [], [], [], [], ['REQUEST_URI' => \$uri]));

        PHP;
    }

    /**
     * A GET with the path as it is written, which a client library would encode: the body of the answer.
     */
    private function rawGet(string $path): string
    {
        $parts = parse_url($this->baseUrl());
        $socket = stream_socket_client('tcp://' . $parts['host'] . ':' . $parts['port'], $code, $message, 10);
        $this->assertIsResource($socket, $message);
        fwrite($socket, 'GET ' . $path . " HTTP/1.1\r\nHost: " . $parts['host'] . ':' . $parts['port'] . "\r\nConnection: close\r\n\r\n");
        $answer = (string) stream_get_contents($socket);
        fclose($socket);
        $this->assertStringStartsWith('HTTP/1.1 ', $answer);

        return substr($answer, (int) strpos($answer, "\r\n\r\n") + 4);
    }
}
