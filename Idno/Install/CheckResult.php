<?php

declare(strict_types=1);

namespace Idno\Install;

/**
 * One thing the installer checked about the server: what it is, whether it is fine, and, when it is
 * not, what that means and how to fix it, in words for someone who is not a system administrator.
 */
final class CheckResult
{
    public const OK = 'ok';
    public const FAIL = 'fail';
    public const WARN = 'warn';

    public function __construct(
        public readonly string $id,
        public readonly string $state,
        public readonly string $title,
        public readonly string $meaning = '',
        public readonly string $fix = '',
        public readonly string $command = '',
    )
    {
    }
}
