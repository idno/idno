<?php

/**
  The main Bonita template class.

  Handles templating.

  @package    Bonita
  @subpackage Templating
 */

namespace Idno\Core\Bonita {

    class Templates implements \Idno\Templating\PhpTemplateContext
    {

        public $templateType = 'default';  // Which template are we using?
        public $fallbackToDefault = true;  // Fallback to
        public $vars = array();     // Template variables

        /**
         * Constructor allows copying; we're shunning use of clone here
         * You can also just instantiate Templates with a list of variables
         */

        function __construct($initial = false)
        {
            if ($initial instanceof \Idno\Core\Bonita\Templates) {
                $this->vars = $initial->vars;
                $this->templateType = $initial->templateType;
            } else if (is_array($initial)) {
                $this->vars = $initial;
            }
        }

        /**
         * Magic method to set template variables
         *
         * @param $name  Name of variable to set
         * @param $value Value
         */
        function __set($name, $value)
        {
            if (!empty($name)) {
                $this->vars[$name] = $value;
            }
        }

        /**
         * Magic method to get stored template variable
         *
         * @param  $name Name of variable to retrieve
         * @return mixed Variable value or null on failure
         */
        function __get($name)
        {
            if (array_key_exists($name, $this->vars)) {
                return $this->vars[$name];
            }
            return null;
        }

        /**
         * Chainable function to allow variables to be added as an array.
         *
         * @param  $vars array Variables to add to the template (eg array('name1' => 'value1', 'name2' => 'value2'))
         * @return \Bonita\Templates this template object
         */
        function __($vars)
        {
            if (!empty($vars) && is_array($vars)) {
                foreach ($vars as $var => $value) {
                    // a template variable, whatever its name: never the object's own vars, templateType or fallbackToDefault
                    if (!empty($var)) {
                        $this->vars[$var] = $value;
                    }
                }
            }
            return $this;
        }

        /**
         * Method to draw an actual template element
         *
         * @param  string  $templateName Name of the template element to draw
         * @param  boolean $returnBlank  If true, returns a blank string on failure; otherwise false
         * @return string|false Rendered template element or false, depending on $returnBlank
         */
        function draw($templateName, $returnBlank = true)
        {
            $result = $this->renderer()->draw((string)$templateName, fn () => $this->vars, fn () => $this->getTemplateType(), $this, false, fn () => $this->fallbackToDefault);
            if ($result !== null) {
                return $result;
            }
            // The template doesn't exist (or its name is refused): a blank string
            if ($returnBlank) {
                return '';
            }
            return false;
        }

        /**
         * Draws only what plugins and themes registered for a template name (prepends, extensions and added content),
         * for the `slot()` function of a Twig template
         *
         * @param  string $name
         * @return string
         */
        function drawSlot($name)
        {
            return $this->renderer()->drawSlot((string)$name, fn () => $this->vars, fn () => $this->getTemplateType(), $this, fn () => $this->fallbackToDefault);
        }

        /**
         * A copy of this template whose variables are the given ones
         *
         * @param  array $vars
         * @return static
         */
        function withVars(array $vars): static
        {
            $copy = clone $this;
            $copy->vars = $vars;
            return $copy;
        }

        /**
         * Removes the named template variables (see PhpTemplateContext).
         *
         * @param array $names
         */
        function forgetVariables(array $names): void
        {
            foreach ($names as $name) {
                unset($this->vars[$name]);
            }
        }

        /**
         * The class template files are bound to: this one, so that `$this` in a template reaches what a Bonita
         * template always reached
         *
         * @return string
         */
        function phpTemplateScope(): string
        {
            return self::class;
        }

        /**
         * The renderer that finds and draws template files
         *
         * @return \Idno\Templating\TemplateRenderer
         */
        protected function renderer()
        {
            return \Idno\Core\Idno::container()->get(\Idno\Templating\TemplateRenderer::class);
        }

        /**
         * Draws the shell template
         *
         * @param $echo  If set to true (by default), echoes the page; otherwise returns it
         * @param $shell Optional override of the page shell template to be used
         */
        function drawPage($echo = true, $shell = 'shell')
        {
            if ($echo) {

                $content = $this->draw($shell);
                header('Content-Length: ' . strlen($content));

                // Break long output to avoid an Apache performance bug
                $split_output = str_split($content, 1024);

                foreach ($split_output as $chunk) {
                    echo $chunk;
                }

                exit;
            } else {
                return $this->draw($shell);
            }
        }

        /**
         * Returns the current template type
         *
         * @return string Name of the current template ('default' by default)
         */
        function getTemplateType()
        {
            return $this->templateType;
        }

        /**
         * Sets the current template type
         *
         * @param string $template The name of the template you wish to use
         */
        function setTemplateType($templateType)
        {
            if (!is_string($templateType)) {
                return false;
            }
            $templateType = \Idno\Templating\TemplateName::normaliseType($templateType);
            if ($templateType === null || !\Idno\Templating\TypeChain::selectable($templateType)) {
                return false;
            }
            if (in_array(strtolower($templateType), ['default', 'modern'], true)) {
                // The web page is drawn from `modern` files first anyway (a third-party plugin's), so `modern` is the default type
                $templateType = 'default';
            }
            if ($this->templateTypeExists($templateType)) {
                $this->templateType = $templateType;
                return true;
            }
            return false;
        }

        /**
         * Does the specified template type exist?
         *
         * @param  string Name of the template type
         * @return true|false
         */
        function templateTypeExists($templateType)
        {
            if (!is_string($templateType)) {
                return false;
            }
            $templateType = \Idno\Templating\TemplateName::normaliseType($templateType);
            if ($templateType === null) {
                return false;
            }
            return \Idno\Core\Idno::container()->get(\Idno\Templating\TemplateLocator::class)->typeExists($templateType);
        }

    }

}
