<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller;

use Idno\Http\Controller\FormTokenController;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Idno\Security\FormToken;
use Idno\Security\HexTokenGenerator;
use Idno\Security\SessionTokenStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Tests\Unit\Http\Controller\Discovery\DiscoveryDoubles;

require_once __DIR__ . '/Discovery/DiscoveryDoubles.php';

final class FormTokenControllerTest extends TestCase
{
    private FormToken $token;

    private mixed $sessionBefore = null;

    protected function setUp(): void
    {
        $this->sessionBefore = $_SESSION ?? null;
        $storage = new SessionTokenStorage();
        $this->token = new FormToken(new CsrfTokenManager(new HexTokenGenerator(), $storage, ''), $storage);
    }

    protected function tearDown(): void
    {
        if ($this->sessionBefore === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->sessionBefore;
        }
    }

    private function controller(): FormTokenController
    {
        return new FormTokenController($this->token, DiscoveryDoubles::renderer(DiscoveryDoubles::drawer()));
    }

    private function context(string $uri, bool $xhr): PageContext
    {
        return new PageContext(Request::create($uri, 'GET', [], [], [], $xhr ? ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'] : []), new SitePage('Fixture\Page'));
    }

    public function testTheFormTokenNeedsXhrAndNoUrl(): void
    {
        $refused = $this->controller()($this->context('/service/security/csrftoken/', false));
        $this->assertSame(403, $refused->getStatusCode(), 'only an XHR request is given a token');
        $this->assertStringNotContainsString($this->token->value(), (string) $refused->getContent());

        foreach (['/service/security/csrftoken/', '/service/security/csrftoken/?url=x', '/service/security/csrftoken/?url[]=x'] as $uri) {
            $context = $this->context($uri, true);
            $answer = $this->controller()($context);

            $this->assertSame(200, $answer->getStatusCode(), $uri);
            $this->assertSame('application/json', $answer->headers->get('Content-Type'), $uri);
            $this->assertSame(['Cache-Control' => 'no-store'], $context->caching(), $uri);
        }
    }

    public function testTheFormTokenIsTheSessionsStoredValue(): void
    {
        $before = time();
        $answer = json_decode((string) $this->controller()($this->context('/service/security/csrftoken/', true))->getContent(), true);
        $after = time();

        $this->assertSame(['time', 'token'], array_keys($answer));
        $this->assertSame($this->token->value(), $answer['token']);
        $this->assertTrue($this->token->isValid($answer['token']));
        $this->assertGreaterThanOrEqual($before, $answer['time']);
        $this->assertLessThanOrEqual($after, $answer['time']);
    }

    public function testTheRouteAndPageItDeclares(): void
    {
        $method = new \ReflectionMethod(FormTokenController::class, '__invoke');
        $route = $method->getAttributes(Route::class)[0]->newInstance();
        $page = $method->getAttributes(SitePage::class)[0]->newInstance();

        $this->assertSame('/{lead}service/security/csrftoken{slash}', $route->path);
        $this->assertSame(['GET', 'HEAD', 'OPTIONS'], $route->methods);
        $this->assertSame('Idno\Pages\Service\Security\CSRFToken', $page->name);
        $this->assertFalse($page->shell);
        $this->assertFalse($page->publicOnPrivateSite, 'a visitor of a private site is refused, as before');
    }
}
