<?php

namespace Tests\Pages {

    use Idno\Core\Idno;

    /**
     * The feed reader (following a feed to read its items in the site) was removed in November
     * 2026: it could not follow a feed on PHP 8. Its five addresses answer as any address the
     * site does not have, for a visitor and for a member, whatever the method, and nothing of
     * it is left to call.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class FeedReaderRemovedTest extends \Tests\BrowserTestCase
    {

        private static $member;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$member = static::member('readergone');
        }

        public static function addresses()
        {
            $cases = [];
            foreach (['/following/', '/following', '/following/add/', '/following/confirm/', '/following/confirm/?feed=' . rawurlencode('https://example.com/'), '/following/refresh/', '/stream/', '/stream'] as $path) {
                foreach (['visitor', 'member'] as $who) {
                    foreach (['GET', 'POST'] as $method) {
                        $cases["$method $path as a $who"] = [$method, $path, $who];
                    }
                }
            }

            return $cases;
        }

        /**
         * @dataProvider addresses
         */
        public function testAddressIsNotFound($method, $path, $who)
        {
            $as = ($who === 'member') ? static::$member : null;
            $body = null;
            if ($method === 'POST') {
                // With a form token where the browser can have one, so that the token check
                // is not what refuses the request
                $body = ['url' => 'https://example.com/', 'feed' => 'https://example.com/'];
                if ($as) {
                    $body += $this->tokenFields($this->jar($as), (string) parse_url($path, PHP_URL_PATH));
                }
            }

            $missing = $this->request($method, '/no-such-page-' . static::marker() . '/', $body, $as);
            $this->assertSame(404, $missing['status'], 'An address the site does not have should answer 404.');

            $result = $this->request($method, $path, $body, $as);

            $this->assertSame(404, $result['status'], "$method $path should answer 404.");
            $this->assertSame('', $result['location'], "$method $path should not redirect.");
            $this->assertSame($this->title($missing['body']), $this->title($result['body']), "$method $path should answer with the site's page for an address it does not have.");
            if ($method === 'GET') {
                $this->assertStringContainsString("We couldn't find it", html_entity_decode($result['body'], ENT_QUOTES));
            }
        }

        public static function removedClasses()
        {
            return [
                ['Idno\Core\Reader'],
                ['Idno\Entities\Reader\Feed'],
                ['Idno\Entities\Reader\FeedItem'],
                ['Idno\Entities\Reader\Subscription'],
                ['Idno\Pages\Following\Home'],
                ['Idno\Pages\Following\Add'],
                ['Idno\Pages\Following\Confirm'],
                ['Idno\Pages\Following\Refresh'],
                ['Idno\Pages\Stream\Home'],
            ];
        }

        /**
         * @dataProvider removedClasses
         */
        public function testClassIsGone($class)
        {
            $this->assertFalse(class_exists($class), "$class was removed with the feed reader.");
        }

        public function testSiteHasNoReader()
        {
            $this->assertFalse(method_exists(Idno::site(), 'reader'), 'The site object should have no reader() any more.');
        }

        public static function removedTemplates()
        {
            return [
                ['following/home'], ['following/add'], ['following/confirm'], ['stream/home'],
                ['entity/FeedItem'], ['entity/Subscription'], ['content/feed/end'],
            ];
        }

        /**
         * @dataProvider removedTemplates
         */
        public function testTemplateIsGone($template)
        {
            $root = dirname(__DIR__, 2);
            $found = array_merge(
                glob($root . '/templates/*/' . $template . '.tpl.php') ?: [],
                glob($root . '/Themes/*/templates/*/' . $template . '.tpl.php') ?: [],
                glob($root . '/IdnoPlugins/*/templates/*/' . $template . '.tpl.php') ?: []
            );

            $this->assertSame([], $found, "No look should still have the template $template.");
        }

        public function testNoPageLinksToTheReader()
        {
            foreach (['/', '/account/settings/', '/account/settings/following/', '/profile/' . static::$member->getHandle()] as $path) {
                $page = $this->get($path, static::$member);
                $this->assertSame(200, $page['status'], "$path should be served to a member.");
                preg_match_all('#(?:href|action)="([^"]*)"#', $page['body'], $links);
                foreach ($links[1] as $link) {
                    $linkPath = (string) parse_url(html_entity_decode($link), PHP_URL_PATH);
                    $this->assertDoesNotMatchRegularExpression('#^/(following|stream)(/|$)#', $linkPath, "$path should not link to the removed reader ($link).");
                }
            }
        }

        private function title($html)
        {
            return preg_match('#<title>(.*?)</title>#s', $html, $match) ? trim($match[1]) : '';
        }
    }

}
