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
         * Draws a template together with everything that was registered for it.
         *
         * The result is put together in this order:
         *
         * 1. every template registered with prependTemplate(), in their order;
         * 2. what was registered for every template type ('*'), and then what was
         *    registered for the current template type: the replacement registered with
         *    replaceTemplate(), if there is one, followed by the templates registered
         *    with extendTemplate(), in their order;
         * 3. the template itself, unless a replacement was drawn;
         * 4. content added with extendTemplateWithContent().
         *
         * So the output of an extension comes BEFORE the output of the template it
         * extends, and extensions are drawn whether or not the template is replaced.
         * Every piece is drawn with the same template variables.
         *
         * @param  string $templateName
         * @param  bool   $returnBlank  Should we return a blank string if nothing was drawn? (Defaults to true; otherwise false is returned)
         * @param  bool   $replacements Should replacements registered with replaceTemplate() be drawn? (Defaults to true.) With false
         *                              the template itself is drawn, which is how a replacement draws the template it replaces.
         * @return false|string
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

            // End session BEFORE we output any data (an API request's is not stored)
            \Idno\Core\Idno::site()->session()->finishEarly();

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
         * $template->draw('plugin/atemplate') are part of the results of
         * $template->draw('core/atemplate'), placed BEFORE the output of
         * "core/atemplate" itself (see draw() for the whole order).
         *
         * The extensions of a template are drawn in the order in which they were
         * added. They are still drawn when the template has been replaced with
         * replaceTemplate().
         *
         * @param string $templateName          The template to extend
         * @param string $extensionTemplateName The template to draw with it
         * @param bool   $to_front              If set, the extension is put ahead of the extensions already added for the same
         *                                      template and template type, so it is drawn before them (and still before the template)
         * @param string $templateType          The template type the extension is drawn for; '*' (the default) is every type.
         *                                      Extensions for '*' are drawn before those for the current type.
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
         * could be prepended to "core/atemplate"; if this is the case, the results of
         * $template->draw('plugin/atemplate') are placed at the very start of the
         * results of $template->draw('core/atemplate'): before any replacement, before
         * every extension added with extendTemplate() and before the output of
         * "core/atemplate" itself (see draw() for the whole order).
         *
         * Prepended templates are drawn in the order in which they were added, for
         * every template type, and are still drawn when the template has been replaced.
         *
         * @param string $templateName        The template to prepend to
         * @param string $prependTemplateName The template to draw before it
         * @param bool   $to_front            If set, the template is put ahead of the ones already prepended to the same
         *                                    template, so it is drawn first
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
         * Only the template itself is replaced. Templates registered for it with
         * prependTemplate() and extendTemplate(), and content added with
         * extendTemplateWithContent(), are still drawn, in the same places: the
         * replacement is drawn after the prepended templates and before the extensions
         * of its template type (see draw() for the whole order).
         *
         * A template has one replacement for each template type; registering another
         * for the same type takes the place of the first. If there is one for '*' and
         * one for the current type, both are drawn.
         *
         * @param string $templateName            The template to replace
         * @param string $replacementTemplateName The template to draw instead
         * @param string $templateType            The template type the replacement is drawn for; '*' (the default) is every type
         */
        function replaceTemplate($templateName, $replacementTemplateName, $templateType = '*')
        {
            if (empty($this->replacements[$templateName][$templateType])) {
                $this->replacements[$templateName][$templateType] = array();
            }
            $this->replacements[$templateName][$templateType] = $replacementTemplateName;
        }

        /**
         * Extends a given template with pre-rendered content, for every template type. The content is
         * drawn last: after the prepended templates, the extensions and the template itself (or its
         * replacement). Content added by several calls is drawn in the order in which it was added.
         *
         * @param string $templateName The template to add the content to
         * @param string $content      The content, printed as it is
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
