<?php

namespace Tests\Pages;

use IdnoPlugins\Status\Status;

/**
 * No form has a field for tags, but a member's browser, an API client and a Micropub client
 * can send one with a post, and what they send is shown to every visitor of the post.
 */
class PostTagsTest extends \Tests\BrowserTestCase
{

    public static function sentTagsProvider(): array
    {
        return [
            'a text' => ['x</a><img src=x onerror=alert(1)><script>alert(1)</script>'],
            'a list' => [['fine', '<img src=x onerror=alert(1)>', '<script>alert(1)</script>']],
            'a list of lists' => [['fine', ['<script>alert(1)</script>']]],
        ];
    }

    /**
     * A member who posts markup as a tag must not get an element into the page that visitors
     * of the post are shown.
     *
     * @dataProvider sentTagsProvider
     */
    public function testMarkupSentAsATagIsNotInThePostAVisitorReads($tags)
    {
        $member = static::member('tagger');
        $marker = static::marker();

        $this->submit('/status/edit', ['body' => 'Tagged ' . $marker, 'tags' => $tags, 'access' => 'PUBLIC'], $member, '/status/edit');

        $posts = Status::get(['owner' => $member->getUUID()]);
        $this->assertCount(1, $posts, 'The post should have been saved.');
        $post = $posts[0];

        $page = $this->get($post->getURL());
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Tagged ' . $marker, $page['body']);
        $this->assertStringNotContainsString('<img src=x', $page['body']);
        $this->assertStringNotContainsString('<script>alert(1)', $page['body']);

        foreach ((array) $post->tags as $tag) {
            $this->assertIsString($tag);
        }
    }

    /**
     * A tag is text: one with an angle bracket in it ("<3"), or an emoji made of several
     * joined ones, was cut short or taken apart when the post was saved.
     */
    public function testATagWithAnAngleBracketOrAJoinedEmojiIsKeptAndShown()
    {
        $member = static::member('hearttagger');
        $marker = static::marker();
        $emoji  = "\u{1F469}\u{200D}\u{1F4BB}";

        $this->submit('/status/edit', ['body' => 'Hearts ' . $marker, 'tags' => ['<3', $emoji], 'access' => 'PUBLIC'], $member, '/status/edit');

        $posts = Status::get(['owner' => $member->getUUID()]);
        $this->assertCount(1, $posts, 'The post should have been saved.');
        $this->assertSame(['<3', $emoji], $posts[0]->tags);

        $page = $this->get($posts[0]->getURL());
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('rel="tag">&lt;3</a>', $page['body']);
        $this->assertStringContainsString('rel="tag">' . $emoji . '</a>', $page['body']);
    }
}
