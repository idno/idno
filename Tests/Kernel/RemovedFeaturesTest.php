<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Config\BootConfig;
use Idno\Core\Config;

/**
 * Pins features that were removed outright, so they cannot quietly return.
 * Extends the characterisation base class for black-box requests via site().
 */
final class RemovedFeaturesTest extends \Tests\Characterisation\CharacterisationTestCase
{
    public function testHubIsGone(): void
    {
        $this->assertFalse(class_exists(\Idno\Core\Hub::class));
        $this->assertFalse(method_exists(\Idno\Core\Idno::class, 'hub'));
        $this->assertFalse(class_exists('Idno\Pages\Hub\Register\Site'));
        $this->assertFalse(class_exists('Idno\Pages\Hub\Register\User'));
        $this->assertSame(404, $this->site()->request('GET', 'hub/register/site/callback')->status);
        $this->assertSame(404, $this->site()->request('GET', 'hub/register/user/callback')->status);
    }

    public function testHubConfigSettingsAreIgnored(): void
    {
        $config = new Config(new BootConfig(['known_hub' => 'https://hub.example.com/', 'idno_hub' => 'https://hub.example.com/'], false));

        $this->assertSame('https://hub.example.com/', $config->known_hub, 'a stale hub setting is carried as an ordinary unused value and does not break boot');
        $this->assertTrue(is_string($config->url) && $config->url !== '');
    }

    public function testFeedbackPageSurvivesWithoutHub(): void
    {
        // Feedback posts to the project's feedback service, not a hub, so the pages stay.
        $this->assertTrue(class_exists(\Idno\Pages\Account\Settings\Feedback::class));
        $this->assertNotSame(404, $this->site()->request('GET', 'account/settings/feedback/')->status);
        $this->assertStringNotContainsString(
            'hub',
            (string)file_get_contents((new \ReflectionClass(\Idno\Pages\Account\Settings\Feedback::class))->getFileName())
        );
    }
}
