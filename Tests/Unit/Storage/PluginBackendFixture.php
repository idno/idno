<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

/**
 * A database backend class as a plugin would supply one (a subclass of Idno\Core\DataConcierge),
 * for ConnectionParametersTest: only its name and parent class are used, it is never built.
 */
abstract class PluginBackendFixture extends \Idno\Core\DataConcierge
{
}
