<?php

namespace Idno\Core {

    use Idno\Translation\CatalogueLoader;

    /**
     * Translate from gettext catalogues (`<path>/<locale>/LC_MESSAGES/<domain>.po`, else `.mo`).
     *
     * The files are read by Idno itself: the gettext extension is not needed and is never called. A
     * catalogue is read the first time a string is looked up in it, and only for the current locale.
     */
    class GetTextTranslation extends Translation
    {

        private $domain;
        private $path;
        private $locale  = null;
        private $strings = null;

        /**
         * Create a catalogue translation.
         *
         * @param  type $domain 'idno' for core, 'mypluginname' for plugins
         * @param  type $path   Full path to where to find the translations.
         * @throws \RuntimeException
         */
        public function __construct(
            $domain = 'idno',
            $path = ''
        )
        {
            // We can't provide translations with no specified translation domain.
            if (empty($domain)) {
                throw new \RuntimeException('You must specify a language domain: "idno" for core translations, or "yourpluginname", for a plugin');
            }

            // If the path for translations is empty, set a standard base path
            if (empty($path)) {
                $path = \Idno\Core\Idno::site()->config()->getPath() . '/languages/';
            }

            // Normalize path
            $this->path = rtrim($path, '/') . '/';

            // Normalize domain
            $this->domain = preg_replace("/[^a-zA-Z0-9\-\_]/", "", $domain);
        }

        /**
         * Whether a catalogue exists for the locale (or for its language: fr for fr_CA).
         * Nothing is read here; the catalogue is read when a string is first looked up.
         *
         * @param string $language
         * @return bool
         */
        public function canProvide($language)
        {
            if (!is_string($language) || !(new CatalogueLoader())->has($this->path, $this->domain, $language)) {
                return false;
            }
            if ($this->locale !== $language) {
                $this->locale  = $language;
                $this->strings = null;
            }

            return true;
        }

        public function getString($key)
        {
            if ($this->strings === null) {
                $logger        = \Idno\Core\Idno::site()->logging();
                $this->strings = $this->locale === null ? [] : (new CatalogueLoader($logger ?? new \Psr\Log\NullLogger()))->load($this->path, $this->domain, $this->locale);
            }

            return $this->strings[$key] ?? $key;
        }

    }

}
