<?php

declare(strict_types=1);

namespace Tests\Characterisation;

/**
 * A post whose title has accents, an emoji or spaces is served at its own address, by the address the site
 * gives it (the slug is stored percent-encoded; the web server hands the router the path decoded, and a client
 * may send it either way).
 */
final class SlugRoutingTest extends CharacterisationTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function titles(): array
    {
        return [
            'an accent' => ['Café notes'],
            'accents and an emoji' => ['Crème brûlée 🍮 recipe'],
            'spaces' => ['A title with   several spaces'],
            'a non-Latin title' => ['Ünïcödé Заметки'],
        ];
    }

    /**
     * @dataProvider titles
     */
    public function testAPostIsServedAtItsOwnAddress(string $title): void
    {
        $post = $this->createArticle($this->admin(), $title, 'Body of ' . $title);
        $path = substr($post->getURL(), strlen($this->site()->baseUrl()));

        $encoded = $this->site()->request('GET', $path);
        $decoded = $this->site()->request('GET', rawurldecode($path));

        $this->assertSame(200, $encoded->status, 'the address as the site writes it: ' . $path);
        $this->assertStringContainsString('Body of', $encoded->body);
        $this->assertSame(200, $decoded->status, 'the address with the title as typed');
        $this->assertContains($this->site()->request('GET', $path . '-nothing-here')->status, [404, 410], 'an address that is none is still not found');
    }

    public function testAStaticPageWithAnAccentedTitleIsServedAtItsAddress(): void
    {
        $page = new \IdnoPlugins\StaticPages\StaticPage();
        $page->title = 'À propos de nous';
        $page->body = 'Body of the about page';
        $this->registerFixture($page);
        $page->setOwner($this->admin());
        $page->setAccess('PUBLIC');
        $page->setPublishStatus('published');
        $page->setSlugResilient('À propos de nous');
        $page->save(true);
        $url = $page->getURL();
        $this->assertStringContainsString('/pages/', $url);
        $this->assertStringContainsString('%C3%', $url, 'the address holds the accent, encoded');
        $path = substr($url, strlen($this->site()->baseUrl()));

        $this->assertSame(200, $this->site()->request('GET', $path)->status, 'encoded');
        $this->assertSame(200, $this->site()->request('GET', rawurldecode($path))->status, 'as typed');
    }
}
