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
            $upload = \Idno\Core\Idno::site()->config()->getUploadPath();
            if (str_starts_with((string) $upload, 's3://')) {
                // Not a directory: files on S3 are served by the file store
                $upload = \Idno\Core\Idno::site()->config()->getTempDir();
            }
            $cache = $upload . 'twig/';
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
         * Extension-aware version of the template drawing function
         *
         * @param  string $templateName
         * @param  bool   $returnBlank  Should we return a blank string if the template doesn't exist? (Defaults to true)
         * @param  book   $replacements Should we honor template replacements? (Defaults to true)
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