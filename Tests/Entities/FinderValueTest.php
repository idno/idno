<?php

namespace Tests\Entities {

    use Idno\Common\Entity;
    use Idno\Core\Idno;
    use Idno\Entities\GenericDataItem;
    use Idno\Entities\Invitation;
    use Idno\Entities\User;

    /**
     * The finders that look one thing up by a text (an email address, a handle, an address,
     * an id) find nothing for a value that is no text, and ask the database nothing: an array
     * would be read by the database as a condition, not as a value.
     */
    class FinderValueTest extends \Tests\IdnoTestCase
    {

        /// @var User
        protected static $member;

        /// @var GenericDataItem
        protected static $object;

        /// @var Invitation
        protected static $invitation;

        protected static $invited;

        public static function setUpBeforeClass(): void
        {
            $mark = bin2hex(random_bytes(5));

            $member = new User();
            $member->handle = 'finder' . $mark;
            $member->email = 'finder' . $mark . '@finder-test.example';
            $member->setPassword(bin2hex(random_bytes(12)));
            $member->setTitle('Finder ' . $mark);
            $member->save(true);
            static::$member = $member;

            $object = new GenericDataItem();
            $object->setDatatype('FinderValueTest');
            $object->setTitle('Finder object ' . $mark);
            $object->shorturl = 'fv' . $mark;
            $object->setAccess('PUBLIC');
            $object->save(true);
            static::$object = $object;

            static::$invited = 'invited' . $mark . '@finder-test.example';
            $invitation = new Invitation();
            $invitation->associateWithEmail(static::$invited);
            $invitation->save();
            static::$invitation = $invitation;
        }

        public static function tearDownAfterClass(): void
        {
            Idno::site()->db()->setIgnoreAccess(true);
            foreach ([static::$object, static::$invitation, static::$member] as $entity) {
                if ($entity) {
                    $entity->delete();
                }
            }
            Idno::site()->db()->setIgnoreAccess(false);
            static::$member = static::$object = static::$invitation = null;
        }

        /**
         * With plain text, each finder finds the fixture: the cases below would pass on an
         * empty database otherwise
         */
        public function testEachFinderFindsItsFixtureByText()
        {
            $member = static::$member;
            $object = static::$object;
            $this->assertSame($member->getUUID(), User::getByEmail($member->email)->getUUID());
            $this->assertSame($member->getUUID(), User::getByHandle($member->handle)->getUUID());
            $this->assertSame($member->getUUID(), User::getByProfileURL($member->getURL())->getUUID());
            $this->assertSame($object->getUUID(), Entity::getByID($object->getID())->getUUID());
            $this->assertSame($object->getUUID(), Entity::getByUUID($object->getUUID(), false)->getUUID());
            $this->assertSame($object->getUUID(), Entity::getByShortURL($object->shorturl)->getUUID());
            $this->assertSame($object->getUUID(), Entity::getByX($object->getID())->getUUID());
            $this->assertSame(static::$invitation->getUUID(), Invitation::getByEmail(static::$invited)->getUUID());
            $this->assertSame(static::$invitation->getUUID(), Invitation::getByEmailAndCode(static::$invited, static::$invitation->code)->getUUID());
            $this->assertCount(1, Invitation::allForEmail(static::$invited));
        }

        public static function valuesThatAreNoText()
        {
            return [
                'a list'               => [['a']],
                'an empty array'       => [[]],
                'greater than nothing' => [['$gt' => '']],
                'greater than a'       => [['$gt' => 'a']],
                'less than'            => [['$lt' => 'zzzzzzzz']],
                'not'                  => [['$not' => 'a']],
                'not in'               => [['$not' => ['$in' => ['a']]]],
                'not equal'            => [['$ne' => 'a']],
                'an expression'        => [['$regex' => '.*']],
                'exists'               => [['$exists' => true]],
                'null'                 => [null],
                'true'                 => [true],
                'false'                => [false],
                'a number'             => [7],
                'an object'            => [new \stdClass()],
            ];
        }

        /**
         * The fixture's own value inside "in": an array that names the right record is still
         * an array
         */
        protected function inList($value)
        {
            return ['$in' => [$value]];
        }

        /**
         * @dataProvider valuesThatAreNoText
         */
        public function testMemberFindersFindNothing($value)
        {
            $this->assertFalse(User::getByEmail($value));
            $this->assertFalse(User::getByHandle($value));
            $this->assertFalse(User::getByProfileURL($value));
        }

        public function testMemberFindersFindNothingForTheRightValueInAnArray()
        {
            $member = static::$member;
            $this->assertFalse(User::getByEmail($this->inList($member->email)));
            $this->assertFalse(User::getByEmail([$member->email]));
            $this->assertFalse(User::getByHandle($this->inList($member->handle)));
            $this->assertFalse(User::getByHandle([$member->handle]));
            $this->assertFalse(User::getByProfileURL($this->inList($member->getURL())));
            $this->assertFalse(User::getByProfileURL([$member->getURL()]));
        }

        /**
         * @dataProvider valuesThatAreNoText
         */
        public function testEntityFindersFindNothing($value)
        {
            $this->assertFalse(Entity::getByID($value));
            $this->assertFalse(Entity::getByUUID($value));
            $this->assertFalse(Entity::getByUUID($value, false));
            $this->assertFalse(Entity::getByShortURL($value));
            $this->assertFalse(Entity::getBySlug($value));
            $this->assertEmpty(Entity::getByX($value));
            $this->assertFalse(GenericDataItem::getByID($value));
        }

        public function testEntityFindersFindNothingForTheRightValueInAnArray()
        {
            $object = static::$object;
            $this->assertFalse(Entity::getByID($this->inList($object->getID())));
            $this->assertFalse(Entity::getByID([$object->getID()]));
            $this->assertFalse(Entity::getByUUID($this->inList($object->getUUID()), false));
            $this->assertFalse(Entity::getByUUID([$object->getUUID()], false));
            $this->assertFalse(Entity::getByShortURL($this->inList($object->shorturl)));
            $this->assertFalse(Entity::getByShortURL([$object->shorturl]));
            $this->assertEmpty(Entity::getByX($this->inList($object->getID())));
        }

        /**
         * An id that is an object with a text form (a MongoDB id) is still an id
         */
        public function testAnIdThatIsAnObjectWithATextForm()
        {
            $id = new class(static::$object->getID()) {
                private $id;

                public function __construct($id)
                {
                    $this->id = (string) $id;
                }

                public function __toString()
                {
                    return $this->id;
                }
            };
            $this->assertSame(static::$object->getUUID(), Entity::getByID($id)->getUUID());
        }

        /**
         * @dataProvider valuesThatAreNoText
         */
        public function testInvitationFindersFindNothing($value)
        {
            $code = static::$invitation->code;
            $this->assertFalse(Invitation::getByEmail($value));
            $this->assertSame([], Invitation::allForEmail($value));
            $this->assertFalse(Invitation::getByEmailAndCode($value, $code));
            $this->assertFalse(Invitation::validate($value, $code));
            $this->assertSame('invalid', Invitation::check($value, $code)['problem']);
            $this->assertFalse(Invitation::getByEmailAndCode(static::$invited, $value));
            $this->assertSame('invalid', Invitation::check(static::$invited, $value)['problem']);
        }

        public function testInvitationFindersFindNothingForTheRightValueInAnArray()
        {
            $code = static::$invitation->code;
            $this->assertFalse(Invitation::getByEmail($this->inList(static::$invited)));
            $this->assertFalse(Invitation::getByEmail([static::$invited]));
            $this->assertFalse(Invitation::getByEmailAndCode([static::$invited], $code));
            $this->assertFalse(Invitation::getByEmailAndCode(static::$invited, [$code]));
        }

        /**
         * Removing the invitations of an address that is no text removes none
         *
         * @dataProvider valuesThatAreNoText
         */
        public function testNoInvitationIsRemovedForAValueThatIsNoText($value)
        {
            Invitation::removeByEmail($value);
            $this->assertCount(1, Invitation::allForEmail(static::$invited));
        }
    }

}
