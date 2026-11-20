<?php

/**
 * Web based installer
 */
class WebInstaller extends \Idno\Core\Installer
{

    private static $installer;

    private $template;
    private $ssl_required = false;

    public function __construct()
    {
        \Idno\Core\Bonita\Main::additionalPath(dirname(__FILE__));
        $this->template = new \Idno\Core\Bonita\Templates(); // Use basic template here
        $this->template->setTemplateType('default');

        parent::__construct();
    }

    /**
     * @deprecated This needs to be moved into the front end js to handle proxies etc
     * @return     boolean
     */
    public function rewriteWorking()
    {
        $subdir = ''; // Idno no longer supports subdirectory installations.

        $host = strtolower($_SERVER['HTTP_HOST']);
        if (!empty(Idno\Common\Page::isSSL())) {
            $schema = 'https://';
        } else {
            $schema = 'http://';
        }

        $curl_handle = curl_init();
        curl_setopt($curl_handle, CURLOPT_URL, $schema . $host . $subdir . '/js/canary.js');
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl_handle, CURLOPT_HEADER, 1);

        $curl_result = curl_exec($curl_handle);
        $curl_status = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);

        if ($curl_status < 200 || $curl_status > 299) {
            return false;
        }

        curl_close($curl_handle);

        return true;
    }

    protected function pageSettings()
    {
        $template = $this->template;
        $ok = true;
        $messages = '';

        $site_title  = \Idno\Core\Input::getInput('site_title');
        $mysql_user  = \Idno\Core\Input::getInput('mysql_user');
        $mysql_host  = \Idno\Core\Input::getInput('mysql_host');
        $mysql_pass  = \Idno\Core\Input::getInput('mysql_pass');
        $mysql_name  = \Idno\Core\Input::getInput('mysql_name');
        $submitted   = \Idno\Core\Input::getInput('database') !== null;
        $refused     = false;
        try {
            $database = self::databaseType((string) \Idno\Core\Input::getInput('database', 'mysql'));
        } catch (\Idno\Exceptions\ConfigurationException $e) {
            $database = 'mysql';
            $refused = true;
            $messages .= '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            $ok = false;
        }
        $sqlite_path = trim((string) \Idno\Core\Input::getInput('sqlite_path', ''));
        $upload_path = \Idno\Core\Input::getInput('upload_path', dirname(dirname(__FILE__)) . '/Uploads/');

        //        if (!WebInstaller::installer()->rewriteWorking()) {
        //            $messages .= '<p>Rewriting appears to be disabled. Usually this means "AllowOverride None" is set in apache2.conf ';
        //            $messages .= 'which prevents Idno\'s .htaccess from doing its thing. We tried to fetch a URL that should redirect ';
        //            $messages .= 'to idno.min.js</p>';
        //            $messages .= '<p>You can usually fix this by setting <code>AllowOverride All</code> in your Apache configuration.</p>';
        //            $messages .= '<p>If you think this is an error, you can continue, but you might have problems later on.</p>';
        //            $ok = false;
        //        }

        $db_params = [];
        if ($database == 'sqlite') {
            $db_ready = $submitted;
            $db_input = ['path' => $sqlite_path];
        } else {
            $db_ready = !$refused && !empty($mysql_name) && !empty($mysql_host);
            $db_input = ['name' => $mysql_name, 'user' => $mysql_user, 'pass' => $mysql_pass, 'host' => $mysql_host];
            if ($submitted && !$db_ready && !$refused) {
                $messages .= '<p>Please give us the name of your MySQL database and the name of the server it is on.</p>';
                $ok = false;
            }
        }
        if ($db_ready) {
            try {
                $db_params = $this->databaseParams($database, $db_input);
            } catch (\Exception $e) {
                $messages .= '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
                $ok = false;
            }
        }

        $upload_path = realpath($upload_path);
        if (!empty($upload_path)) {
            if (substr($upload_path, -1) != '/' && substr($upload_path, -1) != '\\') {
                $upload_path .= '/';
            }

            try {
                $this->checkUploadDirectory($upload_path);
            } catch (\Exception $e) {
                $ok = false;
                $messages .= '<p>' . $e->getMessage() . '</p>';
            }

        } else {
            $ok = false;
            if (!empty($mysql_user)) {
                $messages .= '<p>You need to specify an upload path.</p>';
            }
        }

        if ($ok && !empty($upload_path) && !empty($db_params)) {

            try {
                $this->writeApacheConfig();
            } catch (\Exception $e) {
                $ok = false;
                $messages .= '<p>' . $e->getMessage() . '</p>';
            }

            try {

                $ini_file = $this->buildConfig(
                    array_merge($db_params, ['uploadpath' => $upload_path])
                );

                $this->writeConfig($ini_file);

            } catch (\Exception $ex) {
                $ok = false;

                $template->__(
                    [
                    'title' => 'Save configuration file',
                    'body' => $template->__(
                        [
                        'ini_file' => $ini_file,

                        ]
                    )->draw('pages/write-config'),

                    ]
                )->drawPage();
            }
        }

        if ($ok) {
            if (WebInstaller::installer()->isInstalled()) {
                header('Location: ../begin/register?set_name=' . urlencode($site_title));
                exit;
            }
        }

        $template->__(
            [
            'title' => 'Settings',
            'body' => $template->__(
                [

                'site_title' => $site_title,
                'mysql_user' => $mysql_user,
                'mysql_host' => $mysql_host,
                'mysql_pass' => $mysql_pass,
                'mysql_name' => $mysql_name,
                'database' => $database,
                'sqlite_path' => $sqlite_path !== '' ? $sqlite_path : $this->defaultSqlitePath(),
                'upload_path' => $upload_path,

                'messages' => $messages,

                ]
            )->draw('pages/settings'),

            ]
        )->drawPage();
    }

    public function run()
    {

        // See if we've installed things already
        if ($this->isInstalled()) {
            header('Location: ../'); exit;
        }

        $template = $this->template;

        switch (\Idno\Core\Input::getInput('stage')) {

            case 'settings' :
                $this->pageSettings();
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
