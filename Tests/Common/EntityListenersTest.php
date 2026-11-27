<?php

namespace Tests\Common;

use Idno\Common\Entity;
use Idno\Core\Idno;
use IdnoPlugins\Status\Status;
use IdnoPlugins\Text\Entry;

/**
 * Building an entity registers the lookup of content by address once for its class on the site's event
 * dispatcher, not once for each object: a process that loads thousands of entities (the queue worker, an
 * export) would otherwise keep every one of them alive, and ask each of them for every address looked up.
 */
class EntityListenersTest extends \Tests\IdnoTestCase
{
    private function listeners(): int
    {
        $events = Idno::site()->events();
        $property = new \ReflectionProperty($events, 'dispatcher');
        $property->setAccessible(true);

        return count($property->getValue($events)->getListeners('object/getbyurl'));
    }

    public function testBuildingEntitiesAddsNoListenerAfterTheFirstOfEachClass(): void
    {
        new Status();
        new Entry();
        $before = $this->listeners();
        $this->assertGreaterThan(0, $before);

        for ($i = 0; $i < 50; $i++) {
            new Status();
            new Entry();
        }

        $this->assertSame($before, $this->listeners(), 'no listener for an object of a class that has one');
    }

    public function testAClassNotSeenBeforeHasItsOwnListenerOnce(): void
    {
        new Status();
        // a class of the test's own: no other test has built one
        $make = static fn (): Entity => new class extends Status {
        };
        $before = $this->listeners();
        $make();
        $withClass = $this->listeners();
        $this->assertSame($before + 1, $withClass, 'a class not seen before gets its own listener');

        for ($i = 0; $i < 20; $i++) {
            $make();
        }
        $this->assertSame($withClass, $this->listeners(), 'and only one');
    }

    public function testASecondDispatcherGetsTheListenersAgain(): void
    {
        new Status();
        $site = Idno::site();
        $property = new \ReflectionProperty($site, 'dispatcher');
        $property->setAccessible(true);
        $original = $property->getValue($site);
        $this->assertGreaterThan(0, $this->listeners());

        $property->setValue($site, new \Idno\Core\EventDispatcher());
        try {
            $this->assertSame(0, $this->listeners(), 'a new dispatcher starts with none');
            new Status();
            $this->assertSame(1, $this->listeners(), 'a class already seen on another dispatcher is registered on this one');
            new Status();
            $this->assertSame(1, $this->listeners());
        } finally {
            $property->setValue($site, $original);
        }
    }

    public function testAnEntityIsNotKeptAliveByTheDispatcher(): void
    {
        new Status();
        $reference = \WeakReference::create($entity = new Status());
        $this->assertNotNull($reference->get());
        unset($entity);
        gc_collect_cycles();

        $this->assertNull($reference->get(), 'the site\'s event dispatcher holds no reference to an entity that was built');
    }

    public function testAnEntityIsStillFoundByItsAddress(): void
    {
        $owner = $this->admin();
        $status = new Status();
        $status->body = 'Found by its address';
        $status->setOwner($owner);
        $status->save(true);
        try {
            Entity::clearEntityCache();
            $found = Entity::getByURL($status->getURL(), false);
            $this->assertInstanceOf(Status::class, $found);
            $this->assertSame($status->getUUID(), $found->getUUID());
        } finally {
            Entity::invalidateCache($status->getUUID());
            $status->delete();
        }
    }
}
