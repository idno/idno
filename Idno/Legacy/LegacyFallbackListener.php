<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Logging;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Hands a request to the legacy router when Symfony rejects the request itself before a
 * controller was chosen: a BadRequestHttpException, which is what HttpKernel turns
 * SuspiciousOperationException (e.g. a malformed Host header or an invalid
 * X-HTTP-Method-Override) and ConflictingHeadersException into. The legacy front
 * controller served such requests, so they are still served exactly as before. Each one is
 * logged as a warning through the legacy logger (Idno\Core\Logging), so the site's log
 * level and line format apply. The logger is injected as a closure and fetched only when
 * the listener takes over a request: the listener is built for every kernel.exception,
 * and building the logger needs the legacy application (and, in a second kernel, would
 * replace the process's PHP error handler).
 *
 * While the legacy application is not booted (the schema gate is closed) every exception is left
 * to Symfony: there is no legacy router to serve the request.
 *
 * Every other exception is left to Symfony, so that, for example, a broken route
 * definition fails loudly instead of being served as a legacy page. Exceptions thrown by
 * a controller (any request that has a "_controller") are also left to Symfony. Exceptions
 * thrown by legacy pages never get here: they run after the kernel has returned the response.
 */
final class LegacyFallbackListener
{
    /** The longest exception detail written to the log, in bytes. */
    public const MAX_DETAIL_LENGTH = 200;

    /**
     * Both are service closures: the listener is built whenever the firewall removes its own listener from the dispatcher (every request,
     * in debug), and the controller needs the legacy router's dispatcher, which is built only for a request the legacy router serves.
     *
     * @param \Closure(): LegacyController $controller
     * @param \Closure(): LoggerInterface  $logger
     */
    public function __construct(
        #[AutowireServiceClosure(LegacyController::class)]
        private readonly \Closure $controller,
        #[AutowireServiceClosure(Logging::class)]
        private readonly \Closure $logger,
    )
    {
    }

    // Before Symfony's own exception listeners (ErrorListener logs at 0, renders at -128).
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 64)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$event->isMainRequest()
            || !$exception instanceof BadRequestHttpException
            || $event->getRequest()->attributes->has('_controller')
            // While the schema gate is closed the legacy application has not booted: there is no
            // legacy router (nor logger) to hand the request to, so Symfony answers it (a 400).
            || \Idno\Core\Idno::site() === null
        ) {
            return;
        }

        // The message can contain request data (e.g. the Host header): no context array, and
        // the detail is stripped of control characters and bounded.
        $cause = $exception->getPrevious() ?? $exception;
        ($this->logger)()->warning(sprintf(
            'Request rejected by Symfony before routing (%s), served by the legacy router instead: %s',
            (new \ReflectionClass($cause))->getShortName(),
            self::sanitise($exception->getMessage())
        ));

        $event->allowCustomResponseCode();
        $event->setResponse(($this->controller)()->response($event->getRequest()));
    }

    /**
     * Replaces every control character (0x00-0x1F, 0x7F) with "?" and keeps at most
     * MAX_DETAIL_LENGTH bytes.
     */
    public static function sanitise(string $detail): string
    {
        return substr((string) preg_replace('/[\x00-\x1F\x7F]/', '?', $detail), 0, self::MAX_DETAIL_LENGTH);
    }
}
