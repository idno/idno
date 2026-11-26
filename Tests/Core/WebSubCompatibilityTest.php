<?php

namespace Tests\Core {

    use Idno\Core\Event;
    use Idno\Core\EventDispatcher;
    use Idno\Core\Idno;
    use Idno\Core\WebSub;

    /**
     * The protocol is called WebSub. What was named after its earlier name, and is used by
     * plugins, held by hubs or waiting in a queue, keeps working: the two class names, the
     * event name and the callback address.
     */
    class WebSubCompatibilityTest extends \Tests\IdnoTestCase
    {

        /// The earlier names, spelled out here because this is the test that they still work
        const OLD_CLASS    = 'Idno\Core\PubSubHubbub';
        const OLD_CALLBACK = 'Idno\Pages\Pubsubhubbub\Callback';
        const OLD_EVENT    = 'pubsubhubbub/ping';

        /**
         * What a new PHP process, which has loaded nothing but the autoloader, prints for a
         * line of code. The code reads the class names it is given as $argv[2] and $argv[3].
         */
        private function inNewProcess($code, $first, $second = '')
        {
            $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d error_reporting=0 -r '
                . escapeshellarg('require $argv[1]; ' . $code) . ' -- '
                . escapeshellarg(dirname(__DIR__, 2) . '/vendor/autoload.php') . ' '
                . escapeshellarg($first) . ' ' . escapeshellarg($second) . ' 2>/dev/null';

            return trim((string) shell_exec($command));
        }

        public static function classNames()
        {
            return [
                'the component' => [self::OLD_CLASS, 'Idno\Core\WebSub'],
                'the callback page' => [self::OLD_CALLBACK, 'Idno\Pages\WebSub\Callback'],
            ];
        }

        /**
         * @dataProvider classNames
         */
        public function testEarlierClassNameIsTheSameClass($old, $new)
        {
            $this->assertTrue(class_exists($old), "$old should still name a class.");
            $this->assertSame($new, (new \ReflectionClass($old))->getName(), "$old should be $new itself, so that instanceof and static calls work under either name.");
        }

        /**
         * @dataProvider classNames
         */
        public function testEarlierClassNameLoadsByItself($old, $new)
        {
            $code = 'echo (new ReflectionClass($argv[2]))->getName();';

            $this->assertSame($new, $this->inNewProcess($code, $old), "Code that names $old before anything has loaded $new should get it from the autoloader.");
        }

        /**
         * @dataProvider classNames
         */
        public function testNewClassNameBringsTheEarlierOne($old, $new)
        {
            $code = 'class_exists($argv[2]); echo class_exists($argv[3], false) ? "yes" : "no";';

            $this->assertSame('yes', $this->inNewProcess($code, $new, $old), "Once $new is loaded, $old should exist in whatever letter case it is written.");
        }

        public function testEventHasItsNewName()
        {
            $this->assertSame('websub/ping', WebSub::PING_EVENT);
            $this->assertSame('websub/ping', EventDispatcher::currentName(self::OLD_EVENT));
            $this->assertSame('websub/ping', EventDispatcher::currentName('websub/ping'));
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

            // What code written before the change triggers, and what an event that was
            // queued before it is dispatched as
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
            // The site's listener answers with what WebSub::publish() returned, which is
            // false when there is nothing to publish; an event nobody hears answers true
            $this->assertFalse(Idno::site()->events()->triggerEvent(self::OLD_EVENT, ['object' => null]));
            $this->assertFalse(Idno::site()->events()->triggerEvent(WebSub::PING_EVENT, ['object' => null]));
            $this->assertFalse(Idno::site()->events()->triggerEvent(WebSub::PING_EVENT, []), 'A ping from a hub carries no post, and that is no error.');
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

        public function testCallbackAddressIsServedByTheCallbackPage()
        {
            $path = '/pubsub/callback/' . $this->user()->getID() . '/' . $this->admin()->getID();

            foreach ([$path, $path . '/'] as $address) {
                $page = Idno::site()->routes()->getRoute($address);
                $this->assertInstanceOf(\Idno\Pages\WebSub\Callback::class, $page, "$address should be answered by the callback page.");
                $this->assertInstanceOf(self::OLD_CALLBACK, $page);
            }
        }

    }

}
