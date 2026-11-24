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


        // What plugins and themes extended, prepended and replaced, by template name. The properties
        // $extensions, $prepends, $replacements and $rendered_extensions read from it (see __get()).
        private ?\Idno\Templating\SlotRegistry $slots = null;

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
            \Idno\Core\Bonita\Main::siteSecret(\Idno\Core\Idno::site()->config()->site_secret);

            $this->purifier = new Purifier();

            return parent::__construct($template);
        }

        /**
         * A copy has registrations of its own: what is registered on it does not reach the original
         */
        function __clone()
        {
            $this->slots = $this->slots === null ? null : clone $this->slots;
        }

        /**
         * The registrations are read through the properties third-party code has always read
         * (extensions, prepends, replacements, rendered_extensions), as arrays
         */
        function __get($name)
        {
            switch ($name) {
                case 'extensions':
                    return $this->slots()->extensionMap();
                case 'prepends':
                    return $this->slots()->prependMap();
                case 'replacements':
                    return $this->slots()->replacementMap();
                case 'rendered_extensions':
                    return $this->slots()->contentMap();
            }
            return parent::__get($name);
        }

        /**
         * The four registration properties are always set, as the arrays they were
         */
        function __isset($name)
        {
            return in_array($name, ['extensions', 'prepends', 'replacements', 'rendered_extensions'], true);
        }

        /**
         * The registrations, made when first needed (a subclass need not call this class's constructor)
         *
         * @return \Idno\Templating\SlotRegistry
         */
        private function slots()
        {
            return $this->slots ??= new \Idno\Templating\SlotRegistry();
        }

        /**
         * Makes this template keep its registrations in the given registry (the site's template uses the container's)
         *
         * @param \Idno\Templating\SlotRegistry $slots
         */
        function useSlots(\Idno\Templating\SlotRegistry $slots)
        {
            $this->slots = $slots;
        }

        protected function renderer()
        {
            return parent::renderer()->withSlots($this->slots());
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
            $result = (string)$this->renderer()->draw((string)$templateName, fn () => $this->vars, fn () => $this->getTemplateType(), $this, true, fn () => $this->fallbackToDefault, $replacements == true);

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
            $shell = $this->shellForPage($shell);

            // Get messages and flush session
            $this->messages = \Idno\Core\Idno::site()->session()->getAndFlushMessages();

            // End session BEFORE we output any data
            session_write_close();

            $this->sendShellHeaders();

            return parent::drawPage($echo, $shell);

        }

        /**
         * Draws the page in its shell as drawPage() does, but with none of its side effects: the session's messages are
         * neither shown nor used up, the session is left open and no header is sent. For a caller that makes a Response
         * of its own (a controller's 403 page).
         */
        function drawPageWithoutSideEffects($echo = true, $shell = 'shell')
        {
            $shell = $this->shellForPage($shell);
            $this->messages = [];

            return parent::drawPage($echo, $shell);
        }

        /**
         * The shell a page is drawn in: the override is for web pages: JSON, a feed or any other type has the shell of its own
         * type. JSONP keeps the override, as it always had: its shell is a script that any site can load, and these pages hold
         * the account's and the site's settings, so they are never drawn as one.
         */
        private function shellForPage($shell)
        {
            if (in_array(strtolower((string) $this->getTemplateType()), ['default', 'jsonp'], true)) {
                foreach ($this->url_shell_overrides as $url => $page_shell) {

                    if (strpos(\Idno\Core\Idno::site()->currentPage()->currentUrl(), \Idno\Core\Idno::site()->config()->getDisplayURL() . $url.'/') === 0) {
                        $shell = $page_shell;
                    }

                }
            }

            return $shell;
        }

        /**
         * Sends the headers every page of the default type has: what the `shell/headers` template sent, before no template sent a
         * header. `shell/headers` is still drawn by the shells, as a place a plugin can extend (an extension may call `header()`),
         * and has no file of its own. Another template type has the headers of its own shell.
         */
        function sendShellHeaders()
        {
            if (strtolower((string) $this->getTemplateType()) === 'default') {
                header('Content-type: text/html');
                header('Access-Control-Allow-Origin: *');
            }
            if (strtolower((string) $this->getTemplateType()) === 'markdown') {
                $this->sendMarkdownHeaders();
            }
        }

        /**
         * Whether the page being served is one of the site's or the account's settings pages: its address is under one of
         * the roots that have a shell of their own (`admin`, `account`: addUrlShellOverride()), or its class was registered
         * for a route under `/account/`. These pages hold stored secrets (keys, tokens, passwords) in their forms and, in
         * the older templates another type falls back to, in their text.
         *
         * @return bool
         */
        function isSettingsPage()
        {
            $page = \Idno\Core\Idno::site()->currentPage();
            if (empty($page)) {
                return false;
            }
            if (\Idno\Core\Idno::site()->routes()->isAccountPage($page)) {
                return true;
            }
            $current = (string) $page->currentUrl();
            foreach (array_keys($this->url_shell_overrides) as $root) {
                $prefix = \Idno\Core\Idno::site()->config()->getDisplayURL() . $root;
                if (stripos($current, $prefix) === 0 && in_array((string) substr($current, strlen($prefix), 1), ['', '/', '?', '#', '.'], true)) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Sends the headers of a Markdown document (the `markdown` type's shell sends none itself): the type, `nosniff`, the
         * cross-origin header every page has, and, for an answer that is not an error, the address of the page the
         * document is a form of as its canonical link: the entity's, on its permalink, else this page's without `_t`.
         */
        function sendMarkdownHeaders()
        {
            header('Content-Type: text/markdown; charset=utf-8');
            // (the document is text, whatever a browser might make of its first bytes)
            header('X-Content-Type-Options: nosniff');
            header('Access-Control-Allow-Origin: *');
            if (http_response_code() !== 200) {
                return;
            }
            $entity = $this->vars['markdown_entity'] ?? null;
            $canonical = \Idno\Core\UrlValidator::httpUrl($entity instanceof \Idno\Common\Entity ? $entity->getURL() : $this->getCurrentURLWithoutVar('_t'));
            if ($canonical !== '' && !preg_match('/[<>\s]/', $canonical)) {
                header('Link: <' . $canonical . '>; rel="canonical"', false);
            }
        }

        /**
         * Sends the headers of the simple shell (`shell/simple`: the onboarding pages and registration), which the shell sent
         * itself before no template sent a header: the HTML type, the webmention endpoint twice (the two `rel` values) and
         * the cross-origin header. The page that draws the simple shell calls this first.
         */
        function sendSimpleShellHeaders()
        {
            $url = \Idno\Core\Idno::site()->config()->getDisplayURL();
            header('Content-type: text/html');
            header('Link: <' . $url . 'webmention/>; rel="http://webmention.org/"');
            header('Link: <' . $url . 'webmention/>; rel="webmention"');
            header('Access-Control-Allow-Origin: *');
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
            $variables = array('services'     => \Idno\Core\Idno::site()->syndication()->getServices($content_type),
                                   'content_type' => $content_type,
                'posseLinks'   => $posse_links);
            // Published on the page's template object on purpose: these three keys are part of the page's JSON output, not a side effect of drawing
            foreach ($variables as $name => $value) {
                $this->vars[$name] = $value;
            }
            // and the draw itself is on a copy, so nothing else of it stays on the site's template object. The template
            // replaces `services` with the details it draws (on the object it draws on): that is the value the JSON shows.
            $copy   = clone $this;
            $output = $copy->__($variables)->draw('content/syndication');
            if (array_key_exists('services', $copy->vars)) {
                $this->vars['services'] = $copy->vars['services'];
            }

            return $output;
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
                $variables = array_merge(array('count' => $count, 'offset' => $offset, 'items_per_page' => $items_per_page), $vars);
                // Published on the page's template object on purpose: `items_per_page` is part of the page's JSON output, not a side effect of drawing
                $this->vars['items_per_page'] = $variables['items_per_page'];
                // and the draw itself is on a copy, so nothing else of it stays on the site's template object
                return (clone $this)->__($variables)->draw('shell/pagination');
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
            $this->slots()->extend((string)$templateName, (string)$extensionTemplateName, (bool)$to_front, (string)$templateType);
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
            $this->slots()->prepend((string)$templateName, (string)$prependTemplateName, (bool)$to_front);
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
            $this->slots()->replace((string)$templateName, (string)$replacementTemplateName, (string)$templateType);
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
            $this->slots()->extendWithContent((string)$templateName, (string)$content);
        }

        /**
         * Sets the template type based on various environmental factors
         */
        function autodetectTemplateType()
        {
            if ($page = site()->currentPage()) {
                $template = $page->getInput('_t');
                if (!is_string($template)) {
                    $template = '';
                }
                $template = \Idno\Templating\TypeChain::requested($template);
                if (!empty($template)) {
                    // A request never selects a mail type (the mail sender does)
                    if (\Idno\Templating\TypeChain::requestable($template)) {
                        site()->template()->setTemplateType($template);
                    }
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
