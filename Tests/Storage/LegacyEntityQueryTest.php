<?php

declare(strict_types=1);

namespace Tests\Storage;

use Idno\Common\Entity;
use Idno\Core\Idno;
use Tests\Storage\Contract\EntityQueryContract;

/**
 * The entity query contract against today's code: Idno::site()->db()'s getObjects(), countObjects()
 * and getObject() (Idno\Data\AbstractSQL and Idno\Core\DataConcierge on MySQL).
 */
final class LegacyEntityQueryTest extends EntityQueryContract
{
    private string|false $previousErrorLog = false;

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
        $result = Idno::site()->db()->getObjects($types, $search, [], $limit, $offset, 'entities', $readGroups);
        $this->assertIsArray($result);

        return $result;
    }

    protected function countEntities(array $types, array $search): int
    {
        $result = Idno::site()->db()->countObjects($types, $search);
        $this->assertIsInt($result);

        return $result;
    }

    protected function findOneByUuid(string $uuid): ?Entity
    {
        $result = Idno::site()->db()->getObject($uuid);
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
        $this->assertFalse(Idno::site()->db()->getObject('http://unknown.test/view/' . bin2hex(random_bytes(8))));
        $this->assertFalse(Idno::site()->db()->getObject($this->uuid('s_priv')));
    }
}
