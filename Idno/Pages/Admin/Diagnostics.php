<?php

    /**
     * User administration
     */

namespace Idno\Pages\Admin {

    class Diagnostics extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->setNoCache();
            $this->adminGatekeeper(); // Admins only

            // Generate basic diagnostics for report
            $basics = $this->getBasics();

            // Create diagnostics report
            if ($this->xhr) {

                $report = static::report($basics, $_SESSION ?? [], $_SERVER);

                // Hook so other plugins and subsystems can add their own data to the report.
                $report = \Idno\Core\Idno::site()->events()->triggerEvent('diagnostics/report', [], $report);

                echo $report;
                exit;
            } else {
                $t        = \Idno\Core\Idno::site()->template();
                $t->body  = $t->__(['basics' => $basics])->draw('admin/diagnostics');
                $t->title = \Idno\Core\Idno::site()->language()->_('Diagnostics');
                $t->drawPage();
            }
        }

        /**
         * The detailed report, as text. It is made to be sent to somebody who helps with a
         * problem, so secrets are left out of it: of the configuration by the rule the site
         * export uses (Config::redactSecrets()), and of the server variables, which hold the
         * environment the site may be configured with and the cookie of the request, by
         * Config::redactServerVariables(). Print neither as it is.
         *
         * @param  array $basics  What getBasics() found
         * @param  array $session The session of the request ($_SESSION)
         * @param  array $server  The server variables of the request ($_SERVER)
         * @return string
         */
        static function report(array $basics, array $session, array $server)
        {
            $report = "Idno Diagnostics: Version " . \Idno\Core\Version::version() . '+' . \Idno\Core\Version::build() . " \nDate: " . date('r') . "\n\n";
            $report .= "*** WARNING: This report contains sensitive information. Be careful about how you transmit it, and to whom. ***\n\n";
            $report .= "Basics:\n-------\n\n";

            if ($basics['status'] != 'Ok') {
                foreach ($basics['report'] as $item => $details) {
                    $report .= "$item : {$details['status']}";
                    if ($details['status'] != 'Ok') {
                        $report .= " - {$details['message']}\n";
                    }
                    $report .= "\n";
                }
            } else {
                $report .= "Basic checks on installation discovered no problems.\n\n";
            }

            $config = \Idno\Core\Idno::site()->config();
            $running = [
                'config'     => \Idno\Core\Config::redactSecrets((array) $config->config),
                'ini_config' => \Idno\Core\Config::redactSecrets((array) $config->ini_config),
            ];

            $report .= "\nRunning config:\n---------------\n" . json_encode($running, JSON_PRETTY_PRINT) . "\n\n";
            $report .= "\$_SESSION:\n----------\n" . json_encode($session, JSON_PRETTY_PRINT) . "\n\n";
            $report .= "\$_SERVER:\n---------\n" . json_encode(\Idno\Core\Config::redactServerVariables($server), JSON_PRETTY_PRINT) . "\n\n";

            return $report;
        }

        /**
         * Quickly collect basic information about
         */
        protected function getBasics()
        {
            $basics = [
                'status' => 'Ok',
                'report' => [],
            ];

            // Check Idno version
            if ($remoteVersion = \Idno\Core\RemoteVersion::build()) {
                if (\Idno\Core\Version::build() < $remoteVersion) {
                    $basics['status']             = 'Failure';
                    $basics['report']['version'] = [
                        'status'  => 'Warning',
                        'message' => 'Your build of Idno is behind the latest version from Github, if you\'re having problems you might try updating to the latest version!<br /> <a href="https://github.com/idno/idno" target="_blank">Update now.</a>'
                    ];

                } else {
                    $basics['report']['version'] = [
                        'status'  => 'Ok'
                    ];
                }
            }

            // Check to see if this is an official package
            if ($revision = \Idno\Core\Version::get('revision')) {
                $basics['report']['package'] = [
                    'status'  => 'Ok'
                ];
            } else {
                $basics['status']             = 'Failure';
                $basics['report']['package'] = [
                    'status'  => 'Warning',
                    'message' => 'You appear to be running directly from a git checkout. While this is fine, you might find a pre-packaged version of Idno more stable.'
                ];
            }

            // Check SSL
            if (!\Idno\Common\Page::isSSL()) {
                $basics['status']             = 'Failure';
                $basics['report']['security'] = [
                    'status'  => 'Warning',
                    'message' => 'Your site doesn\'t seem to be loaded over HTTPS. We strongly recommend using HTTPS to make your site secure and protect your privacy.'
                ];
            }

            // Check PHP version
            $phpversion = \Idno\Core\Installer::checkPHPVersion();
            if ($phpversion == 'ok') {
                $basics['report']['php-version'] = [
                    'status' => 'Ok'
                ];
            } else if ($phpversion == 'warn') {
                $basics['status']             = 'Failure';
                $basics['report']['php-version'] = [
                    'status'  => 'Warning',
                    'message' => 'You are running Idno using a very old version of PHP (' . phpversion() . '), which is no longer actively supported. Although Idno will currently still install, some features may not work, so you should upgrade soon. You may need to ask your server administrator to upgrade PHP for you.'
                ];
            } else {
                $basics['report']['php-version'] = [
                    'status'  => 'Failure',
                    'message' => 'You are running PHP version ' . phpversion() . ', which cannot run Idno. You may need to ask your server administrator to upgrade PHP for you.'
                ];
                $basics['status']                = 'Failure';
            }

            // Check installed extensions
            $basics['report']['php-extensions'] = ['status' => 'Ok', 'message' => 'PHP Extension(s): '];
            foreach (\Idno\Core\Installer::requiredModules() as $extension) {
                if (!extension_loaded($extension)) {
                    $basics['report']['php-extensions']['message'] .= "$extension, ";
                    $basics['report']['php-extensions']['status'] = 'Failure';
                    $basics['status']                             = 'Failure';
                }
            }
            $basics['report']['php-extensions']['message'] = trim($basics['report']['php-extensions']['message'], ' ,') . ' missing.';

            // Check for configuration bug
            $configs = \Idno\Core\Idno::site()->db()->getRecords([], [], PHP_INT_MAX, 0, 'config');
            if (count($configs) != 1) {
                $basics['report']['configuration']['message'] = count($configs) . ' Config entries found in database, there should be only one. This is almost certainly not a cause for concern.';
                $basics['report']['configuration']['status'] = 'Warning';
                $basics['status'] = 'Failure';
            }

            // Check upload directory (if set)
            $basics['report']['upload-path'] = ['status' => 'Ok', 'message' => ''];
            $upload_path                     = \Idno\Core\Idno::site()->config()->uploadpath;
            if (!empty($upload_path)) {
                if ($upload_path = realpath($upload_path)) {
                    if (substr($upload_path, -1) != '/' && substr($upload_path, -1) != '\\') {
                        $upload_path .= '/';
                    }
                    if (file_exists($upload_path) && is_dir($upload_path)) {
                        if (!is_readable($upload_path)) {
                            $basics['status'] = 'Failure';
                            $basics['report']['upload-path']['message'] .= 'We can\'t read data from ' . htmlspecialchars($upload_path) . ' - please check permissions and try again.';
                            $basics['report']['upload-path']['status'] = 'Failure';
                        }
                        if (!is_writable($upload_path)) {
                            $basics['status'] = 'Failure';
                            $basics['report']['upload-path']['message'] .= 'We can\'t write data to ' . htmlspecialchars($upload_path) . ' - please check it is writable by your web server user.';
                            $basics['report']['upload-path']['status'] = 'Failure';
                        }
                    } else {
                        $basics['status'] = 'Failure';
                        $basics['report']['upload-path']['message'] .= 'The upload path ' . htmlspecialchars($upload_path) . ' either doesn\'t exist or isn\'t a directory.';
                        $basics['report']['upload-path']['status'] = 'Failure';
                    }
                } else {
                    $basics['status'] = 'Failure';
                    $basics['report']['upload-path']['message'] .= 'The upload path ' . htmlspecialchars($upload_path) . ' either doesn\'t exist or isn\'t a directory.';
                    $basics['report']['upload-path']['status'] = 'Failure';
                }
            } else {
                // We don't have an upload path set, do we need one?
                if (\Idno\Core\Idno::site()->config()->filesystem == 'local') {
                    $basics['status'] = 'Failure';
                    $basics['report']['upload-path']['message'] .= "Your file system is set to 'local' but you have not specified an upload path (uploadpath = ...;) in your config.ini";
                    $basics['report']['upload-path']['status'] = 'Failure';
                }
            }

            return \Idno\Core\Idno::site()->events()->triggerEvent('diagnostics/basics', [], $basics);;
        }

    }
}

