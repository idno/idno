<?php

namespace Tests\Data {

    use Idno\Data\Mongo;

    /**
     * What the MongoDB back end hands to the database: a field value that is an array is
     * handed on only when it is a map of query operators, each with an operand of the right
     * shape. Any other array becomes a condition that nothing meets.
     *
     * Mongo::guardQuery() only rewrites an array, so this needs no MongoDB server.
     */
    class MongoQueryGuardTest extends \Tests\IdnoTestCase
    {

        public static function queriesLeftAlone()
        {
            $object = new \stdClass(); // Stands for a BSON value (an id, a date, a regular expression)

            return [
                'no conditions'       => [[]],
                'plain values'        => [['a' => 'x', 'b' => 3, 'c' => true, 'd' => null]],
                'an object'           => [['_id' => $object]],
                'in a list'           => [['a' => ['$in' => ['x', 'y']]]],
                'in an empty list'    => [['a' => ['$in' => []]]],
                'in a list of objects' => [['a' => ['$in' => [$object]]]],
                'in and not in'       => [['a' => ['$in' => ['x'], '$not' => ['$in' => ['y']]]]],
                'not an expression'   => [['a' => ['$not' => $object]]],
                'a range'             => [['a' => ['$gt' => 'a', '$lte' => 'z']]],
                'other operators'     => [['a' => ['$ne' => 'x'], 'b' => ['$exists' => true], 'c' => ['$nin' => ['x']], 'd' => ['$regex' => '^a', '$options' => 'i']]],
                'alternatives'        => [['$or' => [['a' => 'x'], ['b' => ['$in' => ['y']]]]]],
                'a top-level operator' => [['$text' => ['$search' => 'words']]],
            ];
        }

        /**
         * @dataProvider queriesLeftAlone
         */
        public function testAQueryOfPlainValuesAndOperatorsIsLeftAlone($query)
        {
            $this->assertSame($query, Mongo::guardQuery($query));
        }

        public static function valuesThatAreNoCondition()
        {
            return [
                'a list'                                   => [['m']],
                'an empty array'                           => [[]],
                'a map with a key that is no operator'     => [['m' => 'm']],
                'an unknown operator'                      => [['$bogus' => 'm']],
                'an operator that runs code'               => [['$where' => 'true']],
                'a known operator beside an unknown key'   => [['$gt' => 'a', 'bogus' => 'm']],
                'a known operator beside an unknown operator' => [['$gt' => 'a', '$where' => 'true']],
                'a list holding an operator map'           => [[['$gt' => 'a']]],
                'greater than an array'                    => [['$gt' => ['a']]],
                'not equal to an array'                    => [['$ne' => ['a']]],
                'in something that is no list'             => [['$in' => 'm']],
                'in a list of arrays'                      => [['$in' => [['m']]]],
                'not in something that is no list'         => [['$nin' => 'm']],
                'not a plain value'                        => [['$not' => 'm']],
                'not an empty array'                       => [['$not' => []]],
                'not, with an unknown operator'            => [['$not' => ['$where' => 'true']]],
            ];
        }

        /**
         * @dataProvider valuesThatAreNoCondition
         */
        public function testAnArrayThatIsNoConditionMatchesNothing($value)
        {
            $this->assertSame(
                ['kept' => 'x', 'field' => Mongo::MATCH_NOTHING],
                Mongo::guardQuery(['kept' => 'x', 'field' => $value])
            );
        }

        /**
         * @dataProvider valuesThatAreNoCondition
         */
        public function testTheSameInsideAlternatives($value)
        {
            $this->assertSame(
                ['$or' => [['field' => Mongo::MATCH_NOTHING], ['kept' => 'x']]],
                Mongo::guardQuery(['$or' => [['field' => $value], ['kept' => 'x']]])
            );
            $this->assertSame(
                ['$and' => [['$or' => [['field' => Mongo::MATCH_NOTHING]]]]],
                Mongo::guardQuery(['$and' => [['$or' => [['field' => $value]]]]])
            );
        }

        /**
         * What is left of a PHP list after unset() or array_filter() is still a list of values
         */
        public function testAListWithGapsIsSentAsAList()
        {
            $this->assertSame(
                ['a' => ['$in' => ['x', 'y'], '$not' => ['$in' => ['z']]], 'b' => ['$nin' => ['q']]],
                Mongo::guardQuery(['a' => ['$in' => [1 => 'x', 3 => 'y'], '$not' => ['$in' => [2 => 'z']]], 'b' => ['$nin' => ['k' => 'q']]])
            );
        }

        public function testAQueryThatIsNoArrayMatchesNothing()
        {
            foreach (['text', 3, null, false] as $query) {
                $this->assertSame(['_id' => Mongo::MATCH_NOTHING], Mongo::guardQuery($query));
            }
        }
    }

}
