<?php

namespace IdnoPlugins\Bridgy\Pages {

    use Idno\Common\Page;
    use Idno\Core\Idno;
    use IdnoPlugins\Bridgy\Main;

    /**
     * The pages Bridgy sends a member's browser back to. They are reached by a plain GET, with
     * what Bridgy decided in the address, so they act only for a logged-in member whose address
     * carries the state the site gave them (Main::callbackState()), for a service the plugin
     * knows, and keep only values of the kind Bridgy sends.
     */
    abstract class Callback extends Page
    {

        /// 'enabled' or 'disabled': the page, as it is named in the state
        abstract protected function action();

        /// The status to store for what Bridgy says came of it; null to store nothing
        abstract protected function statusFor($result);

        function getContent()
        {
            $this->gatekeeper();

            $user = Idno::site()->session()->currentUser();
            $service = $this->getInput('service');
            $back = Idno::site()->config()->getDisplayURL() . 'account/bridgy/';

            if (!is_string($service) || !in_array($service, Account::$SERVICES, true)
                || !Main::isCallbackState($user, $this->action(), $service, $this->getInput('state'))
            ) {
                Idno::site()->session()->addErrorMessage(Idno::site()->language()->_("That didn't come from the Bridgy button on this page, or took too long, so nothing was changed. Please try again."));
                $this->forward($back);

                return;
            }

            $status = $this->statusFor($this->getInput('result'));
            if ($status !== null) {
                $address = $this->getInput('user');
                $key = $this->getInput('key');
                $settings = is_array($user->bridgy) ? $user->bridgy : [];
                $settings[$service] = [
                    'status' => $status,
                    // The address of the member's page on Bridgy, asked later for its status
                    'user'   => is_string($address) ? \Idno\Core\UrlValidator::httpUrl($address) : '',
                    'key'    => is_string($key) ? substr($key, 0, 500) : '',
                ];
                $user->bridgy = $settings;
                $user->save();
            }

            $this->forward($back);
        }
    }

}
