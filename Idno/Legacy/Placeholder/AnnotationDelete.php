<?php

declare(strict_types=1);

namespace Idno\Legacy\Placeholder;

/**
 * What the legacy route table holds for the delete of an annotation: the table is exactly what it was before `Idno\Pages\Annotation\Delete` moved
 * (the same patterns, registered in the same place), with this in place of the class that is replaced. It is the placeholder of ONE page, because it names the
 * controller action that serves it (`ACTION`, or `null` when none does): `Idno\Legacy\LegacyRoutedPages` serves a request the legacy router
 * would have given to this class with that action. It serves nothing of its own: it is no `Page` and has no method, so a request the router dispatches
 * to it (a method the action does not take) is the router's 404, as for `Idno\Legacy\ControllerRoute`.
 */
final class AnnotationDelete
{
    /**
     * @var array{0: class-string, 1: string}|null
     */
    public const ACTION = null;
}
