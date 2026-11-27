<?php

namespace Idno\Pages\Service\Queues {

    use Idno\Core\Idno;

    class GC extends \Idno\Common\Page
    {

        public function getContent()
        {

            Idno::site()->template()->setTemplateType('json');

            \Idno\Core\Service::gatekeeper();

            $eventqueue = \Idno\Core\Idno::site()->queue();
            if (!$eventqueue instanceof \Idno\Core\AsynchronousQueue) {
                throw new \RuntimeException("You are not running an asynchronous message queue, so garbage collection is unnecessary.");
            }

            $queue = $this->getTextInput('queue', 'default');

            \Idno\Core\Idno::site()->logging()->debug("Garbage Collecting event queue $queue");

            $eventqueue->gc(300, $queue);

            Idno::site()->template()->__(
                [
                'gc' => true
                ]
            )->drawPage();
        }
    }
}