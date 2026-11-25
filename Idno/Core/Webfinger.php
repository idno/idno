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
         * WebFinger is served by `Idno\Http\Controller\WebfingerController`; this component registers no page.
         */
        function registerpages()
        {
        }

    }

}

