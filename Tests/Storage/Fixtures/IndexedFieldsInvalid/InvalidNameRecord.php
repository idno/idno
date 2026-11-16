<?php

declare(strict_types=1);

namespace Tests\Storage\Fixtures\IndexedFieldsInvalid;

/**
 * Declares a field name that is not valid, next to a valid one.
 */
#[\Idno\Storage\Attribute\AsEntity('fixture_invalid')]
#[\Idno\Storage\Attribute\IndexedField('valid_field')]
#[\Idno\Storage\Attribute\IndexedField('Not-Valid')]
final class InvalidNameRecord
{
}
