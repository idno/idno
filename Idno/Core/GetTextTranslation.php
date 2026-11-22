<?php

namespace Idno\Core {

    /**
     * Translate via GetText.
     */
    class GetTextTranslation extends Translation
    {

        private $domain;
        private $canProvide;

        /**
         * Create a GetText translation.
         *
         * @param  type $domain 'known' for core, 'mypluginname' for plugins
         * @param  type $path   Full path to where to find the translations.
         * @throws \RuntimeException
         */
        public function __construct(
            $domain = 'known',
            $path = ''
        )
        {
            if (!extension_loaded('gettext')) {
                $this->canProvide = false;
                return;
            }

            // If we've got here, gettext is installed and we can provide translations
            $this->canProvide = true;

            // We can't provide translations with no specified translation domain.
            if (empty($domain)) {
                throw new \Idno\Exceptions\TechnicalException('You must specify a language domain: "known" for core translations, or "yourpluginname", for a plugin');
            }

            // If the path for translations is empty, set a standard base path
            if (empty($path)) {
                $path = \Idno\Core\Idno::site()->config()->getPath() . '/languages/';
            }

            // Normalize path
            $path = rtrim($path, '/') . '/';

            // Normalize domain
            $domain = preg_replace("/[^a-zA-Z0-9\-\_\s]/", "", $domain);
            $this->domain = $domain;

            // Set domain
            bindtextdomain($domain, $path);
            bind_textdomain_codeset($domain, 'UTF-8');
        }

        /**
         * Whether this translation provider can provide translations.
         *
         * Note: Unlike the parent class, this does not check the specific
         * $language parameter. GetText's language selection is handled by
         * the system locale, not by this object's state. The $language
         * parameter is accepted for interface compatibility but not used.
         *
         * @param string $language
         * @return bool
         */
        public function canProvide($language)
        {
            return $this->canProvide;
        }

        public function getString($key)
        {
            return $this->canProvide ? dgettext($this->domain, $key) : $key; // Get a specific translation, from this object's domain
        }

    }

}
