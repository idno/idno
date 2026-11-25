<?php

/**
 * Web based installer.
 *
 * The pages only collect what is typed and show what came of it: every check and everything
 * that is written is in \Idno\Core\Installer::install(), which the command line installer
 * uses too. The installer does nothing at all once the site has a configuration file.
 */
class WebInstaller extends \Idno\Core\Installer
{

    private static $installer;

    private $template;
    private $ssl_required = false;

    /// The web server's user saves the file and is the one that reads it; nobody else on the machine needs to
    protected $config_mode = 0640;

    public function __construct($install_path = null)
    {
        \Idno\Core\Bonita\Main::additionalPath(dirname(__FILE__));
        $this->template = new \Idno\Core\Bonita\Templates(); // Use basic template here
        $this->template->setTemplateType('default');

        parent::__construct($install_path);
    }

    /**
     * A value of the submitted form, as it was typed
     */
    private function posted($name)
    {
        return isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : '';
    }

    /**
     * Send the visitor to another address and stop
     */
    protected function redirect($location)
    {
        header('Location: ' . $location);
        exit;
    }

    /**
     * Where the first administrator's account is made
     */
    private function registrationAddress($site_title)
    {
        return '../begin/register' . ($site_title !== '' ? '?set_name=' . rawurlencode($site_title) : '');
    }

    protected function pageSettings()
    {
        $template = $this->template;
        $errors = [];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $input = [];
            foreach (self::FIELDS as $field) {
                $input[$field] = $this->posted($field);
            }
            $input['database'] = 'MySQL';

            $result = $this->install($input);
            $values = $result['values'];
            $errors = $result['errors'];

            if ($result['ok'] && $result['config_written'] && $this->isInstalled()) {
                $this->redirect($this->registrationAddress($values['site_title']));
            }
            if ($result['ok']) {
                // Everything is in order, but the file must be saved by hand
                $template->__(
                    [
                    'title' => 'Save the configuration file',
                    'body' => $template->__(
                        [
                        'ini_file' => $result['config'],
                        'config_path' => $result['config_path'],
                        'htaccess' => $result['htaccess'],
                        'htaccess_path' => $this->install_path . '/.htaccess',
                        'server_user' => self::serverUser(),
                        'site_title' => $values['site_title'],
                        'missing' => false,
                        ]
                    )->draw('pages/write-config'),
                    ]
                )->drawPage();

                return;
            }
        } else {
            $values = $this->normaliseSettings(['mysql_host' => 'localhost', 'upload_path' => $this->defaultUploadPath()])['values'];
        }

        // The password is never sent back to the browser
        unset($values['mysql_pass']);

        $template->__(
            [
            'title' => 'Settings',
            'body' => $template->__(
                [
                'values' => $values,
                'errors' => $errors,
                'server_user' => self::serverUser(),
                'default_upload_path' => $this->defaultUploadPath(),
                ]
            )->draw('pages/settings'),

            ]
        )->drawPage();
    }

    /**
     * The step after the configuration file was saved by hand: on to the first account when
     * the file is there, and otherwise where it should be.
     */
    protected function pageFinish()
    {
        $site_title = trim($this->posted('site_title'));
        if ($this->isInstalled()) {
            $this->redirect($this->registrationAddress($site_title));
        }

        $template = $this->template;
        $template->__(
            [
            'title' => 'Save the configuration file',
            'body' => $template->__(
                [
                'ini_file' => '',
                'config_path' => $this->configPath(),
                'htaccess' => true,
                'htaccess_path' => $this->install_path . '/.htaccess',
                'server_user' => self::serverUser(),
                'site_title' => $site_title,
                'missing' => true,
                ]
            )->draw('pages/write-config'),
            ]
        )->drawPage();
    }

    public function run()
    {
        // What these pages show and take is for the person installing only
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');

        $stage = isset($_GET['stage']) && is_string($_GET['stage']) ? $_GET['stage'] : '';

        // See if we've installed things already
        if ($this->isInstalled()) {
            if ($stage === 'finish') {
                $this->pageFinish();
            }
            $this->redirect('../');
        }

        $template = $this->template;

        switch ($stage) {

            case 'settings' :
                $this->pageSettings();
                break;
            case 'finish' :
                $this->pageFinish();
                break;
            case 'requirements' :
                $template->__(
                    [
                    'title' => 'Requirements',
                    'body' => $template->__(
                        [
                        'ssl-required' => $this->ssl_required
                        ]
                    )->draw('pages/requirements'),

                    ]
                )->drawPage();
                break;

            // Welcome message
            default:
                $template->__(
                    [
                    'body' => $template->draw('pages/begin')
                    ]
                )->drawPage();
        }
    }


    /**
     * Return the current installer
     *
     * @return WebInstaller
     */
    public static function installer()
    {

        if (!empty(self::$installer)) {
            return self::$installer;
        }

        self::$installer = new WebInstaller();

        return self::$installer;
    }
}
