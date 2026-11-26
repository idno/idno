<?php

namespace IdnoPlugins\Bridgy\Pages {

    /**
     * Where Bridgy sends the member's browser after they asked it to start listening
     */
    class Enabled extends Callback
    {

        protected function action()
        {
            return 'enabled';
        }

        protected function statusFor($result)
        {
            return $result == 'success' ? 'enabled' : 'disabled';
        }
    }

}
