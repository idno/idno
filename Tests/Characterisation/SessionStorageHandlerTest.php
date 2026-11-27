<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * A session storage a plugin supplies (the `sessions_storage` setting) is offered the session AFTER the site's session ini values are set, so a
 * handler that starts the session itself or reads them gets SameSite=Lax, strict mode and HttpOnly. Catches `Session::init()` setting those
 * values after the handler step (a plugin's handler would then open sessions without them).
 */
final class SessionStorageHandlerTest extends PrivateSiteTestCase
{
    private string $log = '';

    protected function siteEnvironment(): array
    {
        $this->log = sys_get_temp_dir() . '/idno-session-ini-' . bin2hex(random_bytes(6));

        return ['IDNO_SESSIONS_STORAGE' => \Tests\Fixtures\SessionIniProbeStorage::class, 'IDNO_TEST_SESSION_INI_LOG' => $this->log];
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->log !== '' && is_file($this->log)) {
                unlink($this->log);
            }
        }
    }

    public function testAPluginsSessionHandlerSeesTheSitesSessionSettingsWhenItIsOffered(): void
    {
        $this->site()->request('GET', '/');

        $this->assertFileExists($this->log, 'the plugin storage was offered the session');
        $first = json_decode((string) strtok((string) file_get_contents($this->log), "\n"), true);
        $this->assertSame(['samesite' => 'Lax', 'strict' => '1', 'httponly' => '1'], $first);
    }
}
