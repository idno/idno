<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Idno\Storage\Criteria;
use Idno\Storage\Criterion\AllOf;
use Idno\Storage\Criterion\AnyOf;
use Idno\Storage\Criterion\Criterion;
use Idno\Storage\Criterion\Equals;
use Idno\Storage\Criterion\GreaterThan;
use Idno\Storage\Criterion\In;
use Idno\Storage\Criterion\LessThan;
use Idno\Storage\Criterion\NotEquals;
use Idno\Storage\Criterion\NotIn;
use Idno\Storage\Criterion\Search;
use PHPUnit\Framework\TestCase;

/**
 * Pins how the search arrays legacy code passes to getObjects()/getRecords() are parsed,
 * branch by branch against Idno\Data\MySQL::build_where_from_array(). Dependency-free:
 * runs under both Tests/_bootstrap_unit.php and the full suite's bootstrap.
 */
final class CriteriaTest extends TestCase
{
    /**
     * @param array<int|string, mixed> $search
     *
     * @return list<Criterion>
     */
    private function parse(array $search): array
    {
        return Criteria::fromLegacyArray($search)->root()->children;
    }

    public function testEmptyArrayMatchesEverything(): void
    {
        $this->assertEquals(new Criteria(new AllOf([])), Criteria::fromLegacyArray([]));
        $this->assertEquals(Criteria::all(), Criteria::fromLegacyArray([]));
        $this->assertSame([], Criteria::all()->root()->children);
        $this->assertSame([], Criteria::all()->toLegacyArray());
    }

    public function testScalarBecomesEquals(): void
    {
        $this->assertEquals([new Equals('slug', 'a')], $this->parse(['slug' => 'a']));
    }

    public function testSeveralFieldsAreAllOfTheirCriteriaInOrder(): void
    {
        $this->assertEquals(
            [new Equals('slug', 'a'), new Equals('owner', 'x')],
            $this->parse(['slug' => 'a', 'owner' => 'x'])
        );
    }

    public function testScalarValuesAreNotCoerced(): void
    {
        $criteria = $this->parse(['b' => true, 'n' => 1000, 'f' => 1.5, 's' => '1', 'z' => null, 'no' => false]);
        $values = array_map(static fn (Equals $c): mixed => $c->value, $criteria);
        $this->assertSame([true, 1000, 1.5, '1', null, false], $values);
    }

    public function testFalseAndEmptyScalarsAreStillEquals(): void
    {
        // The empty() checks apply to operator operands only; a plain value is always bound.
        $this->assertEquals(
            [new Equals('a', ''), new Equals('b', 0), new Equals('c', '0'), new Equals('d', false), new Equals('e', null)],
            $this->parse(['a' => '', 'b' => 0, 'c' => '0', 'd' => false, 'e' => null])
        );
    }

    public function testIn(): void
    {
        $this->assertEquals([new In('owner', ['a', 'b'])], $this->parse(['owner' => ['$in' => ['a', 'b']]]));
    }

    public function testInKeepsTheTypesOfItsValues(): void
    {
        $in = $this->parse(['f' => ['$in' => [true, 1, '1']]])[0];
        $this->assertInstanceOf(In::class, $in);
        $this->assertSame([true, 1, '1'], $in->values);
    }

    public function testNot(): void
    {
        $this->assertEquals([new NotEquals('owner', 'a')], $this->parse(['owner' => ['$not' => 'a']]));
    }

    public function testNotIn(): void
    {
        $this->assertEquals([new NotIn('complete', [true])], $this->parse(['complete' => ['$not' => ['$in' => [true]]]]));
    }

    public function testNotOfAnEmptyListExcludesNothingSoItAddsNoCondition(): void
    {
        // Nothing is in an empty list: no restriction (an exclusion that cannot drop a record)
        $this->assertSame([], $this->parse(['n' => ['$not' => ['$in' => []]]]));
    }

    public function testNotWithAnArrayOperandIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parse(['n' => ['$not' => ['a', 'b']]]);
    }

    public function testNonArrayInIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parse(['n' => ['$in' => 'abc']]);
    }

    public function testArrayOperandToLtIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parse(['n' => ['$lt' => [1]]]);
    }

    public function testNonScalarPlainValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parse(['n' => new \stdClass()]);
    }

    public function testLtGt(): void
    {
        $this->assertEquals([new LessThan('created', '2020-01-01 00:00:00')], $this->parse(['created' => ['$lt' => '2020-01-01 00:00:00']]));
        $this->assertEquals([new GreaterThan('n', 1000)], $this->parse(['n' => ['$gt' => 1000]]));
        $lt = $this->parse(['n' => ['$lt' => 1000]])[0];
        $this->assertSame(1000, $lt->value);
    }

    public function testSeveralOperatorsOnOneFieldComeInTodaysEvaluationOrder(): void
    {
        $expected = [
            new NotEquals('n', 5),
            new In('n', [1, 2]),
            new LessThan('n', 9),
            new GreaterThan('n', 1),
        ];
        // Whatever order the caller wrote them in.
        $this->assertEquals($expected, $this->parse(['n' => ['$gt' => 1, '$lt' => 9, '$in' => [1, 2], '$not' => 5]]));
        $this->assertEquals($expected, $this->parse(['n' => ['$not' => 5, '$in' => [1, 2], '$lt' => 9, '$gt' => 1]]));
        $this->assertEquals(
            [new LessThan('n', 9), new GreaterThan('n', 1)],
            $this->parse(['n' => ['$gt' => 1, '$lt' => 9]])
        );
        $this->assertEquals(
            [new NotIn('n', [3]), new In('n', [1])],
            $this->parse(['n' => ['$in' => [1], '$not' => ['$in' => [3]]]])
        );
    }

    /**
     * @return array<string, array{array<int|string, mixed>, Criterion}>
     */
    public static function emptyPlainOperands(): array
    {
        $cases = [];
        foreach ([0, '0', '', false] as $empty) {
            $name = json_encode($empty);
            $cases['$not ' . $name] = [['n' => ['$not' => $empty]], new NotEquals('n', $empty)];
            $cases['$lt ' . $name] = [['n' => ['$lt' => $empty]], new LessThan('n', $empty)];
            $cases['$gt ' . $name] = [['n' => ['$gt' => $empty]], new GreaterThan('n', $empty)];
        }

        return $cases;
    }

    /**
     * A request's `code[$lt]=` or `code[$gt]=0` must not parse to no condition (that dropped the lookup's condition):
     * an empty plain operand is compared like any other.
     *
     * @dataProvider emptyPlainOperands
     *
     * @param array<int|string, mixed> $search
     */
    public function testAnEmptyPlainOperandIsAConditionNotADroppedOne(array $search, Criterion $expected): void
    {
        $this->assertEquals([$expected], $this->parse($search));
    }

    /**
     * @return array<string, array{array<int|string, mixed>}>
     */
    public static function operandsThatCannotBeUsed(): array
    {
        $cases = [];
        foreach (['$in', '$lt', '$gt', '$not'] as $operator) {
            $cases[$operator . ' []'] = [['n' => [$operator => []]]];
            $cases[$operator . ' null'] = [['n' => [$operator => null]]];
        }
        $cases['$in ""'] = [['n' => ['$in' => '']]];
        $cases['$in 0'] = [['n' => ['$in' => 0]]];
        $cases['$in false'] = [['n' => ['$in' => false]]];
        $cases['$in with null'] = [['n' => ['$in' => ['a', null]]]];
        $cases['$in with a list'] = [['n' => ['$in' => [['a']]]]];
        $cases['$not with another key'] = [['n' => ['$not' => ['$in' => ['a'], '$lt' => 'b']]]];

        return $cases;
    }

    /**
     * `code[$in]=`, `code[$lt][]=x` and the like must make the query match nothing rather than drop the condition.
     *
     * @dataProvider operandsThatCannotBeUsed
     *
     * @param array<int|string, mixed> $search
     */
    public function testAnOperandThatCannotBeUsedIsRejected(array $search): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parse($search);
    }

    public function testAKnownOperatorBesideAnEmptyPlainOneKeepsBoth(): void
    {
        $this->assertEquals(
            [new NotEquals('n', ''), new LessThan('n', 0), new GreaterThan('n', 3)],
            $this->parse(['n' => ['$lt' => 0, '$not' => '', '$gt' => 3]])
        );
    }

    /**
     * @return array<string, array{array<int|string, mixed>}>
     */
    public static function arrayValuesThatAreNotOperatorMaps(): array
    {
        return [
            'unknown operators only' => [['n' => ['$regex' => 'a', '$exists' => true]]],
            'unknown beside a known one' => [['n' => ['$regex' => 'a', '$lt' => 2]]],
            'unknown after a known one' => [['n' => ['$lt' => 'g', '$ne' => 'x']]],
            'plain list' => [['f' => ['a', 'b']]],
            'list of one' => [['f' => ['x']]],
            'empty array' => [['f' => []]],
            'map of other keys' => [['f' => ['x' => 'y']]],
            'a list beside an operator' => [['f' => ['$gt' => 'a', 0 => 'b']]],
            'inside an or' => [['$or' => ['f' => ['a', 'b']]]],
        ];
    }

    /**
     * @dataProvider arrayValuesThatAreNotOperatorMaps
     *
     * @param array<int|string, mixed> $search
     */
    public function testAnArrayValueThatIsNotAMapOfKnownOperatorsIsRejected(array $search): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parse($search);
    }

    public function testTheRejectionNamesTheFieldAndNeverTheValues(): void
    {
        try {
            $this->parse(['code' => ['secret-operand']]);
            $this->fail('expected a rejection');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('"code"', $e->getMessage());
            $this->assertStringNotContainsString('secret-operand', $e->getMessage());
        }
    }

    public function testAssociativeOrParsesToAnyOfOfItsEntries(): void
    {
        $this->assertEquals(
            [new AnyOf([new Equals('slug', 'a'), new Equals('owner', 'x')])],
            $this->parse(['$or' => ['slug' => 'a', 'owner' => 'x']])
        );
    }

    public function testOrEntriesMayBeOperatorMaps(): void
    {
        $this->assertEquals(
            [new AnyOf([new In('owner', ['a', 'b']), new LessThan('n', 9), new GreaterThan('n', 1)])],
            $this->parse(['$or' => ['owner' => ['$in' => ['a', 'b']], 'n' => ['$gt' => 1, '$lt' => 9]]])
        );
    }

    public function testOrIsAnAnyOfAlongsideOtherConditions(): void
    {
        $this->assertEquals(
            [new Equals('size', 's'), new AnyOf([new Equals('slug', 'a')])],
            $this->parse(['size' => 's', '$or' => ['slug' => 'a']])
        );
    }

    public function testOrGivenAsAListOfConditionsMatchesNothing(): void
    {
        $this->assertEquals(
            [new AnyOf([])],
            $this->parse(['$or' => [['slug' => 'a'], ['slug' => 'b']]])
        );
        $this->assertEquals([new AnyOf([])], $this->parse(['$or' => []]));
        // ... and the rest of the query still applies next to it.
        $this->assertEquals(
            [new Equals('owner', 'x'), new AnyOf([])],
            $this->parse(['owner' => 'x', '$or' => [['slug' => 'a']]])
        );
    }

    public function testNestedOrInsideAnAssociativeOrIsANestedAnyOf(): void
    {
        $this->assertEquals(
            [new AnyOf([new Equals('slug', 'a'), new AnyOf([new Equals('owner', 'x'), new Equals('flag', 'y')])])],
            $this->parse(['$or' => ['slug' => 'a', '$or' => ['owner' => 'x', 'flag' => 'y']]])
        );
        // A nested list form is a nested empty AnyOf.
        $this->assertEquals(
            [new AnyOf([new Equals('slug', 'a'), new AnyOf([])])],
            $this->parse(['$or' => ['slug' => 'a', '$or' => [['owner' => 'x']]]])
        );
    }

    public function testOrIsRecognisedOnlyAsAKeyWithAnArrayValue(): void
    {
        $this->assertEquals([new Equals('$or', 'x')], $this->parse(['$or' => 'x']));
        $this->assertEquals([new Equals('$or', true)], $this->parse(['$or' => true]));
    }

    public function testSearch(): void
    {
        $this->assertEquals([new Search('q')], $this->parse(['$search' => ['q']]));
        $this->assertEquals([new Search('platypus')], $this->parse(['$search' => ['platypus']]));
    }

    public function testSearchUsesOnlyElementZero(): void
    {
        $this->assertEquals([new Search('a')], $this->parse(['$search' => ['a', 'b']]));
        $this->assertSame([], $this->parse(['$search' => [1 => 'b']]));
    }

    public function testSearchWithNoUsableTermAddsNothing(): void
    {
        $this->assertSame([], $this->parse(['$search' => []]));
        $this->assertSame([], $this->parse(['$search' => ['']]));
        $this->assertSame([], $this->parse(['$search' => ['0']]));
    }

    public function testSearchGivenAPlainStringIsAFieldCondition(): void
    {
        $this->assertEquals([new Equals('$search', 'abc')], $this->parse(['$search' => 'abc']));
    }

    public function testSearchTermIsAString(): void
    {
        $this->assertEquals([new Search('123')], $this->parse(['$search' => [123]]));
    }

    public function testSearchTermThatIsNotScalarIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parse(['$search' => [['x']]]);
    }

    public function testSearchCombinesWithOtherConditionsInKeyOrder(): void
    {
        $this->assertEquals(
            [new Equals('owner', 'x'), new Search('q')],
            $this->parse(['owner' => 'x', '$search' => ['q']])
        );
    }

    public function testOrOrSearchHoldingAnOperatorIsRejected(): void
    {
        // Legacy code would also read '$or' as a field called "$or" with that operator.
        foreach ([['$or' => ['$not' => 'x']], ['$or' => ['$in' => ['x']]], ['$or' => ['$lt' => 1]], ['$search' => ['q', '$gt' => 1]]] as $search) {
            try {
                $this->parse($search);
                $this->fail('Expected InvalidArgumentException for ' . json_encode($search));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // An operator with an empty operand is dropped, as everywhere else.
        $this->assertEquals([new AnyOf([new Equals('$not', '')])], $this->parse(['$or' => ['$not' => '']]));
    }

    public function testIntegerKeysBecomeFieldNames(): void
    {
        $this->assertEquals([new Equals('0', 'a'), new Equals('1', 'b')], $this->parse(['a', 'b']));
    }

    public function testTheQueueQueriesParse(): void
    {
        $this->assertEquals(
            [new Equals('queue', 'default'), new NotIn('complete', [true])],
            $this->parse(['queue' => 'default', 'complete' => ['$not' => ['$in' => [true]]]])
        );
        $this->assertEquals(
            [new Equals('complete', true), new LessThan('completedTs', 1000)],
            $this->parse(['complete' => true, 'completedTs' => ['$lt' => 1000]])
        );
    }

    /**
     * @return array<string, array{array<int|string, mixed>}>
     */
    public static function roundTripShapes(): array
    {
        return [
            'empty' => [[]],
            'scalar' => [['slug' => 'a', 'n' => 1000, 'b' => true, 'z' => null, 'f' => false, 'e' => '']],
            'in not notin' => [['a' => ['$in' => ['x', 'y']], 'b' => ['$not' => 'a'], 'c' => ['$not' => ['$in' => [true, 1]]]]],
            'lt gt' => [['n' => ['$gt' => 1, '$lt' => 9]]],
            'all four' => [['n' => ['$gt' => 1, '$lt' => 9, '$in' => [1], '$not' => 5]]],
            'not and not in cannot co-occur but in+not can' => [['n' => ['$in' => [1], '$not' => 5]]],
            'empty plain operands' => [['n' => ['$lt' => 0, '$not' => '', '$gt' => '0']]],
            'or' => [['$or' => ['slug' => 'a', 'owner' => 'x']]],
            'or with operators' => [['$or' => ['owner' => ['$in' => ['a', 'b']], 'n' => ['$gt' => 1, '$lt' => 9]]]],
            'or list' => [['$or' => [['slug' => 'a'], ['slug' => 'b']]]],
            'or empty' => [['$or' => []]],
            'nested or' => [['x' => 1, '$or' => ['slug' => 'a', '$or' => ['owner' => 'x', 'flag' => 'y']]]],
            'nested or list' => [['$or' => ['slug' => 'a', '$or' => [['owner' => 'x']]]]],
            'search' => [['owner' => 'x', '$search' => ['q']]],
            'search dropped' => [['$search' => ['']]],
            'search plain string' => [['$search' => 'abc']],
            'or plain' => [['$or' => 'x']],
            'integer keys' => [['a', 'b']],
            'queue' => [['queue' => 'default', 'complete' => ['$not' => ['$in' => [true]]]]],
        ];
    }

    /**
     * @dataProvider roundTripShapes
     *
     * @param array<int|string, mixed> $search
     */
    public function testRoundTrip(array $search): void
    {
        $criteria = Criteria::fromLegacyArray($search);
        $again = Criteria::fromLegacyArray($criteria->toLegacyArray());
        $this->assertEquals($criteria, $again);
        // Types survive too (assertEquals is loose about 1 and true).
        $this->assertSame(serialize($criteria), serialize($again));
    }

    public function testRoundTripOfRandomShapes(): void
    {
        mt_srand(20261114);
        $fields = ['owner', 'slug', 'n', '$or', '$search', '$not', '$in', '0'];
        $scalars = ['a', 'b', 1, 0, '0', '', true, false, null, 1.5, 1000];
        $operators = ['$not', '$in', '$lt', '$gt', '$regex'];
        $pick = static fn (array $items): mixed => $items[mt_rand(0, count($items) - 1)];

        $make = function (int $depth) use (&$make, $fields, $scalars, $operators, $pick): array {
            $out = [];
            for ($i = mt_rand(0, 4); $i > 0; $i--) {
                $field = $pick($fields);
                $kind = mt_rand(0, 6);
                if ($kind <= 1) {
                    $out[$field] = $pick($scalars);
                } elseif ($kind <= 3) {
                    $map = [];
                    for ($j = mt_rand(0, 3); $j > 0; $j--) {
                        $op = $pick($operators);
                        $operand = match (mt_rand(0, 3)) {
                            0 => [$pick($scalars), $pick($scalars)],
                            1 => ['$in' => [$pick($scalars)]],
                            default => $pick($scalars),
                        };
                        $map[$op] = $operand;
                    }
                    $out[$field] = $map;
                } elseif ($kind === 4 && $depth < 3) {
                    $out[$field] = $make($depth + 1);
                } elseif ($kind === 5) {
                    $out[$field] = [$pick($scalars), $pick($scalars)];
                } else {
                    $out[$field] = [];
                }
            }

            return $out;
        };

        $parsed = 0;
        for ($n = 0; $n < 3000; $n++) {
            $search = $make(0);
            try {
                $criteria = Criteria::fromLegacyArray($search);
            } catch (\InvalidArgumentException) {
                continue; // an operand legacy code binds as an array
            }
            $parsed++;
            try {
                $legacy = $criteria->toLegacyArray();
            } catch (\LogicException $e) {
                $this->fail('No legacy form for a parsed tree of ' . json_encode($search) . ': ' . $e->getMessage());
            }
            $this->assertSame(
                serialize($criteria),
                serialize(Criteria::fromLegacyArray($legacy)),
                'Round trip failed for ' . json_encode($search)
            );
        }
        $this->assertGreaterThan(1000, $parsed);
    }

    public function testEmptyAnyOfWritesTheListForm(): void
    {
        $this->assertSame(['$or' => []], (new Criteria(new AllOf([new AnyOf([])])))->toLegacyArray());
    }

    public function testToLegacyArrayWritesTheOperatorMapsLegacyCodeUses(): void
    {
        $criteria = Criteria::fromLegacyArray(['n' => ['$gt' => 1, '$lt' => 9], 'o' => ['$not' => ['$in' => ['a']]], '$search' => ['q'], 's' => 'x']);
        $this->assertSame(
            ['n' => ['$lt' => 9, '$gt' => 1], 'o' => ['$not' => ['$in' => ['a']]], '$search' => ['q'], 's' => 'x'],
            $criteria->toLegacyArray()
        );
    }

    public function testATreeWithNoLegacyFormIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        (new Criteria(new AllOf([new AllOf([])])))->toLegacyArray();
    }

    public function testTwoEqualsOnOneFieldHaveNoLegacyForm(): void
    {
        $this->expectException(\LogicException::class);
        (new Criteria(new AllOf([new Equals('a', 1), new Equals('a', 2)])))->toLegacyArray();
    }

    public function testTwoAnyOfSiblingsHaveNoLegacyForm(): void
    {
        // AND of two ORs: one merged '$or' would mean a different query.
        $this->expectException(\LogicException::class);
        (new Criteria(new AllOf([new AnyOf([new Equals('a', 1)]), new AnyOf([new Equals('b', 2)])])))->toLegacyArray();
    }

    public function testAnAnyOfBesideAnEqualsOnTheOrFieldHasNoLegacyForm(): void
    {
        $this->expectException(\LogicException::class);
        (new Criteria(new AllOf([new Equals('$or', 'x'), new AnyOf([new Equals('a', 1)])])))->toLegacyArray();
    }

    /**
     * @return array<string, array{Criterion}>
     */
    public static function nodesLegacyCodeWouldIgnore(): array
    {
        return [
            'In []' => [new In('n', [])],
            'NotIn []' => [new NotIn('n', [])],
            'Search ""' => [new Search('')],
            'Search "0"' => [new Search('0')],
        ];
    }

    /**
     * @dataProvider nodesLegacyCodeWouldIgnore
     */
    public function testANodeLegacyCodeWouldIgnoreHasNoLegacyForm(Criterion $node): void
    {
        $this->expectException(\LogicException::class);
        (new Criteria(new AllOf([$node])))->toLegacyArray();
    }

    public function testAnEqualsWithAnEmptyValueStillWrites(): void
    {
        $tree = new AllOf([new Equals('a', ''), new Equals('b', 0), new Equals('c', false)]);
        $this->assertSame(['a' => '', 'b' => 0, 'c' => false], (new Criteria($tree))->toLegacyArray());
    }

    public function testAllOfAndAnyOfHoldAList(): void
    {
        $a = new Equals('a', 1);
        $b = new Equals('b', 2);
        $this->assertSame([$a, $b], (new AllOf(['x' => $a, 5 => $b]))->children);
        $this->assertSame([$a, $b], (new AnyOf(['x' => $a, 5 => $b]))->children);
        $this->assertEquals(new AllOf([$a, $b]), new AllOf(['x' => $a, 'y' => $b]));
    }

    public function testAllOfAndAnyOfRejectAChildThatIsNotACriterion(): void
    {
        foreach ([AllOf::class, AnyOf::class] as $class) {
            try {
                new $class([new Equals('a', 1), 'b']);
                $this->fail("$class accepted a string child");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
