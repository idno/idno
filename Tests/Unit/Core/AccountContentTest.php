<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Idno\Core\AccountContent;
use PHPUnit\Framework\TestCase;

/**
 * Which of the records an account owns go with it. Catches the site's own content (a static page, an access group, an invitation, a link preview,
 * a queued event) being deleted with the administrator who happened to make it, another account being deleted because it names the deleted one as its
 * owner, a plugin's site content that was registered being deleted all the same, and a record whose class has gone being kept for ever.
 */
final class AccountContentTest extends TestCase
{
    /**
     * @param array<string, mixed> $record
     */
    private static function deleted(array $record): bool
    {
        return !AccountContent::keeps($record);
    }

    public function testTheSiteContentOfCoreAndTheBundledPluginsIsKept(): void
    {
        foreach ([
            \Idno\Entities\AccessGroup::class,
            \Idno\Entities\Invitation::class,
            \Idno\Entities\UnfurledUrl::class,
            \Idno\Entities\AsynchronousQueuedEvent::class,
            'IdnoPlugins\\StaticPages\\StaticPage',
        ] as $class) {
            $this->assertTrue(AccountContent::keeps(['entity_subtype' => $class]), $class . ' belongs to the site, not to whoever made it');
        }
    }

    public function testAnotherAccountIsNeverTheContentOfTheOneThatNamesItsOwner(): void
    {
        $this->assertTrue(AccountContent::keeps(['entity_subtype' => \Idno\Entities\User::class]));
        $this->assertTrue(AccountContent::keeps(['entity_subtype' => \Idno\Entities\RemoteUser::class]));
    }

    public function testPostsAndRecordsOfClassesThatHaveGoneAreContent(): void
    {
        $this->assertTrue(self::deleted(['entity_subtype' => 'IdnoPlugins\\Status\\Status']));
        $this->assertTrue(self::deleted(['entity_subtype' => 'Idno\\Entities\\Reader\\FeedItem']), 'a class that no longer exists: nothing can keep it');
        $this->assertTrue(self::deleted(['entity_subtype' => '']));
        $this->assertTrue(self::deleted([]));
    }

    public function testAPluginRegistersItsSiteContentAndItsSubclassesAreKeptToo(): void
    {
        $this->assertTrue(self::deleted(['entity_subtype' => AccountContentSiteFixture::class]));

        AccountContent::keep(AccountContentSiteFixture::class);

        $this->assertTrue(AccountContent::keeps(['entity_subtype' => AccountContentSiteFixture::class]));
        $this->assertTrue(AccountContent::keeps(['entity_subtype' => '\\' . strtolower(AccountContentSiteFixture::class)]), 'a class name is compared as PHP compares it');
        $this->assertTrue(AccountContent::keeps(['entity_subtype' => AccountContentSiteSubclassFixture::class]));
    }
}

class AccountContentSiteFixture
{
}

final class AccountContentSiteSubclassFixture extends AccountContentSiteFixture
{
}
