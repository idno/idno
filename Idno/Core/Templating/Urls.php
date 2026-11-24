<?php


namespace Idno\Core\Templating {

    use Idno\Core\{ Idno, Webservice };

    trait Urls
    {


        /**
         * Returns a version of the current page URL with the specified variable removed from the address line
         *
         * @param  string $variable_name
         * @return string
         */
        function getCurrentURLWithoutVar($variable_name)
        {
            $components = parse_url($this->getCurrentURL());
            if (!empty($components['query'])) {
                parse_str($components['query'], $url_var_array);
                if (!empty($url_var_array[$variable_name])) {
                    unset($url_var_array[$variable_name]);
                }
            } else {
                $url_var_array = [];
            }
            $components['query'] = http_build_query($url_var_array);
            $url                 = $components['scheme'] . '://' . $components['host'] . (!empty($components['port']) ? ':' . $components['port'] : '') . $components['path'];
            if (!empty($components['query'])) {
                $url .= '?' . $components['query'];
            }

            return $url;
        }

        /**
         * Returns a sanitized version of the current page URL
         *
         * @return string
         */
        function getCurrentURL()
        {
            $base_url = Idno::site()->config()->getDisplayURL();
            $path     = '';
            if ($components = parse_url($base_url)) {
                if ($components['path'] != '/') {
                    $path = substr($components['path'], 1);
                }
            }
            $request_uri = substr($_SERVER['REQUEST_URI'], 1);
            if (!empty($path)) {
                if (substr($request_uri, 0, strlen($path)) == $path) {
                    $request_uri = substr($request_uri, strlen($path));
                }
            }

            return \Idno\Core\Idno::site()->config()->getDisplayURL() . $request_uri;
        }

        /**
         * Returns a version of the current page URL with the specified variable removed from the address line
         *
         * @param  string $variable_name
         * @return string
         */
        function getURLWithoutVar($url, $variable_name)
        {
            if (empty($url)) {
                $url = $this->getCurrentURL();
            }
            $components = parse_url($url);
            $url_var_array = [];
            if (!empty($components['query'])) {
                parse_str($components['query'], $url_var_array);
            }
            if (!empty($url_var_array[$variable_name])) {
                unset($url_var_array[$variable_name]);
            }
            $components['query'] = http_build_query($url_var_array);
            $url                 = $components['scheme'] . '://' . $components['host'] . (!empty($components['port']) ? ':' . $components['port'] : '') . $components['path'];
            if (!empty($components['query'])) {
                $url .= '?' . $components['query'];
            }

            return $url;
        }

        /**
         * Returns a version of the current page URL with the specified URL variable set to the specified value
         *
         * @param  $variable_name
         * @param  $value
         * @return string
         */
        function getCurrentURLWithVar($variable_name, $value)
        {
            $components = parse_url($this->getCurrentURL());
            if (isset($components['query'])) {
                parse_str($components['query'], $url_var_array);
            } else {
                $url_var_array = [];
            }
            $url_var_array[$variable_name] = $value;
            $components['query']           = http_build_query($url_var_array);
            $url                           = $components['scheme'] . '://' . $components['host'] . (!empty($components['port']) ? ':' . $components['port'] : '') . $components['path'];
            if (!empty($components['query'])) {
                $url .= '?' . $components['query'];
            }

            return $url;
        }

        /**
         * Returns a version of the current page URL with the specified variable added to the address line
         *
         * @param  string $variable_name
         * @param  string $variable_value
         * @return string
         */
        function getURLWithVar($variable_name, $variable_value, $url = '')
        {
            if (empty($url)) {
                $url = $this->getCurrentURL();
            }
            $blank_scheme = false;
            if (substr($url, 0, 2) == '//') {
                $blank_scheme = true;
                $url          = 'http:' . $url;
            }
            if ($components = parse_url($url)) {
                if (!empty($components['query'])) {
                    parse_str($components['query'], $url_var_array);
                } else {
                    $components['query'] = array();
                }
                $url_var_array[$variable_name] = $variable_value;
                $components['query']           = http_build_query($url_var_array);
                $url                           = $components['scheme'] . '://' . $components['host'] . (!empty($components['port']) ? ':' . $components['port'] : '') . $components['path'];
                if (!empty($components['query'])) {
                    $url .= '?' . $components['query'];
                }
                if ($blank_scheme) {
                    $url = str_replace($components['scheme'] . ':', '', $url);
                }
            }

            return $url;
        }

        /**
         * Returns the URL variable with name $variable_name if it exists; otherwise null.
         *
         * @param  string $variable_name
         * @return string
         */
        function getCurrentURLVar($variable_name, $url = '')
        {
            $url = $url ?: $this->getCurrentURL();
            $query = parse_url($url, PHP_URL_QUERY);

            if ($query) {
                parse_str($query, $url_var_array);
                return $url_var_array[$variable_name] ?? null;
            }

            return null;
        }

        /**
         * Convert a remote image URL into one addressing the local image proxying service.
         *
         * @param  url                                     $url
         * @param  int Maximum dimensions of proxied image
         * @param  string Transformations. Currently only 'square' is supported.
         * @return URL
         */
        public function getProxiedImageUrl($url, $maxsize = null, $transform = null)
        {

            // Local urls, just relay.
            if (\Idno\Common\Entity::isLocalUUID($url)) {
                return $url;
            }

            // For now, just return $url anyway.
            $proxied_url = $url;
            return $proxied_url;

        }


        /**
         * Return a schema-less version of the given URL
         *
         * @param  $url
         * @param  $match_host If set to true (default), only changes the URI if the host matches the site's host
         * @return mixed
         */
        function makeDisplayURL($url, $match_host = true)
        {
            if (Idno::site()->config()->host != parse_url($url, PHP_URL_HOST) && $match_host == true) {
                return $url;
            }
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if (\Idno\Common\Page::isSSL()) {
                $newuri = 'https:';
            } else {
                $newuri = 'http:';
            }

            return str_replace($scheme . ':', $newuri, $url);
        }


        /**
         * Given a URL, fixes it to have a prefix if it needs one
         *
         * @param  $url
         * @return string
         */
        function fixURL($url)
        {
            // Keep in sync with icon code in templates/default/entity/User/profile/fields.html.twig (and its `classic` copy)
            return (
                substr($url, 0, 7) == 'http://' ||
                substr($url, 0, 8) == 'https://' ||
                substr($url, 0, 1) == '@' ||
                substr($url, 0, 7) == 'mailto:' ||
                substr($url, 0, 4) == 'tel:' ||
                substr($url, 0, 4) == 'sms:' ||
                substr($url, 0, 6) == 'skype:' ||
                substr($url, 0, 5) == 'xmpp:' ||
                substr($url, 0, 4) == 'sip:' ||
                substr($url, 0, 4) == 'ssh:' ||
                substr($url, 0, 8) == 'spotify:' ||
                substr($url, 0, 8) == 'bitcoin:' ||
                substr($url, 0, 9) == 'ethereum:' ||
                substr($url, 0, 4) == 'ssb:' ||
                substr($url, 0, 9) == 'facetime:'
            )
                ? $url
                : 'http://' . $url;
        }

        /**
         * Checks the current URL for `tag/` and passes this down.
         *
         * @return string
         */
        function getTag()
        {
            $classes = \Idno\Core\Idno::site()->template()->getBodyClasses();
            preg_match("/page-tag-([\w_]+)/i", $classes, $matches);
            if (!empty($matches) && !empty($matches[1])) {
                return $matches[1];
            }
            return '';
        }

        function getPageNumber()
        {
            if ($offset = $this->getCurrentURLVar('offset')) {

            }
            return 0;
        }
    }
}
