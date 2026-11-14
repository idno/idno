<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Logging;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 * level and line format apply. The listener is instantiated lazily, when kernel.exception
 * is first dispatched (inside Kernel::handle(), after the legacy boot has built the logger).
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

    public function __construct(private readonly LegacyController $controller, #[Autowire(service: Logging::class)] private readonly LoggerInterface $logger)
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
        ) {
            return;
        }

        // The message can contain request data (e.g. the Host header): no context array, and
        // the detail is stripped of control characters and bounded.
        $cause = $exception->getPrevious() ?? $exception;
        $this->logger->warning(sprintf(
            'Request rejected by Symfony before routing (%s), served by the legacy router instead: %s',
            (new \ReflectionClass($cause))->getShortName(),
            self::sanitise($exception->getMessage())
        ));

        $event->allowCustomResponseCode();
        $event->setResponse($this->controller->response());
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
