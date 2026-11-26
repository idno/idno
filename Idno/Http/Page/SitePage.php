<?php

declare(strict_types=1);

namespace Idno\Http\Page;

/**
 * Says which legacy page a controller serves: what a template, a plugin or the router's rules know a page by. A controller
 * that draws a site page carries it on its class or on the method that answers.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class SitePage
{
    /** @var array<string, ?self> */
    private static array $cache = [];

    /**
     * @param string       $name                The legacy page class whose page this draws (the body class and `page/get`'s `page_class`: the controller wiring gives the event this name)
     * @param bool         $shell               The class `name` is kept as a deprecated shell (an `Idno\Legacy\PageShell`) and core still registers it for the
     *                                          addresses this route serves: the legacy router's choice of it is this controller's place held, as the placeholder
     *                                          (`Idno\Legacy\ControllerRoute`) is for a page whose class was deleted
     * @param bool         $publicOnPrivateSite The page is served to a visitor on a walled garden site
     * @param bool         $accountPage         A page under /account/ (closed to API requests)
     * @param bool         $settingsPage        A page under /account/ or /admin/ (holds stored secrets)
     * @param string|null  $defaultType         The template type the page is drawn in when the request names none
     * @param list<string> $methods             The HTTP methods the page serves besides OPTIONS (always) and HEAD (with GET): a method it does not name is refused
     * @param bool         $markdownPermalink   The page is the permalink of a post and answers its Markdown form (a legacy page was a `MarkdownPermalink`): a GET or HEAD request whose
     *                                          route's last parameter ends in `.md` is the request for it, the suffix is taken off the parameter and the `markdown` type selected
     *                                          (the route may take the suffix with its parameter, as the legacy pattern took it); a page whose shell is a `MarkdownPermalink` says so
     * @param string|null  $formTokenReplacedBy What authenticates the page's state-changing requests (POST, PUT, PATCH, DELETE) in place of the session's form token, as a sentence: null (the default)
     *                                          means the form token is required. Such a request is made stateless before the page is begun, so it never acts as the browser session's user (the legacy
     *                                          session's and Symfony Security's), and the page authenticates it itself. Refused with `accountPage` or `settingsPage`, and for a page that declares no state-changing method
     * @throws \InvalidArgumentException for an exemption that is empty, on an account or settings page, or on a page with no state-changing method
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $shell = false,
        public readonly bool $publicOnPrivateSite = false,
        public readonly bool $accountPage = false,
        public readonly bool $settingsPage = false,
        public readonly ?string $defaultType = null,
        public readonly array $methods = ['GET'],
        public readonly bool $markdownPermalink = false,
        public readonly ?string $formTokenReplacedBy = null,
    )
    {
        if ($formTokenReplacedBy === null) {
            return;
        }
        if (trim($formTokenReplacedBy) === '') {
            throw new \InvalidArgumentException('A site page that takes state-changing requests without the form token must say what authenticates them instead.');
        }
        if ($accountPage || $settingsPage) {
            throw new \InvalidArgumentException('An account or settings page cannot take state-changing requests without the form token.');
        }
        if (array_filter($methods, static fn (string $method): bool => in_array(strtoupper($method), self::STATE_CHANGING, true)) === []) {
            throw new \InvalidArgumentException('A form token exemption needs a state-changing method (POST, PUT, PATCH or DELETE) in the page\'s methods.');
        }
    }

    /**
     * A copy that names another page class and is the same in every other argument: what a shell serves under its own name (or a subclass's) with the
     * flags of the action that serves it.
     */
    public function withName(string $name): self
    {
        return new self(
            name: $name,
            shell: $this->shell,
            publicOnPrivateSite: $this->publicOnPrivateSite,
            accountPage: $this->accountPage,
            settingsPage: $this->settingsPage,
            defaultType: $this->defaultType,
            methods: $this->methods,
            markdownPermalink: $this->markdownPermalink,
            formTokenReplacedBy: $this->formTokenReplacedBy,
        );
    }

    /** The methods that change state: the form token is asked for them. */
    private const STATE_CHANGING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Whether a request with this method is one the page authenticates itself, with no form token: the page is exempt and the method changes state.
     * GET, HEAD and OPTIONS are never exempt.
     */
    public function changesStateWithoutFormToken(string $method): bool
    {
        return $this->formTokenReplacedBy !== null && in_array(strtoupper($method), self::STATE_CHANGING, true) && $this->allows($method);
    }

    /**
     * The methods the page serves, upper case: the declared ones, HEAD where GET is, and OPTIONS.
     *
     * @return list<string>
     */
    public function allowedMethods(): array
    {
        $methods = array_map('strtoupper', $this->methods);
        if (in_array('GET', $methods, true)) {
            $methods[] = 'HEAD';
        }
        $methods[] = 'OPTIONS';

        return array_values(array_unique($methods));
    }

    public function allows(string $method): bool
    {
        return in_array(strtoupper($method), $this->allowedMethods(), true);
    }

    /**
     * The attribute of a controller: the method's, else the class's. Takes an invokable object, a `Class::method` string, a
     * class name (invokable) or an `[object|class, method]` array; null for anything else (a closure, an unknown class). A `Class::method` whose method does not exist has the class's attribute.
     */
    public static function of(object|string|array $controller): ?self
    {
        if ($controller instanceof \Closure) {
            return null;
        }
        if (is_array($controller)) {
            if (count($controller) !== 2 || !isset($controller[0], $controller[1]) || !is_string($controller[1])) {
                return null;
            }
            [$class, $method] = [is_object($controller[0]) ? $controller[0]::class : $controller[0], $controller[1]];
        } elseif (is_object($controller)) {
            [$class, $method] = [$controller::class, '__invoke'];
        } elseif (str_contains($controller, '::')) {
            [$class, $method] = explode('::', $controller, 2);
        } else {
            [$class, $method] = [$controller, '__invoke'];
        }
        if (!is_string($class) || !class_exists($class)) {
            return null;
        }

        $key = $class . '::' . $method;
        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = self::read($class, $method);
        }

        return self::$cache[$key];
    }

    private static function read(string $class, string $method): ?self
    {
        $reflection = new \ReflectionClass($class);
        if ($reflection->hasMethod($method)) {
            foreach ($reflection->getMethod($method)->getAttributes(self::class) as $attribute) {
                return $attribute->newInstance();
            }
        }
        foreach ($reflection->getAttributes(self::class) as $attribute) {
            return $attribute->newInstance();
        }

        return null;
    }
}
