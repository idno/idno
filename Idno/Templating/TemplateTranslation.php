<?php

declare(strict_types=1);

namespace Idno\Templating;

/**
 * The site's translation, for the `t()` function of a Twig template (Idno\Legacy\LegacyTemplateTranslation is over
 * Idno\Core\Language).
 */
interface TemplateTranslation
{
    /**
     * @param array<int, mixed> $subs values for the `%s` placeholders of the translation, as sprintf takes them
     */
    public function translate(string $text, array $subs = []): string;
}
