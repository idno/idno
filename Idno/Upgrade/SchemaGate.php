<?php

declare(strict_types=1);

namespace Idno\Upgrade;

/**
 * Whether the legacy application may boot on the site's database: the gate is closed while the
 * schema is not current (see SchemaStatus::closesTheGate()). Idno\Kernel asks it when it boots, in a
 * kernel that would boot the legacy application; the status is computed when first asked and kept
 * for the life of this object (one per container, so once per request or console run).
 *
 * Constructing it queries nothing.
 */
final class SchemaGate
{
    /** What the entry points that cannot serve the upgrade (Idno/start.php, the console) say while the gate is closed. */
    public const CLOSED_MESSAGE = 'Idno\'s database needs an upgrade: load the site in a browser or run php bin/console idno:upgrade';

    private ?SchemaStatus $status = null;

    public function __construct(private readonly SchemaState $state)
    {
    }

    public function status(): SchemaStatus
    {
        return $this->status ??= $this->state->status();
    }

    public function closed(): bool
    {
        return $this->status()->closesTheGate();
    }

    /**
     * Whether the status has been computed (by the kernel's boot); asking this computes nothing.
     */
    public function asked(): bool
    {
        return $this->status !== null;
    }

    /**
     * For an unsupported database server: what is required and what was found; otherwise null.
     */
    public function refusal(): ?PublicMessage
    {
        $this->status();

        return $this->state->refusal();
    }
}
