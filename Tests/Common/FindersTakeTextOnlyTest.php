<?php

declare(strict_types=1);

namespace Tests\Common;

use Idno\Common\Entity;
use Idno\Entities\Invitation;
use Idno\Entities\UnfurledUrl;
use Idno\Entities\User;
use IdnoPlugins\Status\Status;

/**
 * Catches a lookup (a finder called with `email[]=x` or `code[$lt]=`) that took an array for its value and so matched the newest record, or any record,
 * instead of nothing. A request can send an array where text is expected (`email[]=x`, `email[$gt]=a`, a JSON object). A finder that
 * is handed one finds nothing: it never lets the value drop or bend the condition of its lookup, which would
 * otherwise answer with the newest record the viewer may read.
 */
final class FindersTakeTextOnlyTest extends \Tests\IdnoTestCase
{
    private ?Status $post = null;
    private ?User $member = null;
    private ?UnfurledUrl $unfurled = null;

    public function setUp(): void
    {
        $this->swapUser($this->admin());
        $this->member = new User();
        $this->member->handle = 'finders-text-only';
        $this->member->email = 'finders-text-only@example.test';
        $this->member->setPassword('a-password-of-sufficient-length');
        $this->member->setTitle('Finders');
        $this->member->save(true);

        $this->post = new Status();
        $this->post->body = 'A post the finders are asked for';
        $this->post->setAccess('PUBLIC');
        $this->post->save();
        $this->post->getShortURL(false);

        $this->unfurled = new UnfurledUrl();
        $this->unfurled->source_url = 'http://finders.example.test/page';
        $this->unfurled->save(true);
    }

    public function tearDown(): void
    {
        foreach ([$this->post, $this->member, $this->unfurled] as $entity) {
            $entity?->delete();
        }
        $this->post = $this->member = $this->unfurled = null;
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function valuesThatAreNotText(): array
    {
        return [
            'a plain list' => [['x']],
            'an empty list' => [[]],
            'a map with $gt' => [['$gt' => 'a']],
            'a range' => [['$gt' => 'a', '$lt' => 'zzzzzzzz']],
            'a map with $not' => [['$not' => 'zzz']],
            'an object' => [new \stdClass()],
            'a number' => [12],
            'null' => [null],
        ];
    }

    public function testThePositiveControlsFindTheirRecords(): void
    {
        $this->assertInstanceOf(User::class, User::getByEmail('finders-text-only@example.test'));
        $this->assertInstanceOf(User::class, User::getByHandle('finders-text-only'));
        $this->assertInstanceOf(Entity::class, Entity::getByID($this->post->getID()));
        $this->assertInstanceOf(Entity::class, Entity::getByUUID($this->post->getUUID(), false));
        $this->assertInstanceOf(Entity::class, Entity::getByShortURL($this->post->getShortURL(false)));
        $this->assertInstanceOf(UnfurledUrl::class, UnfurledUrl::getBySourceURL('http://finders.example.test/page'));
    }

    /**
     * @dataProvider valuesThatAreNotText
     */
    public function testUserFindersFindNothing(mixed $value): void
    {
        $this->assertFalse(User::getByEmail($value));
        $this->assertFalse(User::getByHandle($value));
        $this->assertFalse(User::getByProfileURL($value));
    }

    /**
     * @dataProvider valuesThatAreNotText
     */
    public function testEntityFindersFindNothing(mixed $value): void
    {
        $this->assertFalse(Entity::getByID($value));
        $this->assertFalse(Entity::getByUUID($value));
        $this->assertFalse(Entity::getByUUID($value, false));
        $this->assertFalse(Entity::getByShortURL($value));
        $this->assertFalse(Entity::getBySlug($value));
        $this->assertFalse(Entity::getByX($value));
        $this->assertFalse(Entity::getByURL($value));
        $this->assertFalse(\Idno\Entities\File::getByID($value));
    }

    /**
     * @dataProvider valuesThatAreNotText
     */
    public function testTheOtherFindersFindNothing(mixed $value): void
    {
        $this->assertFalse(UnfurledUrl::getBySourceURL($value));
        $this->assertFalse(Invitation::getByEmail($value));
        $this->assertFalse(Invitation::getByEmailAndCode($value, 'any'));
        $this->assertFalse(Invitation::validate($value, 'any'));
    }
}
