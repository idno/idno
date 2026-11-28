<?php

namespace Idno\Core {

    /**
     * Translate via GetText.
     */
    class GetTextTranslation extends Translation
    {

        /**
         * The domain of core's own strings: the catalogue of a language is
         * languages/<locale>/LC_MESSAGES/idno.mo, made from languages/source/idno.pot.
         */
        const CORE_DOMAIN = 'idno';

        /**
         * The domain core's strings had while the project was called Known. A catalogue compiled
         * under this name (known.mo) is still read, after the one named for CORE_DOMAIN.
         */
        const EARLIER_CORE_DOMAIN = 'known';

        private $domain;
        private $canProvide;

        /**
         * Create a GetText translation.
         *
         * @param  string $domain 'idno' for core, 'mypluginname' for plugins
         * @param  string $path   Full path to where to find the translations.
         * @throws \RuntimeException
         */
        public function __construct(
            $domain = self::CORE_DOMAIN,
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
                throw new \Idno\Exceptions\TechnicalException('You must specify a language domain: "idno" for core translations, or "yourpluginname" for a plugin');
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
         * The translations of core's own strings, in the order they are to be registered.
         *
         * The first reads the catalogue of the core domain. The second is for catalogues made before
         * the domain was renamed: it reads one compiled as known.mo, which Language::getTranslation()
         * comes to only for a string the first has no translation of. Where there is no such
         * catalogue it finds nothing and every string passes through it unchanged.
         *
         * @param  string $path Where the catalogues are; the site's languages folder when empty.
         * @return GetTextTranslation[]
         */
        public static function forCore($path = '')
        {
            return [
                new self(self::CORE_DOMAIN, $path),
                new self(self::EARLIER_CORE_DOMAIN, $path),
            ];
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
