<?php

namespace IdnoPlugins\Bridgy\Pages {

    /**
     * Where Bridgy sends the member's browser after they asked it to stop listening
     */
    class Disabled extends Callback
    {

        protected function action()
        {
            return 'disabled';
        }

        protected function statusFor($result)
        {
            return $result == 'success' ? 'disabled' : null;
        }
    }

}
