<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Language;
use Idno\Templating\TemplateTranslation;

/**
 * The templates' translation over the legacy language service, fetched when a string is first translated (the Twig
 * environment can be built, by `debug:twig` for one, where the legacy application is not booted).
 */
final class LegacyTemplateTranslation implements TemplateTranslation
{
    /**
     * @param \Closure(): Language $language
     */
    public function __construct(private readonly \Closure $language)
    {
    }

    public function translate(string $text, array $subs = []): string
    {
        return (string) ($this->language)()->_($text, $subs);
    }
}
