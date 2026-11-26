<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\WebSub;

use Idno\Http\Controller\WebSub\PendingList;
use PHPUnit\Framework\TestCase;

/**
 * The list of subscriptions a member is waiting for a hub to verify: how it is read from what is stored (whatever shape an earlier release left), how an
 * entry is added and expires, and how a verified one is removed.
 */
final class PendingListTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testWhatIsStoredIsReadAsAnObjectOfTheOneClassAndNothingElse(): void
    {
        $pending = new \stdClass();
        $pending->subscribe = ['a'];

        $this->assertEquals($pending, PendingList::read(serialize($pending)));
        foreach ([null, false, 7, '', 'not serialised', serialize('text'), serialize(['a']), serialize(['subscribe' => ['a']]), serialize(new \ArrayObject(['subscribe' => ['a']]))] as $stored) {
            $this->assertEquals(new \stdClass(), PendingList::read($stored), 'a list that is not an object of the one class is a new one: ' . json_encode($stored));
        }
    }

    public function testAnEntryIsAddedToAListThatIsMissingOrIsNotAList(): void
    {
        foreach ([new \stdClass(), (object) ['subscribe' => 'text'], (object) ['subscribe' => null], (object) ['unsubscribe' => ['x']]] as $pending) {
            PendingList::add($pending, 'subscribe', 'ada', self::NOW);
            $this->assertSame(['ada'], $pending->subscribe);
        }
        $pending = (object) ['unsubscribe' => ['earlier']];
        PendingList::add($pending, 'unsubscribe', 'later', self::NOW);
        $this->assertSame(['earlier', 'later'], $pending->unsubscribe);
    }

    public function testAnEntryIsAddedOnce(): void
    {
        $pending = new \stdClass();
        PendingList::add($pending, 'subscribe', 'ada', self::NOW);
        PendingList::add($pending, 'subscribe', 'ada', self::NOW + 5);

        $this->assertSame(['ada'], $pending->subscribe);
    }

    public function testAnEntryIsPendingForTheLeaseAndNotAfter(): void
    {
        $pending = new \stdClass();
        PendingList::add($pending, 'subscribe', 'ada', self::NOW);

        $this->assertTrue(PendingList::isPending($pending, 'subscribe', ['ada'], self::NOW + PendingList::LEASE_SECONDS));
        $this->assertFalse(PendingList::isPending($pending, 'subscribe', ['ada'], self::NOW + PendingList::LEASE_SECONDS + 1));
        $this->assertFalse(PendingList::isPending($pending, 'unsubscribe', ['ada'], self::NOW), 'the other mode');
        $this->assertFalse(PendingList::isPending($pending, 'subscribe', ['bob'], self::NOW), 'another entry');
    }

    public function testAnEntryWithNoTimeIsPendingAsAnEarlierReleaseLeftIt(): void
    {
        $pending = (object) ['subscribe' => ['ada'], 'unsubscribe' => 'not a list'];

        $this->assertTrue(PendingList::isPending($pending, 'subscribe', ['ada', 'other'], self::NOW));
        $this->assertFalse(PendingList::isPending($pending, 'unsubscribe', ['ada'], self::NOW));
        $this->assertFalse(PendingList::isPending(new \stdClass(), 'subscribe', ['ada'], self::NOW));
    }

    public function testAddingDropsTheEntriesThatHaveExpired(): void
    {
        $pending = new \stdClass();
        PendingList::add($pending, 'subscribe', 'old', self::NOW);
        PendingList::add($pending, 'subscribe', 'unstamped-kept', self::NOW);
        unset($pending->at['subscribe:unstamped-kept']);

        PendingList::add($pending, 'subscribe', 'new', self::NOW + PendingList::LEASE_SECONDS + 10);

        $this->assertSame(['unstamped-kept', 'new'], $pending->subscribe);
        $this->assertSame(['subscribe:new'], array_keys($pending->at));
    }

    public function testARemovedEntryLeavesTheRestOfTheObject(): void
    {
        $pending = (object) ['subscribe' => ['a', 'b'], 'unsubscribe' => ['c']];
        PendingList::add($pending, 'subscribe', 'd', self::NOW);

        PendingList::remove($pending, 'subscribe', ['a', 'd']);

        $this->assertSame(['b'], $pending->subscribe);
        $this->assertSame(['c'], $pending->unsubscribe);
        $this->assertSame([], $pending->at);
    }
}
