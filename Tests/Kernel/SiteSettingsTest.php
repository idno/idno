<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Config\BootConfig;
use Idno\Core\Config;
use Idno\Legacy\LegacySiteSettings;
use Tests\IdnoTestCase;

final class SiteSettingsTest extends IdnoTestCase
{
    /** The stored config record as it was before the test; restored in tearDown. */
    private array|false $originalRecord = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRecord = \Idno\Core\Idno::site()->db()->getAnyRecord('config');
    }

    protected function tearDown(): void
    {
        // The web server shares this database, so always put the stored record back.
        if ($this->originalRecord) {
            \Idno\Core\Idno::site()->db()->saveRecord('config', $this->originalRecord);
        }
        parent::tearDown();
    }

    public function testIniValueOverridesDatabaseValue(): void
    {
        $this->assertNotFalse($this->originalRecord, 'the test site needs a stored config record');

        $boot = new BootConfig(['open_registration' => false], false);
        $stored = $this->originalRecord;
        $stored['open_registration'] = true;
        \Idno\Core\Idno::site()->db()->saveRecord('config', $stored);

        $config = new Config($boot);
        $config->load();

        $this->assertFalse($config->open_registration);
        $this->assertTrue((new LegacySiteSettings($config, $boot))->isOverridden('open_registration'));
    }

    public function testSetOnOverriddenKeyThrows(): void
    {
        $boot = new BootConfig(['open_registration' => false], false);
        $settings = new LegacySiteSettings(new Config($boot), $boot);

        $this->expectException(\LogicException::class);
        $settings->set('open_registration', true);
    }

    public function testSetAndSaveRoundTrips(): void
    {
        $this->assertNotFalse($this->originalRecord, 'the test site needs a stored config record');

        $boot = new BootConfig([], false);
        $config = new Config($boot);
        $config->load();
        $settings = new LegacySiteSettings($config, $boot);

        $settings->set('title', 'Round trip title');
        $settings->save();

        $reloaded = new Config($boot);
        $reloaded->load();
        $this->assertSame('Round trip title', $reloaded->title);
        $this->assertFalse($settings->isOverridden('title'));
    }
}
