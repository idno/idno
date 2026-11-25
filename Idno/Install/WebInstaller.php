<?php

declare(strict_types=1);

namespace Idno\Install;

use Idno\Exceptions\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web installation of a site that has no configuration: check the server, name the site and its
 * domain, choose the database. It keeps nothing on the server between steps (what a step accepted
 * travels forward in hidden fields; the database password is asked last and never printed), and
 * writes nothing until the last step's every check has passed. Then it creates the database file
 * (SQLite) or connects (MySQL, MariaDB), writes configuration/config.ini once (never over a file
 * that is there), makes the setup token that lets this browser, and only it, create the site's
 * administrator, and sends the browser to the site, where the upgrade page creates the tables.
 *
 * Anyone who reaches an uninstalled site can run it, so: it takes no path from the request (the
 * SQLite database is always data/idno.sqlite under the project, uploads always Uploads/); a value
 * that is not a string counts as empty; a form is accepted only with the token of the cookie this
 * installer gave the browser.
 */
final class WebInstaller
{
    public const COOKIE = 'idno_install';

    /**
     * @param string $cacheDir The kernel's cache directory (to say when it is a temporary one)
     */
    public function __construct(
        private readonly InstallPaths $paths,
        private readonly string $cacheDir = '',
        private readonly LoggerInterface $logger = new NullLogger(),
    )
    {
    }

    public function handle(Request $request): Response
    {
        $nonce = base64_encode(random_bytes(18));
        $cookie = $request->cookies->get(self::COOKIE);
        $known = is_string($cookie) && preg_match('/\A[a-f0-9]{64}\z/', $cookie) === 1;
        if (!$known) {
            $cookie = bin2hex(random_bytes(32));
        }
        $token = self::fieldFor($cookie);
        $checks = $this->checks($request);
        $form = new InstallForm($request->request->all());
        $databaseValues = $form->databaseValues();
        if ($form->text('database') === '' && !$this->available($checks)['sqlite']) {
            $databaseValues['database'] = 'mysql';
        }

        $response = null;
        $html = null;
        if ($request->getRealMethod() !== 'POST') {
            $html = InstallPage::checks($checks, $token, $nonce);
        } elseif (!$known || !hash_equals($token, $form->text('token'))) {
            $html = InstallPage::checks($checks, $token, $nonce, '', 'That form was opened too long ago, or in another browser. Please start again from here.');
        } else {
            $rewrite = in_array($form->text('rewrite'), ['ok', 'fail'], true) ? $form->text('rewrite') : '';
            $uploads = in_array($form->text('uploads'), ['refused', 'served'], true) ? $form->text('uploads') : '';
            $step = $form->text('step');
            if (ServerChecks::blocking($checks) || ($step === 'check' && ($rewrite === 'fail' || $uploads === 'served')) || !in_array($step, ['check', 'site', 'database'], true)) {
                $html = InstallPage::checks($checks, $token, $nonce, $rewrite, '', $uploads);
            } elseif ($step === 'check') {
                $html = InstallPage::site(['title' => '', 'domain' => SiteAddress::fromHostHeader((string) $request->headers->get('Host', ''))], [], $token, $nonce);
            } elseif ($step === 'site' || $form->text('back') !== '') {
                $errors = $form->text('back') !== '' ? [] : $form->siteProblems();
                $html = $errors === [] && $form->text('back') === ''
                    ? InstallPage::database($databaseValues, [], $this->available($checks), false, $token, $nonce)
                    : InstallPage::site($form->siteValues(), $errors, $token, $nonce);
            } else {
                $result = $this->save($form, $checks, $request, $cookie);
                if ($result instanceof Response) {
                    $response = $result;
                } else {
                    // A message names a file under the project by its path from there, never the server's own path.
                    $result = array_map(fn (string $message): string => str_replace($this->paths->root() . '/', '', $message), $result);
                    $html = isset($result['title']) || isset($result['domain'])
                        ? InstallPage::site($form->siteValues(), $result, $token, $nonce)
                        : InstallPage::database($databaseValues, array_diff_key($result, ['existing' => '']) + (isset($result['existing']) ? ['use_existing' => $result['existing']] : []), $this->available($checks), isset($result['existing']), $token, $nonce);
                }
            }
        }

        if ($response === null) {
            $response = new Response((string) $html, Response::HTTP_OK, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Security-Policy' => InstallPage::contentSecurityPolicy($nonce),
            ]);
            if ($request->getRealMethod() === 'HEAD') {
                $response->setContent('');
            }
        }
        if (!$known) {
            $response->headers->setCookie(Cookie::create(self::COOKIE, $cookie, 0, '/', null, self::secure($request), true, false, Cookie::SAMESITE_STRICT));
        }
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    /**
     * What a form of the browser holding this cookie carries, so that another site cannot post the
     * installer's forms for it.
     */
    public static function fieldFor(string $cookie): string
    {
        return hash('sha256', 'idno-install-form:' . $cookie);
    }

    /**
     * Whether the browser reached the site over HTTPS: the connection itself, or what the server
     * in front of Idno says (X-Forwarded-Proto), as the site itself decides it.
     */
    public static function secure(Request $request): bool
    {
        return $request->isSecure() || strtolower(trim(explode(',', (string) $request->headers->get('X-Forwarded-Proto', ''))[0])) === 'https';
    }

    /**
     * @return list<CheckResult>
     */
    private function checks(Request $request): array
    {
        $temporary = realpath(sys_get_temp_dir());

        return ServerChecks::run($this->paths, [
            'secure' => self::secure($request),
            'host' => (string) $request->headers->get('Host', ''),
            'basePath' => $request->getBasePath(),
            'cacheInTemp' => $this->cacheDir !== '' && $temporary !== false && str_starts_with($this->cacheDir, $temporary . '/') && !str_starts_with($this->cacheDir, $this->paths->root() . '/'),
        ]);
    }

    /**
     * @param list<CheckResult> $checks
     * @return array{sqlite: bool, mysql: bool}
     */
    private function available(array $checks): array
    {
        return [
            'sqlite' => extension_loaded('pdo_sqlite') && ServerChecks::find($checks, 'data')?->state === CheckResult::OK,
            'mysql' => extension_loaded('pdo_mysql'),
        ];
    }

    /**
     * The last step: every check again, then the database, the configuration and the setup token.
     *
     * @param list<CheckResult> $checks
     * @return Response|array<string, string> The redirect to the site, or what is wrong by field
     *                                        (`existing`: the database holds a site and the form
     *                                        must confirm using it)
     */
    private function save(InstallForm $form, array $checks, Request $request, string $cookie): Response|array
    {
        $errors = $form->siteProblems();
        if ($errors !== []) {
            return $errors;
        }
        $home = new RedirectResponse($request->getBasePath() . '/', Response::HTTP_SEE_OTHER);
        if ($this->paths->existingConfigFiles() !== []) {
            return $home;
        }
        try {
            $type = DatabaseChoice::type($form->text('database'));
        } catch (ConfigurationException $e) {
            return ['database' => $e->getMessage()];
        }
        $available = $this->available($checks);
        if (!$available[$type]) {
            return ['database' => 'That choice is not available on this server. Choose the other one.'];
        }
        $url = SiteAddress::url($form->text('domain'));
        $upload = $this->paths->uploadDir() . '/';
        $input = $type === 'sqlite'
            ? ['path' => '']
            : ['name' => $form->text('mysql_name'), 'user' => $form->text('mysql_user'), 'pass' => $form->raw('mysql_pass'), 'host' => $form->text('mysql_host')];
        if ($type === 'mysql') {
            $missing = [];
            if ($input['name'] === '') {
                $missing['mysql_name'] = 'Enter the name of the database.';
            }
            if ($input['host'] === '') {
                $missing['mysql_host'] = 'Enter the database server. It is almost always localhost.';
            }
            if ($missing !== []) {
                return $missing;
            }
        }

        $config = new ConfigFile($this->paths);
        $choice = new DatabaseChoice($this->paths);
        $fileWasThere = file_exists($this->paths->sqliteFile());
        $cleanUp = function () use ($type, $fileWasThere): void {
            if ($type === 'sqlite' && !$fileWasThere) {
                foreach (['', '-wal', '-shm'] as $suffix) {
                    @unlink($this->paths->sqliteFile() . $suffix);
                }
            }
        };
        try {
            // What will be written is checked before anything is created.
            $config->assertWritable($type === 'sqlite'
                ? ['dbname' => $this->paths->sqliteFile(), 'uploadpath' => $upload, 'url' => $url]
            : ['dbname' => $input['name'], 'dbuser' => $input['user'], 'dbpass' => $input['pass'], 'dbhost' => $input['host'], 'uploadpath' => $upload, 'url' => $url]);
            $params = $choice->params($type, $input);
            $finding = $choice->inspect($params);
            if ($finding->kind === DatabaseFinding::REFUSED) {
                $cleanUp();

                return ['database' => $finding->message];
            }
            $code = new InstallCode($this->paths);
            if ($finding->holdsASite() && !($form->text('use_existing') === '1' && $code->matches($form->raw('install_code')))) {
                // Taking over a site's data, and giving it an address, needs proof of access to the
                // server's files: the code written to a file the web server never serves. Nothing but
                // that file is written until the code is given.
                if (!$code->ensure()) {
                    return ['existing' => 'That database already holds an Idno site, and Idno cannot write the code that shows you can reach this server\'s files: the folder configuration cannot be written to. Restore the site\'s configuration file, or install from the command line: php bin/console idno:install --use-existing'];
                }
                $problems = ['existing' => 'That database already holds an Idno site. To use it, show that you can reach this server\'s files: Idno has written a code to the file ' . InstallCode::relativePath() . ' in Idno\'s folder. Read it on the server (for example, from Idno\'s folder: sudo cat ' . InstallCode::relativePath() . '), type it below, check the site\'s address and tick the box. The code lasts an hour. Nothing in the database will be replaced. To start a new site instead, choose an empty database.'];
                if ($form->text('install_code') !== '' || is_array($request->request->all()['install_code'] ?? null)) {
                    $problems['install_code'] = 'That is not the code in ' . InstallCode::relativePath() . ', or it has expired (then a new one has been written there). Read the file again.';
                }

                return $problems;
            }
            $text = $config->build($params + ['uploadpath' => $upload, 'url' => $url]);
            if (!$config->writeOnce($text)) {
                // Another request wrote the configuration first (a double submit, a second tab): the
                // site is installed, and this request changes nothing.
                return $home;
            }
            if ($finding->holdsASite()) {
                $code->clear();
            }
        } catch (ConfigurationException $e) {
            $cleanUp();

            return [self::fieldOf($e->getMessage(), $type) => $e->getMessage()];
        } catch (\Throwable $e) {
            $cleanUp();
            $this->logger->warning('The installer could not read the chosen database: ' . $e::class . ': ' . substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $e->getMessage()), 0, 500));

            return ['database' => 'Idno could not read that database. Check its settings and try again.'];
        }

        // The upload folder refuses everything itself on Apache, beside the rules of every shipped configuration.
        if (!UploadFolder::protect($this->paths->uploadDir())) {
            $this->logger->warning('The installer could not write Uploads/.htaccess: the web server\'s own configuration must refuse the folder Uploads.');
        }

        // The setup token is the random value this browser already holds (its installer cookie): if
        // this response never reaches the browser (a double click on the button), the browser can
        // still create the administrator, because the setup step accepts that cookie too.
        try {
            (new SetupToken($this->paths))->issue($form->title(), $cookie);
            $home->headers->setCookie(Cookie::create(SetupToken::COOKIE, $cookie, time() + SetupToken::LIFETIME, '/', null, self::secure($request), true, false, Cookie::SAMESITE_LAX));
        } catch (\Throwable $e) {
            // The site is installed; `php bin/console idno:setup:link` opens the administrator step.
            $this->logger->warning('The installer wrote the configuration but not the setup marker: ' . $e::class . '. Run php bin/console idno:setup:link');
        }

        return $home;
    }

    /**
     * The field a refusal belongs to: the one a "setting ..." message names, else the database choice.
     */
    private static function fieldOf(string $message, string $type): string
    {
        if ($type === 'mysql' && preg_match('/the setting (dbname|dbuser|dbpass|dbhost) /', $message, $match) === 1) {
            return ['dbname' => 'mysql_name', 'dbuser' => 'mysql_user', 'dbpass' => 'mysql_pass', 'dbhost' => 'mysql_host'][$match[1]];
        }

        return 'database';
    }
}
