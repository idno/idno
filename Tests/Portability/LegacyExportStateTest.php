<?php

declare(strict_types=1);

namespace Tests\Portability;

use Idno\Core\Idno;
use Idno\Legacy\LegacyExportState;
use Tests\IdnoTestCase;

/**
 * The archive export's state in the stored settings: it reads them again before each change and sets only
 * its own keys (a worker's configuration object is as old as the worker), and puts the site's time zone back
 * after the save that resets it.
 */
final class LegacyExportStateTest extends IdnoTestCase
{
    /** @var array<string, mixed> */
    private array $stored = [];

    /** @var array<string, mixed> */
    private array $memory = [];

    private string $timezone = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->stored = (array) Idno::site()->db()->getAnyRecord('config');
        $this->memory = Idno::site()->config()->config;
        $this->timezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        // The stored settings as they were, and this process's copy of them (loading merges over what is there).
        Idno::site()->db()->saveRecord('config', $this->stored);
        Idno::site()->config()->config = $this->memory;
        date_default_timezone_set($this->timezone);
        parent::tearDown();
    }

    private function state(): LegacyExportState
    {
        return new LegacyExportState(Idno::site()->config());
    }

    /**
     * @return array<string, mixed>
     */
    private function storedNow(): array
    {
        return (array) Idno::site()->db()->getAnyRecord('config');
    }

    public function testASettingAnAdministratorChangedMeanwhileIsNotRevertedByTheJob(): void
    {
        $state = $this->state();
        // The configuration object this process holds is old: someone else saves a setting behind its back.
        $other = $this->storedNow();
        $other['changed_meanwhile'] = 'by the administrator';
        Idno::site()->db()->saveRecord('config', $other);
        $this->assertArrayNotHasKey('changed_meanwhile', Idno::site()->config()->config, 'the object in this process does not know');

        $this->assertTrue($state->begin(1_700_000_000, 6 * 3600));
        $state->finish('0123456789abcdef0123456789abcdef', 'example.zip', 1_700_000_000);

        $stored = $this->storedNow();
        $this->assertSame('by the administrator', $stored['changed_meanwhile'], 'what the job did not set is left as it was found');
        $this->assertSame('0123456789abcdef0123456789abcdef', $stored['export_file_id']);
        $this->assertSame(0, $stored['export_in_progress']);
    }

    public function testBeginRefusesWhileOneRunsUnlessItsFlagIsStale(): void
    {
        $state = $this->state();

        $this->assertTrue($state->begin(1_700_000_000, 6 * 3600));
        $this->assertTrue($state->read()['inProgress']);
        $this->assertFalse($state->begin(1_700_000_000 + 6 * 3600 - 1, 6 * 3600));
        $this->assertSame(1_700_000_000, $state->read()['requested'], 'a refusal changes nothing');
        $this->assertTrue($state->begin(1_700_000_000 + 6 * 3600, 6 * 3600));
        $this->assertSame(1_700_000_000 + 6 * 3600, $state->read()['requested']);
    }

    public function testTheTrackedFilesAreStoredReadAgainAndSetOnlyTheirOwnKey(): void
    {
        $state = $this->state();
        $other = $this->storedNow();
        $other['changed_meanwhile'] = 'by the administrator';
        Idno::site()->db()->saveRecord('config', $other);

        $state->trackFile('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'upload', 100);
        $state->trackFile('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'archive', 200);

        $stored = $this->storedNow();
        $this->assertSame('by the administrator', $stored['changed_meanwhile']);
        $this->assertSame(
            [['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'kind' => 'upload', 'at' => 100], ['id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'kind' => 'archive', 'at' => 200]],
            $stored['portability_tracked']
        );

        $state->untrackFiles(['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'cccccccccccccccccccccccccccccccc']);
        $this->assertSame([['id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'kind' => 'archive', 'at' => 200]], $this->state()->trackedFiles());
        $this->assertSame('by the administrator', $this->storedNow()['changed_meanwhile']);
    }

    public function testFinishReturnsThePreviousArchiveAndForgetClearsIt(): void
    {
        $state = $this->state();

        $this->assertNull($state->finish('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'one.zip', 1));
        $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $state->finish('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'two.zip', 2));
        $this->assertSame(['requested' => 0, 'created' => 2, 'swept' => 0, 'inProgress' => false, 'fileId' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'filename' => 'two.zip'], [...$state->read(), 'requested' => 0, 'swept' => 0]);

        $this->assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $state->forgetArchive());
        $this->assertNull($state->read()['fileId']);
        $this->assertNull($state->forgetArchive(), 'nothing to forget');
    }

    public function testFailClearsTheFlag(): void
    {
        $state = $this->state();
        $state->begin(1_700_000_000, 6 * 3600);

        $state->fail();

        $this->assertFalse($state->read()['inProgress']);
        $this->assertSame(0, $this->storedNow()['export_in_progress']);
    }

    public function testTheSitesTimeZoneIsPutBackAfterTheSaves(): void
    {
        $config = Idno::site()->config();
        $config->timezone = 'Europe/Budapest';
        $config->save();
        date_default_timezone_set('Europe/Budapest');
        $state = $this->state();

        $state->begin(1_700_000_000, 6 * 3600);
        $this->assertSame('Europe/Budapest', date_default_timezone_get(), 'after begin()');
        $state->begin(1_700_000_001, 6 * 3600); // refused: the settings were read again, nothing saved
        $this->assertSame('Europe/Budapest', date_default_timezone_get(), 'after a refusal');
        $state->finish('cccccccccccccccccccccccccccccccc', 'x.zip', 3);
        $this->assertSame('Europe/Budapest', date_default_timezone_get(), 'after finish()');
    }
}
