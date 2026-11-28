<?php

namespace Idno\Core {

    interface Template
    {

        /**
         * Override a page shell based on the page root.
         *
         * @param type $path_root Url base, e.g. 'settings'
         * @param type $shell     The shell, e.g. 'settings-shell'
         */
        public function addUrlShellOverride($path_root, $shell);

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
        function draw($templateName, $returnBlank = true, $replacements = true);

        /**
         * Draws the page shell.
         *
         * @param  bool $echo
         * @param  $shell Optional override of the page shell template to be used
         * @return false|string
         */
        function drawPage($echo = true, $shell = 'shell');

        /**
         * Draw syndication buttons relating to a particular content type
         *
         * @param  $content_type
         * @param  $posse_links  containing Entity::getPosseLinks()
         * @return \Idno\Core\Bonita\false|string
         */
        function drawSyndication($content_type, $posse_links = []);

        /**
         * Draws generic pagination suitable for placing somewhere on a page (offset is drawn from the 'offset' input variable)
         *
         * @param  int   $count          Number of items in total (across all pages)
         * @param  int   $items_per_page Number of items you're displaying per page
         * @param  array $vars           Additional template variables
         * @return string
         */
        function drawPagination($count, $items_per_page = null, array $vars = []);

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
        function extendTemplate($templateName, $extensionTemplateName, $to_front = false, $templateType = '*');

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
        function prependTemplate($templateName, $prependTemplateName, $to_front = false);

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
        function replaceTemplate($templateName, $replacementTemplateName, $templateType = '*');

        /**
         * Extends a given template with pre-rendered content, for every template type. The content is
         * drawn last: after the prepended templates, the extensions and the template itself (or its
         * replacement). Content added by several calls is drawn in the order in which it was added.
         *
         * @param string $templateName The template to add the content to
         * @param string $content      The content, printed as it is
         */
        function extendTemplateWithContent($templateName, $content);

        /**
         * Sets the current template type
         *
         * @param string $template The name of the template you wish to use
         */
        function setTemplateType($templateType);

        /**
         * Sets the template type based on various environmental factors
         */
        function autodetectTemplateType();

    }

}