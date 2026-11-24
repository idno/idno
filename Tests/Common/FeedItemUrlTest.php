<?php

declare(strict_types=1);

namespace Tests\Common;

use Idno\Entities\Reader\FeedItem;

/**
 * A feed item's address comes from the markup of a site the member follows: it is kept only when it is an http or https address.
 */
final class FeedItemUrlTest extends \Tests\IdnoTestCase
{
    public function testAnHttpAddressIsKept(): void
    {
        $item = new FeedItem();
        $item->setURL('https://feed.example/a?x=1');

        $this->assertSame('https://feed.example/a?x=1', $item->url);
    }

    public function testAnyOtherAddressIsNoAddress(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,x', 'vbscript:x', 'relative/path', '', null, ['https://a.example/']] as $value) {
            $item = new FeedItem();
            $item->setURL($value);

            $this->assertEmpty($item->url, json_encode($value));
        }
    }

    public function testTheAddressOfAnItemLoadedFromMarkupIsChecked(): void
    {
        $item = new FeedItem();
        $item->loadFromMF2([['properties' => ['name' => ['A'], 'url' => ['javascript:alert(1)']]]]);

        $this->assertEmpty($item->url);

        $item = new FeedItem();
        $item->loadFromMF2([['properties' => ['name' => ['A'], 'url' => ['https://feed.example/post']]]]);

        $this->assertSame('https://feed.example/post', $item->url);
    }
}
