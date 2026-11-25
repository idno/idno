<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\Event;
use Idno\Core\EventDispatcher;
use Idno\Core\Idno;

/**
 * Test-environment only (installed by TestEventHooks, which config/services_test.php makes the configurator of the
 * legacy event dispatcher): when the environment variable IDNO_TEST_RECORD_PAGES names a file, the page events
 * `page/ready`, `page/head`, `page/get`, `page/post` and `page/put` append one JSON line each to it: the event's name, the `page_class` and the `arguments` of its
 * data (null when it has none), the class of the current page, the first word of the template's body classes, the
 * template type, whether the page is an XHR one, whether it is a permalink and the handle of the page's owner (null
 * when it has none). On `page/head` it also sends the header `X-Idno-Test-Page-Head: 1`, as a plugin's `page/head`
 * listener may. Listeners only record (and send that one header): they change nothing else. With the variable unset
 * nothing is registered.
 */
final class PageEventRecorder
{
    public const EVENTS = ['page/ready', 'page/head', 'page/get', 'page/post', 'page/put'];

    public static function install(EventDispatcher $dispatcher): void
    {
        $file = getenv('IDNO_TEST_RECORD_PAGES');
        if (!is_string($file) || $file === '') {
            return;
        }
        foreach (self::EVENTS as $name) {
            $dispatcher->addListener($name, static function (Event $event) use ($file, $name): void {
                $data = $event->data();
                $page = Idno::site()->currentPage();
                $owner = is_object($page) && method_exists($page, 'getOwner') ? $page->getOwner() : null;
                try {
                    $bodyClass = explode(' ', (string) Idno::site()->template()->getBodyClasses())[0];
                } catch (\Throwable) {
                    $bodyClass = null; // a console process has no request address
                }
                $line = json_encode([
                    'event' => $name,
                    'page_class' => is_array($data) ? ($data['page_class'] ?? null) : null,
                    'arguments' => is_array($data) ? ($data['arguments'] ?? null) : null,
                    'current' => is_object($page) ? get_class($page) : null,
                    'body_class' => $bodyClass,
                    'type' => Idno::site()->template()->getTemplateType(),
                    'xhr' => is_object($page) ? ($page->xhr ?? null) : null,
                    'permalink' => is_object($page) && method_exists($page, 'isPermalink') ? $page->isPermalink() : null,
                    'owner' => is_object($owner) && method_exists($owner, 'getHandle') ? $owner->getHandle() : null,
                ]);
                file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
                if ($name === 'page/head') {
                    header('X-Idno-Test-Page-Head: 1', false);
                }
            }, -1000);
        }
    }
}
