<?php

/**
 * Tools for Idno services.
 *
 * @package    idno
 * @subpackage core
 */

namespace Idno\Core {

    class Service extends \Idno\Common\Component
    {

        /**
         * Check that a page is being accessed by a local service.
         * This is used to limit access to certain api endpoints to local services (event queue, cron etc).
         *
         * @TODO: Find a cleaner way.
         */
        public static function gatekeeper()
        {
            $service_signature = $_SERVER['HTTP_X_IDNO_SERVICE_SIGNATURE'] ?? $_SERVER['HTTP_X_KNOWN_SERVICE_SIGNATURE'] ?? null;
            if (empty($service_signature)) {
                throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_('Missing X-Idno-Service-Signature, service call is not possible.'));
            }

            if ($service_signature != static::generateToken(\Idno\Core\Idno::site()->currentPage()->currentUrl())) {
                throw new \RuntimeException(\Idno\Core\Idno::site()->language()->_('Sorry, signature doesn\'t match up.'));
            }

            return true;
        }

        /**
         * Generate a token based on the site secret and URL.
         *
         * @param  type $url Endpoint URL you're calling
         * @return string
         * @throws \Idno\Exceptions\ConfigurationException
         * @throws \RuntimeException
         */
        public static function generateToken($url)
        {

            $site_secret = \Idno\Core\Idno::site()->config()->site_secret;
            if (empty($site_secret)) {
                throw new \Idno\Exceptions\ConfigurationException(\Idno\Core\Idno::site()->language()->_('Missing site secret'));
            }

            $url = explode('?', $url)[0];

            // Normalise url for token generation
            $url = str_replace('https://', '', $url);
            $url = str_replace('http://', '', $url);

            if (empty($url)) {
                throw new \Idno\Exceptions\TechnicalException(\Idno\Core\Idno::site()->language()->_('Url not provided to token generation.'));
            }

            return hash_hmac('sha256', $url, $site_secret);
        }

        /**
         * Call a service endpoint
         *
         * @param  type $endpoint
         * @return boolean
         * @throws \RuntimeException
         */
        public static function call($endpoint, $params = [])
        {

            if (empty($endpoint)) {
                throw new \Idno\Exceptions\TechnicalException('No endpoint given');
            }

            if (strpos($endpoint, 'http')===false) { // Handle variation in endpoint call
                $endpoint = \Idno\Core\Idno::site()->config()->getDisplayURL() . ltrim($endpoint, '/');
            }

            \Idno\Core\Idno::site()->logging()->debug("Calling $endpoint");

            $signature = \Idno\Core\Service::generateToken($endpoint);

            if ($result = \Idno\Core\Webservice::get(
                $endpoint, $params, [
                'X-IDNO-SERVICE-SIGNATURE: ' . $signature
                ]
            )
            ) {
                $error = $result['response'];
                $content = json_decode($result['content']);

                if ($error != 200) {

                    if (empty($content)) {
                        throw new \Idno\Exceptions\TechnicalException(\Idno\Core\Idno::site()->language()->_('Response from service endpoint was not json'));
                    }

                    if (!empty($content->exception->message)) {
                        throw new \Idno\Exceptions\TechnicalException($content->exception->message);
                    }

                } else {

                    // Response is ok
                    return $content;
                }

            } else {
                throw new \Idno\Exceptions\TechnicalException(\Idno\Core\Idno::site()->language()->_('No result from endpoint.'));
            }
             return false;

        }

        /**
         * Returns true if given system call is available (i.e. not in disable_functions).
         *
         * @param  type $func
         * @return boolean
         */
        public static function isFunctionAvailable($func)
        {
            if (!is_callable($func)) {
                return false;
            }

            // https://stackoverflow.com/questions/4033841/how-to-test-if-php-system-function-is-allowed-and-not-turned-off-for-security
            // is_callable does not check disabled functions.
            $disabled = ini_get('disable_functions');
            if ($disabled) {
                $disabled = explode(',', $disabled);
                $disabled = array_map('trim', $disabled);
                return !in_array($func, $disabled);
            }
            return true;
        }

    }

}