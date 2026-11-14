<?php

declare(strict_types=1);

namespace Idno\Legacy;

/**
 * Thrown when the legacy application is booted but no configuration exists yet
 * (no config.ini or config.json): the site has to go through the installer first.
 */
final class NotInstalledException extends \RuntimeException
{
}
