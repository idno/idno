<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Test-environment only: a plugin's page that extends the post permalink's page and overrides nothing (IDNO_TEST_PERMALINK_ROUTES=subclass registers it
 * at `/widgetsub/:id/?`): what a third-party plugin written against `Idno\Pages\Entity\View` is.
 */
class ThirdPartyPermalinkPage extends \Idno\Pages\Entity\View
{
}
