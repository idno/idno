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

                // The same rule of what a secret is as the export archive's (Idno\Portability\SettingsRedactor),
                // applied to a copy: the running configuration is left as it is.
                $config = \Idno\Core\Idno::site()->config();
                $shown  = [
                    'config'     => \Idno\Portability\SettingsRedactor::redact($config->config),
                    'ini_config' => \Idno\Portability\SettingsRedactor::redact($config->ini_config),
                ];

                $report .= "\nRunning config:\n---------------\n" . json_encode($shown, JSON_PRETTY_PRINT) . "\n\n";
                // Both dumps hold secrets (IDNO_DBPASS and the like in the server's variables, a session's own keys)
                $report .= "\$_SESSION:\n----------\n" . json_encode(\Idno\Portability\SettingsRedactor::redact((array)$_SESSION), JSON_PRETTY_PRINT) . "\n\n";
                $report .= "\$_SERVER:\n---------\n" . json_encode(\Idno\Portability\SettingsRedactor::redact($_SERVER), JSON_PRETTY_PRINT) . "\n\n";

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
            if (version_compare(PHP_VERSION, \Idno\Install\ServerChecks::PHP_VERSION, '>=')) {
                $basics['report']['php-version'] = [
                    'status' => 'Ok'
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
            foreach (\Idno\Install\ServerChecks::EXTENSIONS as $extension) {
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

