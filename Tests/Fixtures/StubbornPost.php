<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * An entity whose delete() says it deleted the record and leaves it where it is (a soft delete, a class whose collection is not the one its record was found in):
 * what an account's removal must not take for progress.
 */
class StubbornPost extends \Idno\Common\Entity
{
    public static int $deletes = 0;

    public function delete()
    {
        self::$deletes++;

        return true;
    }
}
