<?php

    /**
     * Service discovery (via webfinger) class
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    class Webfinger extends \Idno\Common\Component
    {

        function init()
        {
        }

        /**
         * WebFinger is served by `Idno\Http\Controller\WebfingerController`. Its registration stays here, where it was, because this component is built
         * AFTER the plugins: a plugin's pattern registered before it still matches first, and a `hijackRoute()` of the same pattern made before it is
         * still overwritten by it, as the legacy router's rules have it (see \Idno\Legacy\ControllerRoute).
         */
        function registerpages()
        {
            \Idno\Core\Idno::site()->routes()->addRoute('/\.well\-known/webfinger/?', \Idno\Legacy\ControllerRoute::class);
        }

    }

}

