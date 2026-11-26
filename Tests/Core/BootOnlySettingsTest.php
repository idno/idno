<?php

declare(strict_types=1);

namespace Tests\Core;

use Idno\Core\Idno;
use PHPUnit\Framework\TestCase;

/**
 * The outbound-HTTP settings come from the boot configuration only: they are never saved to, or
 * read from, the stored (admin-editable) settings.
 */
final class BootOnlySettingsTest extends TestCase
{
    public function testHttpAllowPrivateIsNeverSavedAndNeverReadFromTheStoredSettings(): void
    {
        $db = Idno::site()->db();
        $config = Idno::site()->config();
        $original = $db->getAnyRecord('config');
        $this->assertNotEmpty($original);
        $settings = &$config->config;

        try {
            // Saving: the key is not written.
            $settings['http_allow_private'] = '*';
            $this->assertTrue($config->save());
            $stored = $db->getAnyRecord('config');
            $this->assertIsArray($stored);
            $this->assertArrayNotHasKey('http_allow_private', $stored);

            // Loading: a stored value (written behind the application's back) is ignored.
            unset($settings['http_allow_private']);
            $stored['http_allow_private'] = '*';
            $db->saveRecord('config', $stored);
            $config->load();
            $this->assertArrayNotHasKey('http_allow_private', $settings);
        } finally {
            unset($settings['http_allow_private']);
            $db->saveRecord('config', $original);
            $config->load();
        }
    }

    public function testWebmentionTargetHostsAreNeverSavedAndNeverReadFromTheStoredSettings(): void
    {
        $db = Idno::site()->db();
        $config = Idno::site()->config();
        $original = $db->getAnyRecord('config');
        $this->assertNotEmpty($original);
        $settings = &$config->config;

        try {
            // Saving: the key is not written.
            $settings['webmention_target_hosts'] = 'old.example';
            $this->assertTrue($config->save());
            $stored = $db->getAnyRecord('config');
            $this->assertIsArray($stored);
            $this->assertArrayNotHasKey('webmention_target_hosts', $stored);

            // Loading: a stored value (written behind the application's back) is ignored.
            unset($settings['webmention_target_hosts']);
            $stored['webmention_target_hosts'] = 'old.example';
            $db->saveRecord('config', $stored);
            $config->load();
            $this->assertArrayNotHasKey('webmention_target_hosts', $settings);
        } finally {
            unset($settings['webmention_target_hosts']);
            $db->saveRecord('config', $original);
            $config->load();
        }
    }
}
