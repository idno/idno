<?php

    $buttons = '';
if (!empty($vars['services'])) {

    // Preserve service details for API
    $service_details = [];

    foreach($vars['services'] as $service) {

        if (\Idno\Core\Idno::site()->syndication()->has($service)) {

            $service_details[$service] = [];

            $button = $this->draw('content/syndication/' . $service);
            if (empty($button)) {
                $posse_links = $vars['posseLinks'];

                if ($accounts = \Idno\Core\Idno::site()->syndication()->getServiceAccounts($service)) {
                    foreach($accounts as $account) {
                        $disabled = '';
                        if (isset($posse_links[$service])) {
                            $posse_service = $posse_links[$service];
                            if (is_array($posse_service)) {
                                foreach ($posse_service as $posse_account) {
                                    if ($posse_account['account_id'] === $account['username']) {
                                        $disabled = 'disabled';
                                    }
                                }
                            }
                        }
                        $service_details[$service][] = ['username' => $account['username'], 'name' => $account['name']];

                        // give plugins a chance to pre-select a service (e.g. if replying to a tweet, pre-select twitter)
                        $preselect = \Idno\Core\Idno::site()->events()->triggerEvent(
                            'syndication/selected/' . $service, [
                            'service'       => $service,
                            'username'      => $account['username'],
                            'reply-to'      => \Idno\Core\Idno::site()->currentPage()->getInput('share_url'),
                            'syndicated-to' => \Idno\Core\Idno::site()->currentPage()->getInput('syndicatedto'),
                            ], false
                        );

                        $button .= $this->__(
                            [
                            'service'  => $service,
                            'disabled' => $disabled,
                            'username' => $account['username'],
                            'name'     => $account['name'],
                            'details'  => $account,
                            'selected' => $preselect,
                            ]
                        )->draw('content/syndication/account');
                    }
                } else {
                    $disabled = array_key_exists($service, $posse_links) ? 'disabled' : '';
                    $button = $this->__(
                        array('service' => $service, 'disabled' => $disabled, 'selected' => \Idno\Core\Idno::site()->events()->triggerEvent(
                            'syndication/selected/' . $service, [
                            'service' => $service,
                            //'username' => $account['username'],
                            'reply-to' => \Idno\Core\Idno::site()->currentPage()->getInput('share_url')
                            ], false
                        ))
                    )->draw('content/syndication/button');
                }
            }
            $buttons .= $button;

        }

    }

    // Since template vars aren't scoped, reset vars to avoid them appearing in API views
    unset($this->vars['service']);
    unset($this->vars['username']);
    unset($this->vars['name']);
    unset($this->vars['details']);
    unset($this->vars['selected']);
    unset($this->vars['disabled']);

    // Output service details for API
    $this->vars['services'] = $service_details;
}
    $buttons .= $this->draw('content/syndication/buttons');
if (!empty($buttons)) {
    echo '<p class="syndication">' . $buttons . '</p>';
}
