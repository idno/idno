<?php

namespace Idno\Core {

    use Idno\Common\Component;
    use Idno\Storage\QuoteNormaliser;
    use Idno\Translation\LocaleNegotiator;

    class Language extends Component
    {

        /**
         * Language associated array of translation objects.
         *
         * @var type
         */
        private $translations = [];

        /**
         * Every translation that was registered, whatever its language (`$translations` holds those that can provide the current one),
         * so that work can be done in another language (see inLocale()).
         *
         * @var Translation[]
         */
        private $registered = [];

        /**
         * Current language
         *
         * @var type
         */
        private $language;

        /**
         * Construct a language object
         *
         * @param type $language
         */
        public function __construct($language = null)
        {
            // An explicit locale wins; then the logged-in user's own (a `language` attribute, which nothing
            // in Idno sets today); then the browser's, or on the console the environment's.
            if (empty($language)) {
                $session = \Idno\Core\Idno::site()->session();
                if (!empty($session)) {
                    if ($user = $session->currentUser()) {
                        if (!empty($user->language) && is_string($user->language)) {
                            $language = $user->language;
                        }
                    }
                }
            }

            if (empty($language)) {
                $language = self::detectBrowserLanguage();
            }

            // The value can come from a request header: only a locale identifier is used, because it builds a path.
            $this->language = LocaleNegotiator::normalise((string) $language, 'en_US');

            // The process locale is left alone: translation reads its own catalogues, not the C library's.
            parent::__construct();
        }

        /**
         * Magic method to get stored language variable
         */
        function __get($string)
        {
            return $this->get($string);
        }

        /**
         * Return a translated string, substituting variables in subs in the format of sprintf.
         *
         * @param  type  $string String to translate
         * @param  array $subs   List of substitution variables to be used in the translated string
         * @return string
         */
        public function _($string, array $subs = [])
        {
            return vsprintf($this->get($string), $subs);
        }

        /**
         * Return an ESCAPED translated string, substituting variables in subs in the format of sprintf.
         *
         * @param  type  $string String to translate
         * @param  array $subs   List of substitution variables to be used in the translated string
         * @return string
         */
        public function esc_($string, array $subs = [])
        {
            return htmlentities($this->_($string, $subs), ENT_QUOTES, 'UTF-8');
        }

        /**
         * Register a translation.
         * Register translation strings. It is safe to provide Translation objects for multiple languages, only translations for
         * $this->getLanguage() will be loaded.
         *
         * @param \Idno\Core\Translation $translation
         */
        public function register(Translation $translation)
        {
            $this->registered[] = $translation;
            if ($translation->canProvide($this->getLanguage())) {
                //$this->addTranslations($translation->getStrings());
                $this->translations[] = $translation;
            }
        }

        /**
         * Runs a piece of work with another language and puts the current one back afterwards, also when the work throws. For what is
         * written for somebody else than whoever is asking: a mail is in the site's language, not in the language of the request that caused it.
         *
         * @param  string   $locale
         * @param  callable $work
         * @return mixed What the work returned
         */
        public function inLocale($locale, callable $work)
        {
            $locale = LocaleNegotiator::normalise((string) $locale, 'en_US');
            if ($locale === $this->language) {
                return $work();
            }
            $saved              = [$this->language, $this->translations];
            $this->language     = $locale;
            $this->translations = [];
            foreach ($this->registered as $translation) {
                if ($translation->canProvide($locale)) {
                    $this->translations[] = $translation;
                }
            }
            try {
                return $work();
            } finally {
                [$this->language, $this->translations] = $saved;
                // A catalogue remembers the language it was last asked about: ask it about the current one again
                foreach ($this->translations as $translation) {
                    $translation->canProvide($this->language);
                }
            }
        }

        /**
         * Shortcut for getTranslation.
         *
         * @param  $string
         * @param  bool|true $failover
         * @return bool|string
         */
        function get($string, $failover = true)
        {
            return $this->getTranslation($string, $failover);
        }

        /**
         * Retrieves a translation for a given string. If $failover is true (as set by default), the function will
         * return the original string if no translation exists. Otherwise it will return false.
         *
         * @param  $string
         * @param  bool|true $failover
         * @return string|bool
         */
        function getTranslation($string, $failover = true)
        {

            // Look through translation objects
            foreach ($this->translations as $translation) {
                $value = $translation->getString($string);
                if (!empty($value) && ($value != $string)) {
                    return $value;
                }
            }

            // If we're in lang_debug mode, lets flag untranslated strings
            if (!empty(\Idno\Core\Idno::site()->config()->lang_debug)) {
                \Idno\Core\Idno::site()->events()->triggerEvent(
                    'language/translation/missing-string', [
                    'string' => $string,
                    'language' => $this->language
                    ]
                );
            }

            if ($failover) {
                return $string;
            }

            return false;
        }

        /**
         * Return the current language code for this object.
         */
        public function getLanguage()
        {
            return $this->language;
        }

        /**
         * Replace curly quotes with uncurly quotes
         *
         * @param  $string
         * @return mixed
         */
        function uncurlQuotes($string)
        {
            return QuoteNormaliser::normalise((string) $string);
        }

        /**
         * Detect current language from browser string.
         *
         * TODO: Put more logic here, with better fallbacks.
         *
         * @param bool $full if true, the full locale is returned, e.g. en_GB
         */
        public static function detectBrowserLanguage($full = true)
        {
            $lang = '';

            if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) && is_string($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                $lang = LocaleNegotiator::fromAcceptLanguage($_SERVER['HTTP_ACCEPT_LANGUAGE'], '');
            }

            // If running as console app, detect via environment
            if (defined('IDNO_CONSOLE')) {
                $lang = LocaleNegotiator::fromEnvironment(['LANGUAGE' => getenv('LANGUAGE'), 'LANG' => getenv('LANG')], '');
            }

            if (!$full && $lang !== '') {
                $lang = explode('_', $lang)[0];
            }

            return $lang;
        }

    }

}
