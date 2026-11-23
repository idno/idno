<?php

declare(strict_types=1);

/**
 * The Twig ports that draw something other than their `.tpl.php` original on purpose, keyed by the project-relative path of the
 * Twig file: the reason (a defect fixed, an escape added), and the test, `Class::method`, that pins the new output. The equivalence
 * test (Tests\Templating\TwigPortEquivalenceTest) fails for a difference that is not listed here, and for an entry whose file
 * drew the same as its original (a stale exception) or whose test does not exist.
 *
 * @return array<string, array{reason: string, test: string}>
 */
return [];
