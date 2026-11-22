<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Idno\Queue\UserContext;

/**
 * A UserContext that records who is logged on, and knows which users exist.
 */
class RecordingUserContext implements UserContext
{
    public ?string $loggedOn = null;

    /**
     * @param list<string>|null $existing the uuids of the users that exist (null: all of them)
     */
    public function __construct(private readonly ?\ArrayObject $log = null, private readonly ?array $existing = null)
    {
    }

    public function runAs(?string $uuid, callable $work): mixed
    {
        $this->log?->append('on ' . ($uuid ?? 'null'));
        try {
            if ($uuid !== null && $this->existing !== null && !in_array($uuid, $this->existing, true)) {
                throw new \RuntimeException('Invalid user (' . $uuid . ') given for runAsContext, aborting');
            }
            $this->loggedOn = $uuid;

            return $work();
        } finally {
            $this->loggedOn = null;
            $this->log?->append('off');
        }
    }

    public function ensureNobodyLoggedOn(): void
    {
        $this->loggedOn = null;
    }
}
