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

        // The site's own URL, freshly installed: a plain client that verifies certificates, does not
        // follow a redirect and gives up after ten seconds.
        $client = \Symfony\Component\HttpClient\HttpClient::create(['timeout' => 10, 'max_duration' => 10, 'max_redirects' => 0]);

        return $this->urlAnswers($client, $schema . $host . $subdir . '/js/canary.js');
    }

    protected function pageSettings()
    {
        $template = $this->template;

        // What the request asks for is decided in Installer::checkWebSettings(), which creates
        // nothing for a request that is not a POST and takes no path for an SQLite database.
        $settings = $this->checkWebSettings($_SERVER['REQUEST_METHOD'] ?? '', $_REQUEST);
        $ok          = $settings['ok'];
        $messages    = $settings['messages'];
        $database    = $settings['database'];
        $db_params   = $settings['dbParams'];
        $upload_path = $settings['uploadPath'];
        $site_title  = $settings['values']['site_title'];
        $mysql_user  = $settings['values']['mysql_user'];
        $mysql_host  = $settings['values']['mysql_host'];
        $mysql_pass  = $settings['values']['mysql_pass'];
        $mysql_name  = $settings['values']['mysql_name'];

        //        if (!WebInstaller::installer()->rewriteWorking()) {
        //            $messages .= '<p>Rewriting appears to be disabled. Usually this means "AllowOverride None" is set in apache2.conf ';
        //            $messages .= 'which prevents Idno\'s .htaccess from doing its thing. We tried to fetch a URL that should redirect ';
        //            $messages .= 'to idno.min.js</p>';
        //            $messages .= '<p>You can usually fix this by setting <code>AllowOverride All</code> in your Apache configuration.</p>';
        //            $messages .= '<p>If you think this is an error, you can continue, but you might have problems later on.</p>';
        //            $ok = false;
        //        }

        if ($ok && !empty($upload_path) && !empty($db_params)) {

            // The file's text is built first: a value that cannot be written is refused before anything
            // (.htaccess included) is written.
            $ini_file = '';
            try {
                $ini_file = $this->buildConfig(
                    array_merge($db_params, ['uploadpath' => $upload_path])
                );
            } catch (\Idno\Exceptions\ConfigurationException $e) {
                $ok = false;
                $messages .= '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            }

            if ($ok) {
                try {
                    $this->writeApacheConfig();
                } catch (\Exception $e) {
                    $ok = false;
                    $messages .= '<p>' . $e->getMessage() . '</p>';
                }

                try {

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
                'sqlite_path' => $settings['sqlitePath'],
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
