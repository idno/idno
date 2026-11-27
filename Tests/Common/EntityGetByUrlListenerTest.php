<?php

namespace Tests\Common;

use Idno\Common\Entity;
use Idno\Core\EventDispatcher;
use Idno\Core\Idno;
use Idno\Entities\GenericDataItem;
use IdnoPlugins\Status\Status;

/**
 * Every entity used to add its own `object/getbyurl` listener, bound to itself, when it was
 * constructed. The dispatcher then kept every entity the process ever built, and each lookup
 * ran once per entity. There is now one static listener per dispatcher and entity class.
 */
class EntityGetByUrlListenerTest extends \Tests\IdnoTestCase
{
    private $original;
    private $created = [];

    public function setUp(): void
    {
        $this->original = Idno::site()->events();
    }

    public function tearDown(): void
    {
        $this->swapDispatcher($this->original);
        foreach ($this->created as $entity) {
            $entity->delete();
        }
        $this->created = [];
    }

    private function swapDispatcher(EventDispatcher $dispatcher): void
    {
        $property = new \ReflectionProperty(Idno::class, 'dispatcher');
        $property->setValue(Idno::site(), $dispatcher);
    }

    private function freshDispatcher(): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $this->swapDispatcher($dispatcher);
        return $dispatcher;
    }

    private function listenerCount(EventDispatcher $dispatcher): int
    {
        $property = new \ReflectionProperty(EventDispatcher::class, 'dispatcher');
        return count($property->getValue($dispatcher)->getListeners('object/getbyurl'));
    }

    private function make(string $class, string $slugWords)
    {
        if ($class === Status::class) {
            $entity = new Status();
            $entity->body = $slugWords;
        } else {
            $entity = new GenericDataItem();
            $entity->setDatatype('getbyurl-listener-test');
            $entity->title = $slugWords;
            $entity->setSlug($slugWords);
        }
        $entity->setOwner($this->user());
        $entity->save(true);
        $this->created[] = $entity;
        return $entity;
    }

    public function testConstructingManyEntitiesAddsOneListenerPerClass()
    {
        $dispatcher = $this->freshDispatcher();

        for ($i = 0; $i < 50; $i++) {
            new Status();
        }
        $this->assertSame(1, $this->listenerCount($dispatcher));

        for ($i = 0; $i < 50; $i++) {
            new GenericDataItem();
            new Status();
        }
        $this->assertSame(2, $this->listenerCount($dispatcher));
    }

    public function testEntitiesAreFreedWhenTheyGoOutOfScope()
    {
        $this->freshDispatcher();
        gc_collect_cycles();

        $references = [];
        $before = memory_get_usage();
        $build = function () use (&$references) {
            for ($i = 0; $i < 200; $i++) {
                $entity = new Status();
                $references[] = \WeakReference::create($entity);
            }
        };
        $build();
        gc_collect_cycles();
        $retained = memory_get_usage() - $before;

        $alive = count(array_filter($references, fn($r) => $r->get() !== null));
        $this->assertSame(0, $alive, "$alive of 200 entities were still referenced (retained " . round($retained / 200) . ' bytes each)');
    }

    public function testListenerCountDoesNotGrowWithEntitiesLoaded()
    {
        $dispatcher = $this->freshDispatcher();
        $first = $this->make(Status::class, 'listener count status');
        // Saving builds other entities too (the owner), so count what there is now
        $count = $this->listenerCount($dispatcher);
        $this->assertGreaterThanOrEqual(1, $count);
        // Hydrating records builds entities as well, of classes that already have a listener
        for ($i = 0; $i < 5; $i++) {
            $this->assertNotEmpty(Status::getFromAll(['uuid' => $first->getUUID()]));
            $this->assertNotEmpty(Entity::getByUUID($first->getUUID(), false));
        }
        $this->assertSame($count, $this->listenerCount($dispatcher));
    }

    public function testAReplacedDispatcherGetsItsOwnListener()
    {
        $first = $this->freshDispatcher();
        new Status();
        $second = $this->freshDispatcher();
        $this->assertSame(0, $this->listenerCount($second));
        new Status();
        $this->assertSame(1, $this->listenerCount($second));
        $this->assertSame(1, $this->listenerCount($first));
    }

    public function testAClassFirstBuiltAfterTheEventWasTriggeredStillRegisters()
    {
        $dispatcher = $this->freshDispatcher();
        $status = $this->make(Status::class, 'late class status');
        $this->assertNotFalse(Entity::getByURL($status->getURL(), false));

        $generic = $this->make(GenericDataItem::class, 'late class generic');
        $this->assertSame(2, $this->listenerCount($dispatcher));
        $generic->canonical = 'https://localhost/late-canonical';
        $generic->save(true);
        $found = Entity::getByURL('https://localhost/late-canonical', false);
        $this->assertInstanceOf(GenericDataItem::class, $found);
        $this->assertSame($generic->getUUID(), $found->getUUID());
    }

    public function orderProvider()
    {
        return [
            'status first' => [[Status::class, GenericDataItem::class]],
            'generic first' => [[GenericDataItem::class, Status::class]],
        ];
    }

    /**
     * @dataProvider orderProvider
     */
    public function testLookupsAreTheSameInEitherConstructionOrder(array $order)
    {
        $dispatcher = $this->freshDispatcher();
        foreach ($order as $class) {
            new $class();
        }
        $status = $this->make(Status::class, 'equivalence status ' . substr(md5(implode($order)), 0, 6));
        $generic = $this->make(GenericDataItem::class, 'equivalence generic');
        $generic->canonical = 'https://localhost/canonical-' . substr(md5(implode($order)), 0, 6);
        $generic->save(true);

        // Slug (the address of a post)
        $found = Entity::getByURL($status->getURL(), false);
        $this->assertInstanceOf(Status::class, $found);
        $this->assertSame($status->getUUID(), $found->getUUID());

        // Canonical address
        $found = Entity::getByURL($generic->canonical, false);
        $this->assertInstanceOf(GenericDataItem::class, $found);
        $this->assertSame($generic->getUUID(), $found->getUUID());

        // A slug on another entity's address resolves the same entity
        $found = Entity::getByURL(Idno::site()->config()->url . '2020/' . $status->getSlug(), false);
        $this->assertSame($status->getUUID(), $found->getUUID());

        // A UUID address and a short URL are not looked up by this event, as before.
        // (A short URL is written out: Entity::getShortURL() declares a function and can run once per process.)
        $this->assertFalse(Entity::getByURL($status->getUUID(), false));
        $this->assertFalse(Entity::getByURL(Idno::site()->config()->url . 's/o2aoV', false));

        // An unknown local address, and a remote one
        $this->assertFalse(Entity::getByURL(Idno::site()->config()->url . 'nothing-lives-here', false));
        $this->assertFalse(Entity::getByURL('https://example.org/nothing-lives-here', false));
        $this->assertSame(count($order), $this->listenerCount($dispatcher));
    }

    public function testASubclassThatOverridesRegisterEventHooksStillWorks()
    {
        $dispatcher = $this->freshDispatcher();
        $subclass = new class () extends GenericDataItem {
            public static $registered = 0;
            public function registerEventHooks()
            {
                parent::registerEventHooks();
                static::$registered++;
                Idno::site()->events()->addListener('test/hooks', function () {
                });
            }
        };
        $class = get_class($subclass);
        new $class();
        new $class();
        $this->assertSame(3, $class::$registered);
        $this->assertSame(1, $this->listenerCount($dispatcher));
    }
}
