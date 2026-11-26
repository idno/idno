<?php

namespace Tests\Core {

    use Idno\Core\Event;
    use Idno\Core\EventDispatcher;
    use Idno\Core\Idno;
    use Idno\Core\PageHandler;
    use Idno\Core\WebSub;

    /**
     * The protocol is called WebSub. What was named after its earlier name, and is used by plugins, held by hubs or waiting
     * in a queue, keeps working: the class name, the event name and the callback address.
     */
    class WebSubCompatibilityTest extends \Tests\IdnoTestCase
    {

        /// The earlier names, spelled out here because this is the test that they still work
        const OLD_CLASS = 'Idno\Core\PubSubHubbub';
        const OLD_EVENT = 'pubsubhubbub/ping';

        /**
         * What a new PHP process, which has loaded nothing but the autoloader, prints for a line of code. The code reads
         * the class names it is given as $argv[2] and $argv[3].
         */
        private function inNewProcess($code, $first, $second = '')
        {
            $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d error_reporting=0 -r '
                . escapeshellarg('require $argv[1]; ' . $code) . ' -- '
                . escapeshellarg(dirname(__DIR__, 2) . '/vendor/autoload.php') . ' '
                . escapeshellarg($first) . ' ' . escapeshellarg($second) . ' 2>/dev/null';

            return trim((string) shell_exec($command));
        }

        public function testEarlierClassNameIsTheSameClass()
        {
            $this->assertTrue(class_exists(self::OLD_CLASS), self::OLD_CLASS . ' should still name a class.');
            $this->assertSame(WebSub::class, (new \ReflectionClass(self::OLD_CLASS))->getName(), 'The earlier name should be the class itself, so that instanceof and static calls work under either name.');
        }

        public function testEarlierClassNameLoadsByItself()
        {
            $code = 'echo (new ReflectionClass($argv[2]))->getName();';

            $this->assertSame(WebSub::class, $this->inNewProcess($code, self::OLD_CLASS), 'Code that names the earlier class before anything has loaded WebSub should get it from the autoloader.');
        }

        public function testNewClassNameBringsTheEarlierOne()
        {
            $code = 'class_exists($argv[2]); echo class_exists($argv[3], false) ? "yes" : "no";';

            $this->assertSame('yes', $this->inNewProcess($code, WebSub::class, self::OLD_CLASS), 'Once WebSub is loaded, the earlier name should exist in whatever letter case it is written.');
        }

        public function testEventHasItsNewName()
        {
            $this->assertSame('websub/ping', WebSub::PING_EVENT);
            $this->assertSame('websub/ping', EventDispatcher::currentName(self::OLD_EVENT));
            $this->assertSame('websub/ping', EventDispatcher::currentName('websub/ping'));
            $this->assertSame('websub/received', EventDispatcher::currentName('websub/received'));
            $this->assertSame('saved', EventDispatcher::currentName('saved'), 'An event that was not renamed keeps its name.');
        }

        public function testListenerForTheEarlierEventNameHearsTheNewOne()
        {
            $events = new EventDispatcher();
            $heard  = [];
            $events->addListener(
                self::OLD_EVENT, function (Event $event) use (&$heard) {
                    $heard[] = $event->data()['n'];
                }
            );

            $events->triggerEvent(WebSub::PING_EVENT, ['n' => 1]);

            $this->assertSame([1], $heard);
        }

        public function testListenerForTheNewEventNameHearsTheEarlierOne()
        {
            $events = new EventDispatcher();
            $heard  = [];
            $events->addListener(
                WebSub::PING_EVENT, function (Event $event) use (&$heard) {
                    $heard[] = $event->data()['n'];
                }
            );

            // What code written before the change triggers, and what an event that was queued before it is dispatched as
            $events->triggerEvent(self::OLD_EVENT, ['n' => 1]);
            $events->dispatch(self::OLD_EVENT, new Event(['n' => 2]));

            $this->assertSame([1, 2], $heard);
        }

        public function testListenerForBothNamesRunsOnce()
        {
            $events   = new EventDispatcher();
            $heard    = 0;
            $listener = function (Event $event) use (&$heard) {
                $heard++;
            };
            $events->addListener(WebSub::PING_EVENT, $listener);
            $events->addListener(self::OLD_EVENT, $listener);

            $events->triggerEvent(WebSub::PING_EVENT);
            $this->assertSame(1, $heard, 'Triggered by its new name, the event should reach the listener once.');

            $events->triggerEvent(self::OLD_EVENT);
            $this->assertSame(2, $heard, 'Triggered by its earlier name, the event should reach the listener once.');
        }

        public function testTwoListenersForTheTwoNamesBothRun()
        {
            $events = new EventDispatcher();
            $heard  = [];
            $events->addListener(
                WebSub::PING_EVENT, function (Event $event) use (&$heard) {
                    $heard[] = 'new';
                }
            );
            $events->addListener(
                self::OLD_EVENT, function (Event $event) use (&$heard) {
                    $heard[] = 'earlier';
                }
            );

            $events->triggerEvent(WebSub::PING_EVENT);

            $this->assertSame(['new', 'earlier'], $heard);
        }

        public function testOtherEventsStillAddAListenerEachTime()
        {
            $events   = new EventDispatcher();
            $heard    = 0;
            $listener = function (Event $event) use (&$heard) {
                $heard++;
            };
            $events->addListener('saved', $listener);
            $events->addListener('saved', $listener);

            $events->triggerEvent('saved');

            $this->assertSame(2, $heard, 'Only renamed events drop a listener that is added twice.');
        }

        public function testEarlierEventNameReachesTheSitesOwnListener()
        {
            // The site's listener answers with what WebSub::publish() returned, which is false when there is nothing to publish
            $this->assertFalse(Idno::site()->events()->triggerEvent(self::OLD_EVENT, ['object' => null]));
            $this->assertFalse(Idno::site()->events()->triggerEvent(WebSub::PING_EVENT, ['object' => null]));
            $this->assertFalse(Idno::site()->events()->triggerEvent(WebSub::PING_EVENT, []), 'A ping with no post is no error.');
        }

        public function testCallbackAddressIsTheOneHubsHold()
        {
            $this->assertSame('pubsub/callback/', WebSub::CALLBACK_PATH, 'Hubs hold this address for every subscription already made: it must not change.');

            $url = WebSub::callbackUrl($this->user(), $this->admin());
            $this->assertSame(
                Idno::site()->config()->url . 'pubsub/callback/' . $this->user()->getID() . '/' . $this->admin()->getID(),
                $url
            );
        }

        public function testCallbackAddressIsHeldWhereCoreRegistersIt()
        {
            $path = '/pubsub/callback/' . $this->user()->getID() . '/' . $this->admin()->getID();

            foreach ([$path, $path . '/'] as $address) {
                $this->assertSame(\Idno\Legacy\ControllerRoute::class, PageHandler::chosenFor(Idno::site()->routes(), $address), "$address should be held by the placeholder, where core registers it.");
            }
        }

    }

}
