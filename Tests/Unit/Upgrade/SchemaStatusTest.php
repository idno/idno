<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use Idno\Upgrade\PublicMessage;
use Idno\Upgrade\SchemaStatus;
use Idno\Upgrade\UpgradePage;
use PHPUnit\Framework\TestCase;

/**
 * What each schema status means for every entry point, decided once (SchemaStatus's methods and
 * PublicMessage::forStatus()). Every case is listed here: a new case fails this test until someone
 * has decided whether it closes the gate, whether the upgrade advances by steps or is refused, and
 * which public message it carries.
 */
final class SchemaStatusTest extends TestCase
{
    /**
     * @return array<string, array{closed: bool, steps: bool, refused: bool, message: ?string}>
     */
    private static function decisions(): array
    {
        return [
            SchemaStatus::NotApplicable->name => ['closed' => false, 'steps' => false, 'refused' => false, 'message' => null],
            SchemaStatus::Unreachable->name => ['closed' => false, 'steps' => false, 'refused' => false, 'message' => PublicMessage::unreachable()->text],
            SchemaStatus::UnsupportedServer->name => ['closed' => true, 'steps' => false, 'refused' => true, 'message' => PublicMessage::serverRequirement()->text],
            SchemaStatus::Empty->name => ['closed' => true, 'steps' => true, 'refused' => false, 'message' => null],
            SchemaStatus::Current->name => ['closed' => false, 'steps' => false, 'refused' => false, 'message' => null],
            SchemaStatus::PendingMigrations->name => ['closed' => true, 'steps' => true, 'refused' => false, 'message' => null],
            SchemaStatus::Legacy->name => ['closed' => true, 'steps' => true, 'refused' => false, 'message' => null],
            SchemaStatus::LegacyTooOld->name => ['closed' => true, 'steps' => false, 'refused' => true, 'message' => PublicMessage::tooOld()->text],
            SchemaStatus::RenamedWithoutRecord->name => ['closed' => true, 'steps' => false, 'refused' => true, 'message' => PublicMessage::renamedWithoutRecord()->text],
            SchemaStatus::OneXBesideCompletedUpgrade->name => ['closed' => true, 'steps' => false, 'refused' => true, 'message' => PublicMessage::oneXBesideCompletedUpgrade()->text],
            SchemaStatus::MongoRetired->name => ['closed' => true, 'steps' => false, 'refused' => true, 'message' => PublicMessage::mongoRetired()->text],
        ];
    }

    public function testEveryStatusIsDecided(): void
    {
        $decisions = self::decisions();
        self::assertSame(array_map(static fn (SchemaStatus $status): string => $status->name, SchemaStatus::cases()), array_keys($decisions), 'every case, and only the cases, in declaration order');
        foreach (SchemaStatus::cases() as $status) {
            $expected = $decisions[$status->name];
            self::assertSame($expected['closed'], $status->closesTheGate(), $status->name . ': closes the gate');
            self::assertSame($expected['steps'], $status->advancesBySteps(), $status->name . ': advances by steps');
            self::assertSame($expected['refused'], $status->refusesTheUpgrade(), $status->name . ': refuses the upgrade');
            self::assertSame($expected['message'], PublicMessage::forStatus($status)?->text, $status->name . ': its public message');
            self::assertFalse($status->advancesBySteps() && $status->refusesTheUpgrade(), $status->name . ': never both');
            if ($status->advancesBySteps() || $status->refusesTheUpgrade()) {
                self::assertTrue($status->closesTheGate(), $status->name . ': only a closed gate has an upgrade to advance or refuse');
            }
        }
    }

    public function testTheMongoMessageNamesTheImportCommand(): void
    {
        self::assertSame(
            'This site is configured for MongoDB, which Idno no longer runs on. Move its data to MySQL, MariaDB or SQLite with php bin/console idno:import:mongo: see the upgrade notes.',
            PublicMessage::mongoRetired()->text,
        );
    }

    public function testTheServerRefusalNamesWhatWasFound(): void
    {
        $found = PublicMessage::unsupportedServer('MySQL', '5.7.44');

        self::assertSame($found, PublicMessage::forStatus(SchemaStatus::UnsupportedServer, $found));
        self::assertNull(PublicMessage::forStatus(SchemaStatus::Legacy, $found), 'only for UnsupportedServer');
    }

    /**
     * The page and the plain text follow the decisions: a script and a form exactly when the upgrade
     * advances by steps; `Idno cannot start: <message>` exactly when it is refused.
     */
    public function testThePageFollowsTheDecisions(): void
    {
        foreach (SchemaStatus::cases() as $status) {
            if (!$status->closesTheGate()) {
                continue;
            }
            $html = UpgradePage::html($status, null, '', 'bm9uY2U=');
            self::assertSame($status->advancesBySteps(), str_contains($html, '<script'), $status->name . ': a script');
            self::assertSame($status->advancesBySteps(), str_contains($html, '<form'), $status->name . ': a form');
            $message = PublicMessage::forStatus($status);
            self::assertSame(
                $status->refusesTheUpgrade() ? 'Idno cannot start: ' . $message?->text . "\n" : "Idno is upgrading its database.\n",
                UpgradePage::text($status),
                $status->name,
            );
        }
    }
}
