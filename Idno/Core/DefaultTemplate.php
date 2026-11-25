<?php

    /**
     * Template management class
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    use Idno\Entities\User;

    class DefaultTemplate extends \Idno\Core\Bonita\Templates
        implements Template
    {
        // Require sample texts
        use Templating\SampleText;

        // Utility URLs
        use Templating\Urls;

        // Parsing strings
        use Templating\Parsing;

        // Formatting functions
        use Templating\Formatting;

        // Template variables
        use Templating\Variables;

        // Data variables
        use Templating\Data;

        // Class manipulations stuff
        use Templating\Classes;


        // We'll keep track of extensions to templates here
        public $extensions = array();

        // We'll keep track of prepended templates here
        public $prepends = array();

        // We'll keep track of replaced templates here
        public $replacements = array();

        // We can also extend templates with HTML or other content
        public $rendered_extensions = array();

        // Keep track of data attributes to add to objects of certain types
        public $object_data = [];

        // Keep track of the HTML purifier
        public $purifier = false;

        // Override the shell for specific url roots
        public $url_shell_overrides = [];


        /**
         * On construction, detect the template type
         */
        function __construct($template = false)
        {
            if (!($template instanceof Template)) {
                $this->detectTemplateType();
            }

            \Idno\Core\Bonita\Main::siteSecret(\Idno\Core\Idno::site()->config()->site_secret);

            $this->purifier = new Purifier();

            return parent::__construct($template);
        }

        /**
         * Override a page shell based on the page root.
         *
         * @param type $path_root Url base, e.g. 'settings'
         * @param type $shell     The shell, e.g. 'settings-shell'
         */
        public function addUrlShellOverride($path_root, $shell)
        {
            $this->url_shell_overrides[trim($path_root, ' /')] =  $shell;
        }

        /**
         * Extension-aware version of the template drawing function
         *
         * @param  string $templateName
         * @param  bool   $returnBlank  Should we return a blank string if the template doesn't exist? (Defaults to true)
         * @param  book   $replacements Should we honor template replacements? (Defaults to true)
         * @return \Idno\Core\Bonita\false|string
         */
        function draw($templateName, $returnBlank = true, $replacements = true)
        {
            $result = '';
            if (!empty($this->prepends[$templateName])) {
                foreach ($this->prepends[$templateName] as $template) {
                    $result .= parent::draw($template, $returnBlank);
                }
            }
            $replaced = false;
            foreach (['*', $this->getTemplateType()] as $templateType) {
                if (!empty($this->replacements[$templateName][$templateType]) && $replacements == true) {
                    $result .= parent::draw($this->replacements[$templateName][$templateType], $returnBlank);
                    $replaced = true;
                }
                if (!empty($this->extensions[$templateName][$templateType])) {
                    foreach ($this->extensions[$templateName][$templateType] as $template) {
                        $result .= parent::draw($template, $returnBlank);
                    }
                }
            }
            if (!$replaced) {
                $result .= parent::draw($templateName, $returnBlank);
            }
            if (!empty($this->rendered_extensions[$templateName])) {
                $result .= $this->rendered_extensions[$templateName];
            }

            if ($templateName == 'shell' && !empty(Idno::site()->config()->filter_shell)) {
                if (is_array(Idno::site()->config()->filter_shell)) {
                    foreach (Idno::site()->config()->filter_shell as $search => $replace) {
                        $result = str_replace($search, $replace, $result);
                    }
                }
            }

            if (!empty($result)) {
                return $result;
            }
            if ($returnBlank) {
                return '';
            }

            return false;
        }

        /**
         * Draws the page shell.
         *
         * @param  bool $echo
         * @param  $shell Optional override of the page shell template to be used
         * @return false|string
         */
        function drawPage($echo = true, $shell = 'shell')
        {
            // Detect page, and see if we need to use a different shell
            foreach ($this->url_shell_overrides as $url => $page_shell) {

                if (strpos(\Idno\Core\Idno::site()->currentPage()->currentUrl(), \Idno\Core\Idno::site()->config()->getDisplayURL() . $url.'/') === 0) {
                    $shell = $page_shell;
                }

            }

            // Get messages and flush session
            $this->messages = \Idno\Core\Idno::site()->session()->getAndFlushMessages();

            // End session BEFORE we output any data
            session_write_close();

            return parent::drawPage($echo, $shell);

        }

        /**
         * Draw syndication buttons relating to a particular content type
         *
         * @param  $content_type
         * @param  $posse_links  containing Entity::getPosseLinks()
         * @return \Idno\Core\Bonita\false|string
         */
        function drawSyndication($content_type, $posse_links = [])
        {
            return $this->__(
                array('services'     => \Idno\Core\Idno::site()->syndication()->getServices($content_type),
                                   'content_type' => $content_type,
                'posseLinks'   => $posse_links)
            )->draw('content/syndication');
        }

        /**
         * Draws generic pagination suitable for placing somewhere on a page (offset is drawn from the 'offset' input variable)
         *
         * @param  int   $count          Number of items in total (across all pages)
         * @param  int   $items_per_page Number of items you're displaying per page
         * @param  array $vars           Additional template variables
         * @return string
         */
        function drawPagination($count, $items_per_page = null, array $vars = [])
        {
            if (empty($vars)) {
                $vars = [];
            }
            if ($items_per_page == null) {
                $items_per_page = \Idno\Core\Idno::site()->config()->items_per_page;
            }
            $page   = \Idno\Core\Idno::site()->currentPage();
            $offset = (int)$page->getInput('offset');
            if ($offset == 0 && $count < $items_per_page) {
                return '';
            } else {
                return $this->__(array_merge(array('count' => $count, 'offset' => $offset, 'items_per_page' => $items_per_page), $vars))->draw('shell/pagination');
            }

        }

        /**
         * Extend a template with another template. eg, template "plugin/atemplate"
         * could extend "core/atemplate"; if this is the case, the results of
         * $template->draw('plugin/atemplate') will be automatically appended to
         * the end of the results of $template->draw('core/atemplate').
         *
         * @param string $templateName
         * @param string $extensionTemplateName
         * @param bool   $to_front              If set, this will add the template to the beginning of the template queue
         */
        function extendTemplate($templateName, $extensionTemplateName, $to_front = false, $templateType = '*')
        {
            if (empty($this->extensions[$templateName][$templateType])) {
                $this->extensions[$templateName][$templateType] = array();
            }
            if ($to_front) {
                array_unshift($this->extensions[$templateName][$templateType], $extensionTemplateName);
            } else {
                $this->extensions[$templateName][$templateType][] = $extensionTemplateName;
            }
        }

        /**
         * Prepend a template with another template. eg, template "plugin/atemplate"
         * could extend "core/atemplate"; if this is the case, the results of
         * $template->draw('plugin/atemplate') will be automatically prepended to
         * the end of the results of $template->draw('core/atemplate').
         *
         * @param string $templateName
         * @param string $prependTemplateName
         * @param bool   $to_front            If set, this will add the template to the beginning of the template queue
         */
        function prependTemplate($templateName, $prependTemplateName, $to_front = false)
        {
            if (empty($this->prepends[$templateName])) {
                $this->prepends[$templateName] = array();
            }
            if ($to_front) {
                array_unshift($this->prepends[$templateName], $prependTemplateName);
            } else {
                $this->prepends[$templateName][] = $prependTemplateName;
            }
        }

        /**
         * Replace a core template with another template. eg, template "plugin/atemplate"
         * could replace "core/atemplate"; if this is the case, the results of
         * $template->draw('plugin/atemplate') will be displayed instead of
         * $template->draw('core/atemplate'). Usually this isn't required - Idno replaces
         * templates automatically if you create one in your plugin with the same name -
         * but this function enables conditional replacements.
         *
         * @param string $templateName
         * @param string $extensionTemplateName
         */
        function replaceTemplate($templateName, $replacementTemplateName, $templateType = '*')
        {
            if (empty($this->replacements[$templateName][$templateType])) {
                $this->replacements[$templateName][$templateType] = array();
            }
            $this->replacements[$templateName][$templateType] = $replacementTemplateName;
        }

        /**
         * Extends a given template with pre-rendered content. All pre-rendered content will be drawn after
         * template-driven content.
         *
         * @param $templateName
         * @param $content
         */
        function extendTemplateWithContent($templateName, $content)
        {
            if (empty($this->rendered_extensions[$templateName])) {
                $this->rendered_extensions[$templateName] = $content;
            } else {
                $this->rendered_extensions[$templateName] .= $content;
            }
        }

        /**
         * Sets the template type based on various environmental factors
         */
        function autodetectTemplateType()
        {
            // What is drawn depends on the Accept header (a page, JSON, an ActivityPub object),
            // so a cache must keep the answers apart
            if (!headers_sent()) {
                header('Vary: Accept', false);
            }

            if ($page = site()->currentPage()) {
                $template = $page->getInput('_t');
                if (!empty($template)) {
                    site()->template()->setTemplateType($template);
                } else if ($page->isAcceptedContentType('application/json')) {
                    site()->template()->setTemplateType('json');
                } else if ($page->isAcceptedContentType('application/ld+json; profile="https://www.w3.org/ns/activitystreams"') ||
                    $page->isAcceptedContentType('application/activity+json')) {
                    site()->template()->setTemplateType('activitypub');
                }
            }
        }

    }

}
