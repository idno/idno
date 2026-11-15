<?php

declare(strict_types=1);

namespace Tests\Storage;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Tests\Storage\Contract\EntityQueryContract;

/**
 * The entity query contract against the legacy code: getObjects(), countObjects() and getObject() of
 * the legacy MySQL backend (Idno\Data\AbstractSQL and Idno\Core\DataConcierge on MySQL). Since
 * Task 7 Idno::site()->db() is the storage adapter, so the test constructs the legacy backend itself
 * and runs the legacy object's own methods. The contract sets the ignore-access flag on db(); each
 * call copies it to the legacy object's own flag, which is what its getObjects() reads. Deleted with
 * the legacy backend.
 */
final class LegacyEntityQueryTest extends EntityQueryContract
{
    private static ?\Idno\Data\MySQL $legacy = null;

    private string|false $previousErrorLog = false;

    /**
     * The legacy MySQL backend (built once for the class), with db()'s ignore-access flag.
     */
    private static function legacy(): \Idno\Data\MySQL
    {
        self::$legacy ??= new \Idno\Data\MySQL();
        self::$legacy->setIgnoreAccess(Idno::site()->db()->getIgnoreAccess());

        return self::$legacy;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Negative paging makes the legacy backend fail and log the failure; keep the output clean.
        $this->previousErrorLog = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }
        parent::tearDown();
    }

    protected function findEntities(array $types, array $search, int $limit = 10, int $offset = 0, array $readGroups = []): array
    {
        $result = self::legacy()->getObjects($types, $search, [], $limit, $offset, 'entities', $readGroups);
        $this->assertIsArray($result);

        return $result;
    }

    protected function countEntities(array $types, array $search): int
    {
        $result = self::legacy()->countObjects($types, $search);
        $this->assertIsInt($result);

        return $result;
    }

    protected function findOneByUuid(string $uuid): ?Entity
    {
        $result = self::legacy()->getObject($uuid);
        if ($result === false) {
            return null;
        }
        $this->assertInstanceOf(Entity::class, $result);

        return $result;
    }

    public function testGetObjectReturnsFalseNotNull(): void
    {
        // OBSERVED: what the repository's null stands for.
        $this->asAnonymous();
        $this->assertFalse(self::legacy()->getObject('http://unknown.test/view/' . bin2hex(random_bytes(8))));
        $this->assertFalse(self::legacy()->getObject($this->uuid('s_priv')));
    }
}
