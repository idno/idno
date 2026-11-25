<?php

declare(strict_types=1);

namespace Idno\Http\Page;

/**
 * Carries an \Error thrown by the controller of a site page to the kernel's exception handling. Symfony dispatches no exception
 * event for an \Error (`handle_all_throwables` is off, and stays off: it would change other requests), so PageRequestListener
 * wraps the controller of a site page, and what it throws is this exception, which PageExceptionListener unwraps: what is logged,
 * mailed and drawn is the original error's class, message, file and line.
 */
final class PageError extends \RuntimeException
{
    public function __construct(public readonly \Error $error)
    {
        parent::__construct($error->getMessage(), 0, $error);
    }
}
