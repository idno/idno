<?php

declare(strict_types=1);

namespace Idno\Install;

use Idno\Config\BootConfig;
use Idno\Kernel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Between the configuration being written and the site having an administrator, a marker file
 * exists (SetupToken). While it does, and the legacy application is booted (the tables exist), this
 * answers every request before routing:
 *
 *  - the site has an administrator already (an existing database was chosen): the marker is removed
 *    and the request goes on as any other;
 *  - the browser holds the setup token (the cookie the installer gave it, or the one the link of
 *    `idno:setup:link`, `/_setup/<token>`, gives): `/_setup/` is the "Create your account" form, and
 *    posting it creates the administrator, removes the marker, signs the browser in and sends it to
 *    `/admin/welcome/`; a page anywhere else is redirected to `/_setup/`;
 *  - anyone else: 503 and a page that says the site is being set up.
 *
 * With no marker it does nothing but look for the file (one stat a request, no statement): the
 * create-account step does not exist, for any address or method, once the site has an administrator.
 *
 * It is built for the first request event, so it takes nothing that needs the legacy application:
 * the administrator's code is a closure fetched only while the marker exists.
 */
final class SetupGateListener
{
    public const PATH = '/_setup/';
    public const WELCOME = '/admin/welcome/';

    /**
     * @param \Closure(): FirstAdministrator $administrator
     */
    public function __construct(
        #[Autowire(service: 'kernel')]
        private readonly KernelInterface $kernel,
        private readonly SetupToken $setup,
        private readonly BootConfig $config,
        #[AutowireServiceClosure(FirstAdministrator::class)]
        private readonly \Closure $administrator,
    )
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 290)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->kernel instanceof Kernel || !$this->kernel->legacyBooted() || !$this->setup->pending()) {
            return;
        }
        $administrator = ($this->administrator)();
        if ($administrator->administratorExists()) {
            $this->setup->clear();

            return;
        }
        $response = $this->respond($event->getRequest(), $administrator);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex');
        $event->setResponse($response);
    }

    private function respond(Request $request, FirstAdministrator $administrator): Response
    {
        $method = $request->getRealMethod();
        $path = $request->getPathInfo();
        $base = $request->getBasePath();
        $wantsPage = ($method === 'GET' || $method === 'HEAD') && (str_contains(strtolower((string) $request->headers->get('Accept', '')), 'text/html') || !$request->headers->has('Accept'));
        $secure = WebInstaller::secure($request);

        // The link `idno:setup:link` prints: it gives this browser the token.
        if ($method === 'GET' && preg_match('#\A/_setup/([a-f0-9]{64})/?\z#', $path, $match) === 1 && $this->setup->matches($match[1])) {
            $response = new RedirectResponse($base . self::PATH, Response::HTTP_SEE_OTHER);
            $response->headers->setCookie(Cookie::create(SetupToken::COOKIE, $match[1], time() + SetupToken::LIFETIME, '/', null, $secure, true, false, Cookie::SAMESITE_LAX));

            return $response;
        }

        $token = $request->cookies->get(SetupToken::COOKIE);
        if (!$this->setup->matches($token)) {
            if (!$wantsPage) {
                return new Response("This site is being set up.\n", Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain; charset=UTF-8', 'Retry-After' => '300']);
            }
            $nonce = base64_encode(random_bytes(18));

            return new Response($method === 'HEAD' ? '' : InstallPage::pending($nonce), Response::HTTP_SERVICE_UNAVAILABLE, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Security-Policy' => InstallPage::contentSecurityPolicy($nonce),
                'Retry-After' => '300',
            ]);
        }

        $atForm = in_array($path, ['/_setup', self::PATH], true);
        if (!$atForm || !in_array($method, ['GET', 'HEAD', 'POST'], true) || $method !== $request->getMethod()) {
            return $wantsPage
                ? new RedirectResponse($base . self::PATH, Response::HTTP_FOUND)
                : new Response("This site is being set up.\n", Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $field = SetupToken::fieldFor((string) $token);
        $form = new InstallForm($request->request->all());
        $details = new AdministratorDetails($form->raw('name'), $form->raw('handle'), $form->raw('email'), $form->raw('password'), $form->raw('password2'));
        $errors = [];
        if ($method === 'POST') {
            if (!hash_equals($field, $form->text('token'))) {
                $errors['form'] = 'That form was opened too long ago. Please fill it in again.';
            } else {
                $errors = $administrator->problems($details);
                if ($errors === []) {
                    try {
                        $uuid = $administrator->create($details, $this->setup->title());
                    } catch (\RuntimeException) {
                        $errors['form'] = 'The account could not be created. Please try again.';
                    }
                }
                if ($errors === [] && isset($uuid)) {
                    $this->setup->clear();
                    $administrator->signIn($uuid);
                    $response = new RedirectResponse($base . self::WELCOME, Response::HTTP_SEE_OTHER);
                    $response->headers->clearCookie(SetupToken::COOKIE, '/', null, $secure, true, Cookie::SAMESITE_LAX);

                    return $response;
                }
            }
        }

        $nonce = base64_encode(random_bytes(18));
        $url = $this->config->get('url');
        $html = InstallPage::account(
            ['name' => $details->name, 'handle' => $details->handle, 'email' => $details->email],
            $errors,
            $field,
            $base . self::PATH,
            is_string($url) && $url !== '' ? rtrim($url, '/') . '/' : '/',
            $nonce
        );

        return new Response($method === 'HEAD' ? '' : $html, $method === 'POST' ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => InstallPage::contentSecurityPolicy($nonce),
        ]);
    }
}
