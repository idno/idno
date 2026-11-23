<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * The admin's user search loads its results with a script and puts them into the page, so the forms that its action links submit (Make admin,
 * Block, Delete) must come with the rows: the page appends them when the request is an XHR one. The search's script sends the
 * `X-Requested-With` header that makes it one.
 */
final class UserSearchActionLinksTest extends RenderedSiteTestCase
{
    protected bool $compareGoldens = false;

    public function testTheSearchResultsOfAnXhrRequestCarryTheFormsOfTheirActionLinks(): void
    {
        $this->seedSite();
        $cookie = $this->loginAsAdmin();

        $response = $this->site()->request('GET', 'search/users/?query=&template=forms/components/usersearch/user&sort=created&order=desc&limit=100&offset=0', ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest']);

        $this->assertSame(200, $response->status, substr($response->body, 0, 500));
        $rendered = (string) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['rendered'] ?? '');
        $this->assertSame(1, preg_match_all('/data-form-id="(f[0-9a-f]{13})"/', $rendered, $links) > 0 ? 1 : 0, 'the rows have action links');
        foreach ($links[1] as $id) {
            $this->assertStringContainsString('<form action=', $rendered);
            $this->assertStringContainsString('style="display: none; margin: 0; padding: 0" id="' . $id . '"', $rendered, 'the form of the link ' . $id . ' is in the results');
        }
        $this->assertStringContainsString('name="__bTk"', $rendered, 'and is signed');

        // Without the header the request is not an XHR one: the links are there and their forms are not (which is why the script sends it)
        $plain = $this->site()->request('GET', 'search/users/?query=&template=forms/components/usersearch/user&limit=100', ['Cookie' => $cookie]);
        $without = (string) (json_decode($plain->body, true, 512, JSON_THROW_ON_ERROR)['rendered'] ?? '');
        $this->assertStringContainsString('data-form-id="', $without);
        $this->assertStringNotContainsString('<form action=', $without);
    }

    public function testTheSearchScriptSendsTheHeaderThatMakesItsRequestAnXhrOne(): void
    {
        $this->seedSite();
        $cookie = $this->loginAsAdmin();

        $page = $this->site()->request('GET', 'admin/users/', ['Cookie' => $cookie]);

        $this->assertSame(200, $page->status);
        $this->assertMatchesRegularExpression("/fetch\\(sourceUrl \\+ '\\?' \\+ params\\.toString\\(\\), \\{ headers: \\{ 'X-Requested-With': 'XMLHttpRequest' \\} \\}\\)/", $page->body);
    }
}
