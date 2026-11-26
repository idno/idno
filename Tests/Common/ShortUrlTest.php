<?php

declare(strict_types=1);

namespace Tests\Common;

use Idno\Common\Entity;
use IdnoPlugins\Status\Status;

/**
 * An entity is given a short address when it is first asked for one, and any number of entities may be given one in a process (a queue worker, an import):
 * the function that writes a number as a code was declared inside the method, so a second call ended the process.
 */
final class ShortUrlTest extends \Tests\IdnoTestCase
{
    /** @var list<Status> */
    private array $posts = [];

    public function setUp(): void
    {
        $this->swapUser($this->admin());
    }

    public function tearDown(): void
    {
        foreach ($this->posts as $post) {
            $post->delete();
        }
        $this->posts = [];
    }

    public function testASecondShortAddressInTheSameProcessIsMade(): void
    {
        $codes = [];
        foreach (['one', 'two', 'three'] as $body) {
            $post = new Status();
            $post->body = 'A post given a short address: ' . $body;
            $post->setAccess('PUBLIC');
            $post->save();
            $this->posts[] = $post;
            $code = $post->getShortURL(false);
            $this->assertMatchesRegularExpression('/\A[0-9a-zA-Z]+\z/', $code);
            $codes[] = $code;
        }

        $this->assertCount(3, array_unique($codes), 'each has a code of its own');
        foreach ($codes as $n => $code) {
            $this->assertSame($this->posts[$n]->getID(), Entity::getByShortURL($code)->getID(), 'and the code finds the post');
        }
    }
}
