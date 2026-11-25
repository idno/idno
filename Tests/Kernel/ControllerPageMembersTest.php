<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Common\Page;
use Idno\Legacy\ControllerPage;
use PHPUnit\Framework\TestCase;

/**
 * No member of `Page` may send a header, set the process's status, echo or exit for a page a controller serves. Every public
 * method `Page` declares is either overridden by `ControllerPage` (to work from the request and record its effect on the
 * context, or to throw) or on the list below of members that are pure, and each of those is read to prove it.
 */
final class ControllerPageMembersTest extends TestCase
{
    /**
     * Members `ControllerPage` inherits, each with why that is right. The test reads every one of these methods' own code and
     * fails for a `header()`, `http_response_code()`, `echo`, `print` or `exit` in it.
     */
    private const PURE = [
        // Answer by calling members that are overridden
        'getContent' => 'only setResponse(501), overridden',
        'postContent' => 'only setResponse(501), overridden',
        'putContent' => 'only setResponse(501), overridden',
        'deleteContent' => 'only setResponse(501), overridden',
        'webmentionContent' => 'returns false',
        'createGatekeeper' => 'canWrite() then deniedContent() (overridden) and gatekeeper()',
        'xhrGatekeeper' => 'reads the xhr property, deniedContent() (overridden)',
        'tokenGatekeeper' => 'currentUrl() (overridden) and the form token, deniedContent() (overridden)',
        'gatekeeper' => 'the session, deniedContent() (overridden)',
        'reverseGatekeeper' => 'the session, deniedContent() (overridden)',
        'adminGatekeeper' => 'the session, deniedContent() (overridden)',
        'forwardToLogin' => 'builds an address, forward() (overridden)',
        'doesPathMatch' => 'currentUrl() (overridden)',
        'matchUrl' => 'currentUrl() (overridden)',
        'selectMarkdownIfWanted' => 'wantsMarkdown() (overridden) and the template type',
        'getIcon' => 'the site\'s configuration and the current page\'s owner',
        'arguments' => 'returns the public property the constructor set',
        // Static: they cannot see a page's request, and are called by the class (`\Idno\Common\Page::isSSL()`), never through a page.
        // For a request made from the process's globals (FrontController::requestFromGlobals()) they answer as the request does
        // (ControllerPageEquivalenceTest compares isSSL() with isSecure()).
        'isSSL' => 'static, reads the process\'s server variables',
        'getallheaders' => 'static, reads the process\'s server variables',
        'getBearerToken' => 'static, reads the process\'s request headers',
        'buildUrl' => 'static, pure',
    ];

    /**
     * @return list<string>
     */
    private function declaredByPage(): array
    {
        $names = [];
        foreach ((new \ReflectionClass(Page::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class === Page::class) {
                $names[] = $method->name;
            }
        }

        return $names;
    }

    public function testEveryPublicMemberOfPageIsOverriddenOrKnownToBePure(): void
    {
        $unaccounted = [];
        foreach ($this->declaredByPage() as $name) {
            $overridden = (new \ReflectionMethod(ControllerPage::class, $name))->class === ControllerPage::class;
            if (!$overridden && !isset(self::PURE[$name])) {
                $unaccounted[] = $name;
            }
        }

        $this->assertSame([], $unaccounted, 'override these on ControllerPage (work from the request, or throw), or list them as pure with the reason');
    }

    public function testTheListOfPureMembersHoldsOnlyMembersPageDeclaresAndControllerPageInherits(): void
    {
        $declared = $this->declaredByPage();
        foreach (array_keys(self::PURE) as $name) {
            $this->assertContains($name, $declared, $name . ' is not a member of Page');
            $this->assertSame(Page::class, (new \ReflectionMethod(ControllerPage::class, $name))->class, $name . ' is overridden: take it off the list');
        }
    }

    public function testNoPureMemberSendsHeadersSetsTheStatusEchoesOrExits(): void
    {
        $found = [];
        foreach (array_keys(self::PURE) as $name) {
            $method = new \ReflectionMethod(Page::class, $name);
            $lines = array_slice(file((string) $method->getFileName()) ?: [], $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
            $tokens = token_get_all("<?php\n" . implode('', $lines));
            foreach ($tokens as $i => $token) {
                if (!is_array($token)) {
                    continue;
                }
                $isCall = $token[0] === T_STRING && in_array(strtolower($token[1]), ['header', 'http_response_code', 'header_remove', 'setcookie'], true)
                    && ($tokens[$i + 1] ?? null) === '(';
                if ($isCall || in_array($token[0], [T_ECHO, T_PRINT, T_EXIT, T_INLINE_HTML], true)) {
                    $found[] = $name . ': ' . $token[1];
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function testTheOverridesThatMustNotSendAnythingDoNotEither(): void
    {
        $source = (string) file_get_contents((string) (new \ReflectionClass(ControllerPage::class))->getFileName());
        $tokens = token_get_all($source);
        $found = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            $isCall = $token[0] === T_STRING && in_array(strtolower($token[1]), ['header', 'http_response_code', 'header_remove', 'setcookie', 'session_write_close'], true)
                && ($tokens[$i + 1] ?? null) === '(';
            if ($isCall || in_array($token[0], [T_ECHO, T_PRINT, T_EXIT], true)) {
                $found[] = $token[1] . ':' . $token[2];
            }
        }

        $this->assertSame([], $found, 'ControllerPage records effects on the context; it sends nothing');
    }
}
