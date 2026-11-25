<?php

declare(strict_types=1);

namespace Idno\Http\Page;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Gives a controller argument typed PageContext the context of the request.
 */
final class PageContextResolver implements ValueResolverInterface
{
    /**
     * @return iterable<PageContext>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== PageContext::class) {
            return [];
        }

        return [PageContext::of($request)];
    }
}
