<?php

namespace Idno\Core {

    /**
     * Work that is done after the visitor has their answer, in the same process, when the site's queue is not
     * asynchronous (with an asynchronous queue the work is queued for the worker instead).
     */
    class AfterResponse
    {

        /**
         * Answers the page's request with a redirect to $location at once, closes the connection, runs $work
         * with no time limit, and ends the process (so the page's own forwarding does not run again).
         *
         * @param \Idno\Common\Page $page
         * @param string            $location
         * @param callable          $work
         */
        static function run(\Idno\Common\Page $page, string $location, callable $work): never
        {
            if ($page instanceof \Idno\Legacy\ControllerPage) {
                // A controller answers once, with a Response: the work runs when the kernel terminates, after it has been sent.
                $page->context()->afterResponse(\Closure::fromCallable($work));
                $page->forward($location);
                // (a page that cannot forward, an XHR POST, is ended by the forward with an empty answer: the work still runs behind it)
                throw new \Idno\Http\Page\PageRedirect($location, '', 200);
            }
            \Idno\Core\Idno::site()->session()->finishEarly();
            $page->forward($location, false);

            ignore_user_abort(true);    // This is dangerous, but the work must continue

            $length = ob_get_length();
            header('Connection: close');
            header('Content-length: ' . (string)($length === false ? 0 : $length));

            @ob_end_flush();            // Return output to the browser
            @ob_end_clean();
            @flush();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            set_time_limit(0);          // Eliminate the time limit: this could take a while

            try {
                $work();
            } catch (\Throwable $e) {
                // The work reports its own failures (by mail); this is what is left
                \Idno\Core\Idno::site()->logging()->error('Work after the response failed: ' . get_class($e));
            }

            exit;
        }

    }

}
