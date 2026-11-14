<?php

/**
 * The global application() function that legacy console plugins call (see
 * Idno\Common\ConsolePlugin): it returns the console application, which
 * Idno\Console\Application stores in the global $console before it constructs them.
 */

namespace {

    if (!function_exists('application')) {
        function &application()
        {
            global $console;

            return $console;
        }
    }
}
