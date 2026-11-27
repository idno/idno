<?php

declare(strict_types=1);

namespace Idno\Storage;

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

/**
 * A storage query: a tree of Criterion nodes under an AllOf root.
 *
 * fromLegacyArray() mirrors Idno\Data\MySQL::build_where_from_array() (since removed), the interpreter of
 * the search arrays legacy code passes to getObjects() and getRecords(), including its
 * oddities (listed on the method). Value objects here are deliberately not coerced: later
 * code reproduces the legacy "everything is bound as a string" behaviour at evaluation time.
 *
 * The round-trip guarantee of toLegacyArray() covers trees produced by fromLegacyArray();
 * for a tree built by hand that has no faithful legacy form (an operand legacy code would
 * drop as empty, two AnyOf siblings, a nested AllOf) it throws rather than change the query.
 *
 * This class is a value object, not a container service (see config/services.php).
 */
final class Criteria
{
    /** The operators a field's value may hold, in the order they are read */
    private const OPERATORS = ['$not', '$in', '$lt', '$gt'];

    public function __construct(private readonly AllOf $root)
    {
    }

    /**
     * Matches everything.
     */
    public static function all(): self
    {
        return new self(new AllOf([]));
    }

    /**
     * Parses the search arrays legacy code passes to getObjects()/getRecords().
     *
     * A field whose value is not an array is an Equals (even a false or empty one). A field whose
     * value is an array must be a map of the operators $not, $in, $lt and $gt and nothing else, each
     * with an operand it can use (see operators()): a plain list, an empty array, an unknown
     * operator (alone or beside known ones), an empty $in and an array where a plain value belongs
     * are rejected with an InvalidArgumentException, so the query matches nothing. The operators
     * are read in this order: $not (a NotIn when its value is a map holding $in, nothing for an empty
     * one, otherwise a NotEquals), $in, $lt, $gt; an empty plain operand (0, '', '0') is compared
     * like any other. After the operators, a key '$or' whose value is an array becomes an AnyOf of
     * what its entries produce (recursively, with the same rules: a nested '$or' key is a
     * nested AnyOf), and a key '$search' whose value is an array becomes a Search of its
     * element [0] (nothing when that is empty). A '$or' given as a LIST of conditions (the
     * list form of the legacy search arrays) produces no children, so it is an AnyOf with none, which matches nothing
     * (the old SQL backend compiled it to invalid SQL). A non-array '$or' or '$search' is an ordinary field.
     *
     * Shapes legacy code binds as PHP arrays (a $not holding an array without a non-empty
     * $in, a non-array $in, an array operand to $lt/$gt, a non-scalar value) raise a PHP
     * warning there (an exception under the test error handler) and are rejected here with an
     * InvalidArgumentException.
     *
     * @param array<int|string, mixed> $search
     *
     * @throws \InvalidArgumentException when an operand cannot be represented
     */
    public static function fromLegacyArray(array $search): self
    {
        return new self(new AllOf(self::parse($search)));
    }

    public function root(): AllOf
    {
        return $this->root;
    }

    /**
     * The inverse of fromLegacyArray(): fromLegacyArray($c->toLegacyArray()) equals $c for every tree
     * fromLegacyArray() produces. Today only tests use it (CriteriaTest's round trip, and
     * EntityRepositoryCriteriaTest, which reads the criteria the repository built as a legacy array);
     * it is kept for code that must hand criteria to a backend that takes legacy search arrays (the
     * backend class of a plugin).
     * An AnyOf without children is written as the list form, '$or' => [].
     *
     * @return array<int|string, mixed>
     *
     * @throws \LogicException for a tree that has no faithful legacy array form (such as a
     *                          nested AllOf, two AnyOf siblings, or an operand legacy code ignores as empty)
     */
    public function toLegacyArray(): array
    {
        return self::write($this->root->children);
    }

    /**
     * @param array<int|string, mixed> $params
     * @param bool                     $alternatives the entries of an '$or'
     *
     * @return list<Criterion>
     */
    private static function parse(array $params, bool $alternatives = false): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $field = (string) $key;
            if ($alternatives && is_int($key) && is_array($value)) {
                // The list form of '$or' (a list of conditions): it has no field to hold, and contributes
                // no alternative, so an '$or' made only of them is an AnyOf with none, which matches nothing
                continue;
            }
            if (($key === '$or' || $key === '$search') && is_array($value)) {
                foreach (self::OPERATORS as $operator) {
                    if (!empty($value[$operator])) {
                        throw new \InvalidArgumentException(sprintf('"%s" holds a "%s" operator: it cannot be both a field and a %s.', $key, $operator, $key));
                    }
                }
            }
            if (!is_array($value)) {
                $out[] = new Equals($field, self::scalar($value, $field));
                continue;
            }
            if ($key !== '$or' && $key !== '$search') {
                array_push($out, ...self::operators($value, $field));
            }
            if ($key === '$or') {
                $out[] = new AnyOf(self::parse($value, true));
            }
            if ($key === '$search' && !empty($value[0])) {
                $term = $value[0];
                if (!is_scalar($term)) {
                    throw new \InvalidArgumentException('The $search term must be a string.');
                }
                $out[] = new Search((string) $term);
            }
        }

        return $out;
    }

    /**
     * The conditions of a field whose value is an array. It must be a map made only of the operators the parser
     * knows, each with an operand it can use: `$in` an array of plain values (an empty one matches nothing, so the
     * query is refused), `$not` a plain value or a map holding only `$in` (an empty `$in` excludes nothing, so it
     * adds no condition), `$lt` and `$gt` a plain value (an empty one, `0` or `''`, is compared like any other).
     * A plain list, an empty array, a key that is no operator (alone or beside ones that are) and an array where a
     * plain value belongs is a query that cannot be answered, so that a value a request supplied can never drop or
     * bend the condition it was meant for.
     *
     * @param array<int|string, mixed> $value
     *
     * @return list<Criterion>
     *
     * @throws \InvalidArgumentException
     */
    private static function operators(array $value, string $field): array
    {
        if ($value === []) {
            throw new \InvalidArgumentException(sprintf('The value for "%s" is an empty array: use an operator map or a scalar.', $field));
        }
        foreach ($value as $operator => $operand) {
            if (!in_array($operator, self::OPERATORS, true)) {
                throw new \InvalidArgumentException(sprintf('The value for "%s" is an array that is not made only of the operators $not, $in, $lt and $gt.', $field));
            }
            $usable = match ($operator) {
                '$in' => self::isListOfPlainValues($operand) && $operand !== [],
                '$not' => is_scalar($operand) || (is_array($operand) && array_keys($operand) === ['$in'] && self::isListOfPlainValues($operand['$in'])),
                default => is_scalar($operand),
            };
            if (!$usable) {
                throw new \InvalidArgumentException(sprintf('The "%s" operator of "%s" has an operand it cannot use.', $operator, $field));
            }
        }

        $out = [];
        if (array_key_exists('$not', $value)) {
            $not = $value['$not'];
            if (!is_array($not)) {
                $out[] = new NotEquals($field, $not);
            } elseif ($not['$in'] !== []) {
                $out[] = new NotIn($field, array_values($not['$in']));
            }
        }
        if (array_key_exists('$in', $value)) {
            $out[] = new In($field, array_values($value['$in']));
        }
        if (array_key_exists('$lt', $value)) {
            $out[] = new LessThan($field, $value['$lt']);
        }
        if (array_key_exists('$gt', $value)) {
            $out[] = new GreaterThan($field, $value['$gt']);
        }

        return $out;
    }

    private static function isListOfPlainValues(mixed $operand): bool
    {
        return is_array($operand) && count(array_filter($operand, 'is_scalar')) === count($operand);
    }

    private static function scalar(mixed $value, string $field): string|int|float|bool|null
    {
        if ($value !== null && !is_scalar($value)) {
            throw new \InvalidArgumentException(sprintf('The operand for "%s" must be a scalar, %s given.', $field, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * An empty list has no legacy form ($in refuses it, $not takes it as no condition), and nor has a search
     * with an empty term (legacy code ignores it).
     */
    private static function assertWritable(Criterion $child): void
    {
        $empty = match (true) {
            $child instanceof In, $child instanceof NotIn => empty($child->values),
            $child instanceof Search => empty($child->query),
            default => false,
        };
        if ($empty) {
            throw new \LogicException('No legacy form for ' . $child::class . ' with an empty operand: legacy code ignores it.');
        }
    }

    /**
     * @param list<Criterion> $children
     *
     * @return array<int|string, mixed>
     */
    private static function write(array $children): array
    {
        $out = [];
        foreach ($children as $child) {
            self::assertWritable($child);
            [$key, $fragment] = match (true) {
                $child instanceof Equals => [$child->field, $child->value],
                $child instanceof NotEquals => [$child->field, ['$not' => $child->value]],
                $child instanceof NotIn => [$child->field, ['$not' => ['$in' => $child->values]]],
                $child instanceof In => [$child->field, ['$in' => $child->values]],
                $child instanceof LessThan => [$child->field, ['$lt' => $child->value]],
                $child instanceof GreaterThan => [$child->field, ['$gt' => $child->value]],
                $child instanceof AnyOf => ['$or', self::write($child->children)],
                $child instanceof Search => ['$search', [$child->query]],
                default => throw new \LogicException('No legacy array form for ' . get_debug_type($child) . '.'),
            };

            // The operators on one field share one array entry. A '$or' is never merged: two
            // AnyOf siblings mean AND, and one merged '$or' would mean OR.
            if (!array_key_exists($key, $out)) {
                $out[$key] = $fragment;
            } elseif ($key !== '$or' && is_array($out[$key]) && is_array($fragment) && array_intersect_key($out[$key], $fragment) === []) {
                $out[$key] += $fragment;
            } else {
                throw new \LogicException(sprintf('The criteria for "%s" have no legacy array form.', $key));
            }
        }

        return $out;
    }
}
