<?php

namespace Idno\Core {

    /**
     * Work that is done after the visitor has their answer, in the same process, on a site
     * whose queue is not asynchronous (with an asynchronous queue the work is queued for the
     * worker instead).
     */
    class AfterResponse
    {

        /**
         * Runs $work after the answer the page is about to give, wherever the page ends (a
         * page forwards and exits): in a shutdown function, which sends the output the page
         * made with its length and closes the connection first, so that the browser lets
         * go, and lifts the time limit. Nothing here answers or ends the request, so code
         * deep in a page can ask for it. From the command line there is no answer to wait
         * for, and $work runs at once.
         *
         * @param callable $work
         */
        static function later(callable $work)
        {
            if (PHP_SAPI === 'cli') {
                $work();

                return;
            }
            register_shutdown_function(
                static function () use ($work) {
                    ignore_user_abort(true);
                    try {
                        // What the session holds (the messages for the next page) is written before the browser is let go
                        Idno::site()->session()->finishEarly();
                    } catch (\Throwable $e) {
                        // The work is still done
                    }
                    if (!headers_sent() && ($length = ob_get_length()) !== false) {
                        header('Connection: close');
                        header('Content-Length: ' . (string) $length);
                    }
                    while (ob_get_level() > 0) {
                        @ob_end_flush();
                    }
                    @flush();
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    set_time_limit(0);
                    try {
                        $work();
                    } catch (\Throwable $e) {
                        Idno::site()->logging()->error('Work after the response failed: ' . get_class($e));
                    }
                }
            );
        }

    }

}
