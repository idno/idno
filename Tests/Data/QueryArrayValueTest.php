<?php

namespace Tests\Data {

    use Idno\Core\Idno;
    use Idno\Entities\GenericDataItem;

    /**
     * A field value of a query that is an array is a condition only when it is a map made of
     * the operators the database back end knows, each with an operand it can use. Any other
     * array (a plain list, an empty array, a map with a key that is no operator, an operator
     * with an operand of the wrong shape) makes the query match nothing. It is never left out
     * of the query, which would make the query match more than its caller asked for.
     *
     * These run against the database the site is configured with.
     */
    class QueryArrayValueTest extends \Tests\IdnoTestCase
    {

        protected static $datatype;

        /// @var GenericDataItem
        protected static $object;

        public static function setUpBeforeClass(): void
        {
            static::$datatype = 'ArrayQuery' . bin2hex(random_bytes(6));
            $object = new GenericDataItem();
            $object->setDatatype(static::$datatype);
            $object->setTitle('Array query object');
            $object->arrayq = 'm';
            $object->arrayother = 'other';
            $object->setAccess('PUBLIC');
            $object->save(true);
            static::$object = $object;
        }

        public static function tearDownAfterClass(): void
        {
            Idno::site()->db()->setIgnoreAccess(true);
            if (static::$object) {
                static::$object->delete();
            }
            Idno::site()->db()->setIgnoreAccess(false);
            static::$object = null;
        }

        protected function isSQL()
        {
            return Idno::site()->db() instanceof \Idno\Data\AbstractSQL;
        }

        protected function matching(array $search)
        {
            return GenericDataItem::countFromX(GenericDataItem::class, ['datatype' => static::$datatype] + $search);
        }

        protected function find(array $search)
        {
            return GenericDataItem::getFromX(GenericDataItem::class, ['datatype' => static::$datatype] + $search) ?: [];
        }

        /**
         * The fixture is there, and a plain value finds it
         */
        public function testATextValueStillMatches()
        {
            $this->assertSame(1, $this->matching([]));
            $this->assertSame(1, $this->matching(['arrayq' => 'm']));
            $this->assertCount(1, $this->find(['arrayq' => 'm']));
            $this->assertSame(0, $this->matching(['arrayq' => 'n']));
        }

        public static function arraysThatAreNoCondition()
        {
            return [
                'a list'                              => [['m']],
                'a list of two'                       => [['m', 'n']],
                'an empty array'                      => [[]],
                'a map with a key that is no operator' => [['m' => 'm']],
                'an unknown operator'                 => [['$bogus' => 'm']],
                'a known operator beside an unknown key' => [['$gt' => 'a', 'bogus' => 'm']],
                'a known operator beside an unknown operator' => [['$gt' => 'a', '$bogus' => 'm']],
                'a list holding an operator map'      => [[['$gt' => 'a']]],
                'less than an array'                  => [['$lt' => ['z']]],
                'greater than an array'               => [['$gt' => ['a']]],
                'in something that is no list'        => [['$in' => 'm']],
                'in a list of arrays'                 => [['$in' => [['m']]]],
                'in an empty list'                    => [['$in' => []]],
                'not an empty array'                  => [['$not' => []]],
                'not a list'                          => [['$not' => ['m']]],
                'not, with an unknown operator'       => [['$not' => ['$bogus' => 'x']]],
                'less than an empty text'             => [['$lt' => '']],
            ];
        }

        /**
         * @dataProvider arraysThatAreNoCondition
         */
        public function testAnArrayThatIsNoConditionMatchesNothing($value)
        {
            $this->assertSame(0, $this->matching(['arrayq' => $value]), 'The count');
            $this->assertCount(0, $this->find(['arrayq' => $value]), 'The records');
        }

        /**
         * The same for a field that is a column of its own in the SQL back ends
         *
         * @dataProvider arraysThatAreNoCondition
         */
        public function testTheSameForAFieldOfTheMainTable($value)
        {
            $this->assertSame(0, $this->matching(['uuid' => $value]), 'The count');
            $this->assertCount(0, $this->find(['uuid' => $value]), 'The records');
        }

        public static function operatorMaps()
        {
            return [
                'in a list'             => [['$in' => ['m', 'z']], 1],
                'in a list without it'  => [['$in' => ['y', 'z']], 0],
                'in a list with gaps'   => [['$in' => [1 => 'm', 4 => 'z']], 1],
                'not in a list with gaps' => [['$not' => ['$in' => [2 => 'm']]], 0],
                'a range'               => [['$gt' => 'a', '$lt' => 'z'], 1],
                'a range without it'    => [['$gt' => 'n', '$lt' => 'z'], 0],
                'not in a list'         => [['$not' => ['$in' => ['y', 'z']]], 1],
                'not in a list with it' => [['$not' => ['$in' => ['m', 'z']]], 0],
                'not in an empty list'  => [['$not' => ['$in' => []]], 1],
            ];
        }

        /**
         * @dataProvider operatorMaps
         */
        public function testASupportedOperatorMapIsStillACondition($value, $expected)
        {
            $this->assertSame($expected, $this->matching(['arrayq' => $value]), 'The count');
            $this->assertCount($expected, $this->find(['arrayq' => $value]), 'The records');
        }

        public function testASupportedOperatorMapOnAFieldOfTheMainTable()
        {
            $uuid = static::$object->getUUID();
            $this->assertSame(1, $this->matching(['uuid' => ['$in' => [$uuid, 'x']]]));
            $this->assertSame(0, $this->matching(['uuid' => ['$in' => ['x', 'y']]]));
            $this->assertSame(0, $this->matching(['uuid' => ['$not' => ['$in' => [$uuid]]]]));
            $this->assertSame(1, $this->matching(['uuid' => ['$not' => ['$in' => ['x']]]]));
        }

        /**
         * The SQL back ends take a single value after $not
         */
        public function testNotAValueInSQL()
        {
            if (!$this->isSQL()) {
                $this->markTestSkipped('Only the SQL back ends take a plain value after $not.');
            }
            $this->assertSame(1, $this->matching(['arrayq' => ['$not' => 'z']]));
            $this->assertSame(0, $this->matching(['arrayq' => ['$not' => 'm']]));
            $this->assertSame(1, $this->matching(['arrayq' => ['$not' => '']]), 'An empty operand is a value like any other');
        }

        /**
         * A value that matches nothing inside "or" leaves the other alternatives as they are,
         * and matches nothing when there are none
         */
        public function testInsideOrInSQL()
        {
            if (!$this->isSQL()) {
                $this->markTestSkipped('The map form of $or is the SQL back ends\'.');
            }
            $this->assertSame(0, $this->matching(['$or' => ['arrayq' => ['m'], 'arrayother' => 'nothing']]));
            $this->assertSame(1, $this->matching(['$or' => ['arrayq' => ['m'], 'arrayother' => 'other']]));
            $this->assertSame(0, $this->matching(['$or' => []]), 'No alternative at all');
        }

        /**
         * A text search for something that is no text finds nothing, and is no error
         */
        public function testASearchForAnArrayFindsNothing()
        {
            $db = Idno::site()->db();
            foreach ([['Array'], ['$gt' => ''], []] as $query) {
                $search = $db->createSearchArray($query);
                $this->assertSame(0, GenericDataItem::countFromX(GenericDataItem::class, ['datatype' => static::$datatype] + $search));
                $this->assertEmpty(GenericDataItem::getFromX(GenericDataItem::class, ['datatype' => static::$datatype] + $search));
            }
        }

        /**
         * Nothing in the log says a query failed: a value that is no condition is answered
         * with a query that matches nothing, not with a query the database refuses
         */
        public function testNoQueryIsRefusedByTheDatabase()
        {
            if (!$this->isSQL()) {
                $this->markTestSkipped('Reads the SQL back end\'s own builder.');
            }
            $db = Idno::site()->db();
            foreach (static::arraysThatAreNoCondition() as $name => $case) {
                if ($name === 'less than an empty text') {
                    continue; // A condition like any other, which nothing meets
                }
                $variables = [];
                $joins = 0;
                $plain = [];
                $where = $db->build_where_from_array(['arrayq' => $case[0]], $variables, $joins, $plain);
                $this->assertSame('((1 = 0))', $where, $name);
                $this->assertSame([], $variables, $name);
            }
        }
    }

}
