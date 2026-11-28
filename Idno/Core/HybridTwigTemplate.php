<?php

/**
 * Hybrid twig template management class
 *
 * @package    idno
 * @subpackage core
 */

namespace Idno\Core {

    use Twig\Loader\FilesystemLoader;
    use Twig\Environment;

    /**
     * Hybrid twig template management class.
     *
     * This class extends the Idno template to, in addition to supporting the Idno php based templates, to support
     * the more standard twig templates.
     */
    class HybridTwigTemplate extends DefaultTemplate
    {

        private $twig;

        private $loader;

        public function __construct($template = false)
        {

            // Create cache
            $cache = \Idno\Core\Idno::site()->config()->getUploadPath() . 'twig/';
            @mkdir($cache);

            // Set up twig environment
            $this->loader = new FilesystemLoader(\Idno\Core\Bonita\Main::getPaths());
            $this->twig = new Environment(
                $this->loader, [
                'cache' => $cache
                ]
            );

            // Update with the parent
            return parent::__construct($template);
        }

        /**
         * Draws a template together with everything that was registered for it, from
         * Twig templates (.html.twig) where there are any.
         *
         * The Twig templates are drawn in the order DefaultTemplate::draw() uses: the
         * templates registered with prependTemplate(); then, for every template type
         * ('*') and for the current one, the replacement registered with
         * replaceTemplate() followed by the templates registered with extendTemplate();
         * then the template itself, unless a replacement was drawn. So here too an
         * extension's output comes before the output of the template it extends.
         *
         * If any of that gave output, it is the result, and nothing is added to it:
         * no PHP template (.tpl.php) is drawn, and neither is content added with
         * extendTemplateWithContent(). If none of it gave output, the PHP templates
         * are drawn by DefaultTemplate::draw().
         *
         * @param  string $templateName
         * @param  bool   $returnBlank  Should we return a blank string if nothing was drawn? (Defaults to true; otherwise false is returned)
         * @param  bool   $replacements Should replacements registered with replaceTemplate() be drawn? (Defaults to true)
         * @return false|string
         */
        function draw($templateName, $returnBlank = true, $replacements = true)
        {
            $this->loader->setPaths(\Idno\Core\Bonita\Main::getPaths());

            // Add template types to an array; ensure we revert to default
            $templateTypes = array($this->getTemplateType());
            if ($this->fallbackToDefault) {
                if ($this->getTemplateType() != 'default') {
                    $templateTypes[] = 'default';
                }
            }

            $result = '';
            if (!empty($this->prepends[$templateName])) {
                foreach ($this->prepends[$templateName] as $template) {
                    foreach ($templateTypes as $type) {
                        try {
                            $result .= $this->twig->render("templates/{$type}/{$template}.html.twig", $this->vars);
                            break;
                        } catch (\Exception $e) {
                            // Ignore loading errors here
                        }
                    }

                }
            }
            $replaced = false;
            foreach (['*', $this->getTemplateType()] as $templateType) {
                if (!empty($this->replacements[$templateName][$templateType]) && $replacements == true) {
                    foreach ($templateTypes as $type) {
                        try {
                            $result .= $this->twig->render("templates/{$type}/{$this->replacements[$templateName][$templateType]}.html.twig", $this->vars);

                            $replaced = true;

                            break;
                        } catch (\Exception $e) {
                            // Ignore loading errors here
                        }
                    }
                }
                if (!empty($this->extensions[$templateName][$templateType])) {
                    foreach ($this->extensions[$templateName][$templateType] as $template) {
                        foreach ($templateTypes as $type) {
                            try {
                                $result .= $this->twig->render("templates/{$type}/{$template}.html.twig", $this->vars);

                                break;
                            } catch (\Exception $e) {
                                // Ignore loading errors here
                            }
                        }
                    }
                }
            }
            if (!$replaced) {
                foreach ($templateTypes as $type) {
                    try {
                        //$result .= $this->twig->render("templates/{$type}/{$templateName}.html.twig", $this->vars);
                        $result .= $this->twig->render("templates/{$type}/{$templateName}.html.twig", $this->vars);
                        break;
                    } catch (\Exception $e) {
                        // Ignore loading errors here

                    }
                }
            }

            // We have a twig template, return it
            if (!empty($result)) {
                return $result;
            }

            // No twig template, look for a bonita template
            return parent::draw($templateName, $returnBlank, $replacements);
        }
    }

}