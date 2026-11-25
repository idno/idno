<?php


namespace Idno\Core\Templating {

    trait Classes
    {

        /**
         * Retrieves a set of contextual body classes suitable for including in a shell template
         *
         * @return string
         */
        function getBodyClasses()
        {
            $classes = '';
            $classes .= (str_replace('\\', '_', strtolower(\Idno\Core\Idno::site()->currentPage()->pageClassName())));
            if ($path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
                if ($path == '/') {
                    $classes .= ' homepage';
                }
                if ($path = explode('/', $path)) {
                    $page_class = '';
                    foreach ($path as $element) {
                        if (!empty($element)) {
                            if (!empty($page_class)) {
                                $page_class .= '-';
                            }
                            $page_class .= $element;
                            $classes .= ' page-' . $page_class;
                        }
                    }
                }
            }

            if (\Idno\Core\Idno::site()->session()->isLoggedIn()) {
                $classes .= ' logged-in';
            } else {
                $classes .= ' logged-out';
            }

            return $classes;
        }
    }
}