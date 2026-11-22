<?php

/**
  The main Bonita template class.

  Handles templating.

  @package    Bonita
  @subpackage Templating
 */

namespace Idno\Core\Bonita {

    class Templates
    {

        public $templateType = 'default';  // Which template are we using?
        public $fallbackToDefault = true;  // Fallback to
        public $vars = array();     // Template variables

        /**
         * Test hook: when set (only in the test environment, by Tests\Fixtures\TemplateTrace), called as
         * ($templateType, $templateName, $path) for each draw, $path being the file the draw found or '-'.
         *
         * @var callable|null
         */
        public static $trace = null;

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
                    $this->$var = $value;
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
            $templateName = preg_replace('/^_[A-Z0-9\/]+/i', '', $templateName);

            // Reject template names with path traversal sequences or invalid characters
            if (strpos($templateName, '..') !== false) {
                if ($returnBlank) {
                    return '';
                }
                return false;
            }
            // Only allow alphanumeric characters, forward slashes, hyphens, and underscores
            if (!preg_match('/^[a-zA-Z0-9\/_-]+$/', $templateName)) {
                if ($returnBlank) {
                    return '';
                }
                return false;
            }

            if (!empty($templateName)) {

                // Add the Bonita base path to our additional paths list
                $paths = \Idno\Core\Bonita\Main::getPaths();

                // Add template types to an array; ensure we revert to default
                $templateTypes = array($this->getTemplateType());
                if ($this->fallbackToDefault) {
                    if ($this->getTemplateType() != 'default') {
                        $templateTypes[] = 'default';
                    }
                }

                // Cycle through the additional paths and check for the template file
                // - if it exists, break out of the foreach
                foreach ($templateTypes as $templateType) {
                    foreach ($paths as $basepath) {
                        $path = $basepath . '/templates/' . $templateType . '/' . $templateName . '.tpl.php';
                        if (file_exists($path)) {
                            if (self::$trace !== null) {
                                call_user_func(self::$trace, $this->getTemplateType(), $templateName, $path);
                            }
                            // create an anonymous function for scoping
                            $fn = (function ($path, $vars, $t) {
                                // dump the variables into the local scope
                                foreach ($vars as $k => $v) {
                                    ${$k} = $v;
                                }
                                ob_start();
                                include $path;
                                return ob_get_clean();
                            });
                            return $fn($path, $this->vars, $this);
                        }
                    }
                }
            }
            // If we've got here, just return a blank string; the template doesn't exist
            if (self::$trace !== null) {
                call_user_func(self::$trace, $this->getTemplateType(), $templateName, '-');
            }
            if ($returnBlank) {
                return '';
            }
            return false;
        }

        /**
         * Draws a list of PHP objects using a specified list template. Objects
         * must have a template of the form object/classname
         *
         * @param  $items An array of PHP objects
         * @return string
         */
        function drawList($items, $style = 'stream')
        {
            if (is_array($items) && !empty($items) && !empty($style)) {
                $t = new \Idno\Core\Bonita\Templates($this);
                $t->items = $items;
                return $t->draw('list/' . $style);
            }
            return '';
        }

        /**
         * Draws a single supplied PHP object. Objects should have a corresponding template
         * of the form object/classname
         *
         * @param  $item PHP object
         * @return string
         */
        function drawObject($object)
        {
            if (is_object($object)) {
                $t = new \Idno\Core\Bonita\Templates($this);
                $t->object = $object;
                if (($result = $t->draw('object/' . get_class($object), false)) !== false) {
                    return $result;
                }
                if ($object instanceof BonDrawable) {
                    return $t->draw('object/default');
                }
            }
            return '';
        }

        /**
         * Takes some text and runs it through the current template's processor.
         * This is in the form of a template at processors/text, or processors/X where X
         * is a custom processor
         *
         * @param  $content   Some content
         * @param  $processor Optionally, the processor you want to use (default: text)
         * @return string Formatted content (or the input content if the processor doesn't exist)
         */
        function process($content, $processor = 'text')
        {
            $t = new \Idno\Core\Bonita\Templates();
            $t->content = $content;
            $t->setTemplateType($this->getTemplateType());
            if (($result = $t->draw('processor/' . $processor, false)) !== false) {
                return $result;
            }
            return $content;
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
            $templateType = preg_replace('/^_[A-Z0-9\/]+/i', '', $templateType);
            if (strpos($templateType, '..') !== false || !preg_match('/^[a-zA-Z0-9\/_-]+$/', $templateType)) {
                return false;
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
            $templateType = preg_replace('/^_[A-Z0-9\/]+/i', '', $templateType);
            if (strpos($templateType, '..') !== false || !preg_match('/^[a-zA-Z0-9\/_-]+$/', $templateType)) {
                return false;
            }
            if (!empty($templateType)) {
                $paths = \Idno\Core\Bonita\Main::getPaths();
                foreach ($paths as $basepath) {
                    $path = $basepath . '/templates/' . $templateType . '/';
                    if (file_exists($path)) {
                        return true;
                    }
                }
            }
            return false;
        }

        /**
         * Detects templates based on the given browser string
         * (defaults, of course, to "default")
         */
        function detectTemplateType()
        {
            $device = \Idno\Core\Bonita\Main::detectDevice();
            return $this->setTemplateType($device);
        }

    }

}
