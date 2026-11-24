<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Security\FormToken;
use Idno\Security\HexTokenGenerator;
use Idno\Security\SessionTokenStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManager;

/**
 * The form token on the manager Idno wires, with no session (the unit tier has none): the process keeps the token.
 */
final class FormTokenTest extends TestCase
{
    private mixed $sessionBefore = null;

    protected function setUp(): void
    {
        // Under the full bootstrap a session is active and the token is kept in it: leave it as it was.
        $this->sessionBefore = $_SESSION ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->sessionBefore === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->sessionBefore;
        }
    }

    private function formToken(): FormToken
    {
        $storage = new SessionTokenStorage();

        return new FormToken(new CsrfTokenManager(new HexTokenGenerator(), $storage, ''), $storage);
    }

    public function testNoSessionIsStartedAndTheTokenIsStable(): void
    {
        $status = session_status();
        $formToken = $this->formToken();

        $first = $formToken->value();

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $first);
        $this->assertSame($first, $formToken->value());
        $this->assertSame($status, session_status(), 'drawing a token starts no session');
        $this->assertSame([], headers_list());
    }

    public function testTheStoredValueIsAccepted(): void
    {
        $formToken = $this->formToken();

        $this->assertTrue($formToken->isValid($formToken->value()));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function refused(): array
    {
        return [
            'empty' => [''],
            'an array' => [['x']],
            'null' => [null],
            'an integer' => [1],
            'another token' => [str_repeat('a', 64)],
            'upper case' => ['A'],
            'a randomised look-alike' => ['aa.' . base64_encode(str_repeat('a', 32)) . '.' . base64_encode(str_repeat('b', 64))],
        ];
    }

    /**
     * @dataProvider refused
     */
    public function testAnythingElseIsRefused(mixed $submitted): void
    {
        $formToken = $this->formToken();
        $formToken->value();

        $this->assertFalse($formToken->isValid($submitted));
    }

    public function testForgettingGivesANewTokenAtOnce(): void
    {
        $formToken = $this->formToken();
        $old = $formToken->value();

        $formToken->forget();
        $new = $formToken->value();

        $this->assertNotSame($old, $new);
        $this->assertFalse($formToken->isValid($old));
        $this->assertTrue($formToken->isValid($new));
    }

    public function testWithNoSessionTheTokenIsTheProcessesAndWithOneTheSessions(): void
    {
        $one = $this->formToken()->value();
        $two = $this->formToken()->value();

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->assertSame($one, $two, 'the session holds the token, whoever asks');
        } else {
            $this->assertNotSame($one, $two, 'with no session each store holds its own');
        }
    }
}
