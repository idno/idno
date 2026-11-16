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
     * Per field, the legacy code looks at each of these operators in turn, in this fixed
     * order: $not (a NotIn when its value holds a non-empty $in, otherwise a NotEquals),
     * $in, $lt, $gt. An operator whose operand is empty (0, '0', '', [], null, false) is
     * dropped, as is an unknown operator. A field whose value is not an array is an Equals
     * (even a false or empty one). A plain list or an empty array as a field value adds
     * nothing. After the operators, a key '$or' whose value is an array becomes an AnyOf of
     * what its entries produce (recursively, with the same rules: a nested '$or' key is a
     * nested AnyOf), and a key '$search' whose value is an array becomes a Search of its
     * element [0] (nothing when that is empty). A '$or' given as a LIST of conditions (the
     * MongoDB form) produces no children, so it is an AnyOf with none, which matches nothing
     * (today it compiles to invalid SQL). A non-array '$or' or '$search' is an ordinary field.
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
     * MongoDB backend, when it moves to the storage layer).
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
     *
     * @return list<Criterion>
     */
    private static function parse(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $field = (string) $key;
            if (($key === '$or' || $key === '$search') && is_array($value)) {
                foreach (['$not', '$in', '$lt', '$gt'] as $operator) {
                    if (!empty($value[$operator])) {
                        throw new \InvalidArgumentException(sprintf('"%s" holds a "%s" operator: it cannot be both a field and a %s.', $key, $operator, $key));
                    }
                }
            }
            if (!is_array($value)) {
                $out[] = new Equals($field, self::scalar($value, $field));
                continue;
            }

            if (!empty($value['$not'])) {
                $not = $value['$not'];
                if (is_array($not) && !empty($not['$in'])) {
                    $out[] = new NotIn($field, self::values($not['$in'], $field));
                } else {
                    $out[] = new NotEquals($field, self::scalar($not, $field));
                }
            }
            if (!empty($value['$in'])) {
                $out[] = new In($field, self::values($value['$in'], $field));
            }
            if (!empty($value['$lt'])) {
                $out[] = new LessThan($field, self::scalar($value['$lt'], $field));
            }
            if (!empty($value['$gt'])) {
                $out[] = new GreaterThan($field, self::scalar($value['$gt'], $field));
            }
            if ($key === '$or') {
                $out[] = new AnyOf(self::parse($value));
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

    private static function scalar(mixed $value, string $field): string|int|float|bool|null
    {
        if ($value !== null && !is_scalar($value)) {
            throw new \InvalidArgumentException(sprintf('The operand for "%s" must be a scalar, %s given.', $field, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @return list<string|int|float|bool|null>
     */
    private static function values(mixed $values, string $field): array
    {
        if (!is_array($values)) {
            throw new \InvalidArgumentException(sprintf('The $in operand for "%s" must be an array.', $field));
        }
        $out = [];
        foreach ($values as $value) {
            $out[] = self::scalar($value, $field);
        }

        return $out;
    }

    /**
     * Legacy code drops an operator with an empty operand and a search with an empty term,
     * so a node holding one has no faithful legacy form.
     */
    private static function assertWritable(Criterion $child): void
    {
        $empty = match (true) {
            $child instanceof NotEquals, $child instanceof LessThan, $child instanceof GreaterThan => empty($child->value),
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
