<?php

declare(strict_types=1);

namespace Tests\Templating;

use Tests\Acceptance\Browser;

/**
 * Markdown over HTTP where the test changes the site (a member's name, a post with hostile values, a plugin and its settings): each
 * test has a seeded site of its own. The tests that only read share one: `MarkdownOutputTest`.
 */
final class MarkdownOutputChangedSiteTest extends MarkdownSiteTestCase
{
    public function testHostileValuesStayValuesOfTheirOwnFields(): void
    {
        $this->seedSite();
        $title = "- Evil: \"title\" 'q' # not a comment\nadmin: true\n---\n# pwned \\";
        $name = '# Bob: "the" \'member\' - [x] {y}';
        $path = $this->runInSite(
            '$site = \Idno\Core\Idno::site();'
            . '$bob = \Idno\Entities\User::getByHandle("bob"); $bob->setTitle(' . $this->literal($name) . '); $bob->save(true);'
            . '$e = new \IdnoPlugins\Text\Entry(); $e->_id = "00000000-0000-4000-8000-000000000099";'
            . '$e->title = ' . $this->literal($title) . ';'
            . '$e->body = ' . $this->literal('<p>Body <script>alert("x")</script><img src="x" onerror="alert(1)" alt="pic"> <a href="javascript:alert(2)">link</a></p><p>---</p><p>injected: true</p>') . ';'
            . '$e->tags = "#safe";'
            . '$e->setOwner($bob); $e->setAccess("PUBLIC"); $e->setPublishStatus("published"); $e->created = 1772366400; $e->setSlugResilient("hostile-post");'
            . 'if (!$e->save(true)) { throw new \RuntimeException("not saved"); }'
            . 'return substr($e->getURL(), strlen($site->config()->getURL()));'
        );
        $this->assertIsString($path);

        $response = $this->get($path . '.md');
        $this->assertMarkdown($response, $this->baseUrl() . $path);
        [$fields, $body] = $this->parse($response->body);

        $this->assertSame(['title', 'author', 'pubDate', 'description', 'link', 'guid', 'category'], array_keys($fields), 'no field was added');
        $this->assertSame('- Evil: "title" \'q\' # not a comment admin: true --- # pwned \\', $fields['title'], 'the title is one string, on one line');
        $this->assertSame($name, $fields['author']);
        $this->assertSame(['safe'], $fields['category']);
        $this->assertStringNotContainsString('alert', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertDoesNotMatchRegularExpression('/<[a-zA-Z\/!]/', $body);
        $this->assertStringContainsString('Body', $body);
    }

    /**
     * The settings pages hold secrets in the fields of their forms. As Markdown they are their text only: every stored secret
     * is absent from the document, for the administrator, for a member and for a visitor.
     */
    public function testTheSettingsPagesAsMarkdownHoldNoSecret(): void
    {
        $this->seedSite();
        $this->assertTrue($this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->plugins()->enable('Styles');

        return true;
        PHP));
        $this->assertTrue($this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $config = $site->config();
        $config->config['smtp_host'] = 'smtp.example.org';
        $config->config['smtp_username'] = 'mailer-user-7731';
        $config->config['smtp_password'] = 'mailer-secret-9142';
        $config->webhook_syndication = [['title' => 'Chat room', 'url' => 'https://hooks.example/services/T000/key-hook-8812']];
        $config->styles = ['css' => 'body.custom-sheet-5530 { color: red }'];
        $config->save();
        foreach (['ada' => 'A', 'bob' => 'B'] as $handle => $mark) {
            $user = \Idno\Entities\User::getByHandle($handle);
            $user->apikey = 'api-key-' . $mark . '-3391';
            $user->indieauth_tokens = ['token-secret-' . $mark . '-6620' => ['client_id' => 'https://client.example/', 'redirect_uri' => 'https://client.example/cb', 'scope' => 'create', 'issued_at' => 1773000000]];
            $user->bridgy = ['twitter' => ['status' => 'enabled', 'user' => $handle, 'key' => 'bridgy-key-' . $mark . '-4471']];
            $user->save(true);
        }

        return true;
        PHP));
        $secrets = ['mailer-user-7731', 'mailer-secret-9142', 'key-hook-8812', 'custom-sheet-5530', 'api-key-A-3391', 'api-key-B-3391', 'token-secret-A-6620', 'token-secret-B-6620', 'bridgy-key-A-4471', 'bridgy-key-B-4471'];
        $pages = ['admin/email/', 'account/settings/tools/', 'account/indiepub/', 'account/bridgy/', 'admin/webhooks/', 'admin/styles/', 'account/settings/', 'admin/'];

        $ada = $this->loginAsAdmin();
        $bob = (new Browser($this->site()))->login(self::MEMBER, self::PASSWORD);
        // The form token of each session, from the HTML of the same session: it is a secret too
        $tokens = [];
        foreach (['the administrator' => $ada, 'a member' => $bob] as $who => $cookie) {
            $this->assertSame(1, preg_match('/name="__bTk" value="([0-9a-f]{64})"/', $this->get('account/settings/', ['Cookie' => $cookie])->body, $found), $who . ' has a token in the HTML');
            $tokens[$who] = $found[1];
        }

        // The test is looking at pages that hold the secrets: the HTML of the administrator's has each of these
        foreach (['account/indiepub/' => 'token-secret-A-6620', 'account/bridgy/' => 'bridgy-key-A-4471', 'admin/webhooks/' => 'key-hook-8812', 'admin/styles/' => 'custom-sheet-5530'] as $page => $secret) {
            $this->assertStringContainsString($secret, $this->get($page, ['Cookie' => $ada])->body, $page . ' holds its secret as HTML');
        }

        $drawn = 0;
        foreach (['the administrator' => ['Cookie' => $ada], 'a member' => ['Cookie' => $bob], 'a visitor' => []] as $who => $cookie) {
            foreach ($pages as $page) {
                foreach (['markdown', 'md'] as $type) {
                    $html = $this->get($page, $cookie);
                    $response = $this->get($page . '?_t=' . $type, $cookie);
                    $this->assertSame($html->status, $response->status, $page . ' for ' . $who . ': the status of the HTML page');
                    if ($response->status >= 300 && $response->status < 400) {
                        continue;
                    }
                    $this->assertSame('markdown', $this->kind($response), $page . ' for ' . $who);
                    foreach ($secrets as $secret) {
                        $this->assertStringNotContainsString($secret, $response->body, $page . ' for ' . $who);
                    }
                    $this->assertDoesNotMatchRegularExpression('/<[a-zA-Z\/!]/', $this->parse($response->body)[1], $page . ' for ' . $who . ': no HTML is left');
                    $this->assertStringNotContainsString('__bTk', $response->body, 'no form token');
                    if (isset($tokens[$who])) {
                        $this->assertStringNotContainsString($tokens[$who], $response->body, $page . ' for ' . $who . ': not the session\'s token');
                    }
                    $drawn += $response->status === 200 ? 1 : 0;
                }
            }
        }
        $this->assertGreaterThanOrEqual(2 * count($pages), $drawn, 'the administrator was shown every page');
    }

    /**
     * Which pages are settings pages is decided from how their route was registered, not from the address as written: a
     * doubled slash (the router matches it) and a request seen as https on a site configured for http are still `/admin`.
     */
    public function testAnAdminPageIsASettingsPageWhateverWayItsAddressIsWritten(): void
    {
        $this->seedSite();
        $this->assertTrue($this->runInSite(<<<'PHP'
        $site = \Idno\Core\Idno::site();
        $site->plugins()->enable('Styles');
        $config = $site->config();
        $config->config['smtp_host'] = 'smtp.example.org';
        $config->config['smtp_username'] = 'mailer-user-7731';
        $config->config['smtp_password'] = 'mailer-secret-9142';
        $config->webhook_syndication = [['title' => 'Chat room', 'url' => 'https://hooks.example/services/T000/key-hook-8812']];
        $config->styles = ['css' => 'body.custom-sheet-5530 { color: red }'];
        $config->save();

        return true;
        PHP));
        $ada = $this->loginAsAdmin();
        $secrets = ['mailer-user-7731', 'mailer-secret-9142', 'key-hook-8812', 'custom-sheet-5530'];

        foreach ([
            '//admin/email/?_t=markdown' => [],
            'admin/email/?_t=markdown' => ['X-Forwarded-Proto' => 'https'],
            '//admin/webhooks/?_t=markdown' => [],
            'admin/webhooks/?_t=markdown' => ['X-Forwarded-Proto' => 'https'],
            'admin/styles/?_t=markdown' => ['X-Forwarded-Proto' => 'https'],
            '//admin/styles/?_t=md' => [],
        ] as $path => $headers) {
            $response = $this->get($path, ['Cookie' => $ada] + $headers);

            $this->assertSame(200, $response->status, $path);
            $this->assertSame('markdown', $this->kind($response), $path);
            $this->assertStringContainsString('This page is a settings page and has no Markdown form.', $response->body, $path);
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $response->body, $path);
            }
        }
    }
}
