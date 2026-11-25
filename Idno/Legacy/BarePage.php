<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Page;

/**
 * A page that has no content: the current page of an answer no page produced (a 404, a refusal, an error page drawn in the
 * shell), which it makes by being constructed (Page::init()). It stands for the home page, whose body class those pages carry.
 */
final class BarePage extends Page
{
    public function pageClassName(): string
    {
        return 'Idno\Pages\Homepage';
    }
}
