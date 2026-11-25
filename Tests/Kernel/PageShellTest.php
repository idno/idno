<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * The base of the deprecated shells (`Idno\Legacy\PageShell`), in a process of its own on a site of the test's own: what a shell draws is what
 * the service draws for the same request, what it gives the service is what the shell holds, what the service throws is what a legacy page
 * did, and a subclass of a shell (not the shell) logs its deprecation once for the process.
 */
final class PageShellTest extends PrivateSiteTestCase
{
    /** The shell of a test: a page whose handler is the code given, with the legacy exits recorded and ended as they ended a request */
    private const SHELL = <<<'PHP'
    $shell = static function (\Closure $serve) {
        return new class ($serve) extends \Idno\Legacy\PageShell {
            protected const SHELL = '';
            private \Closure $serve;
            public function __construct(\Closure $serve)
            {
                $this->serve = $serve;
                parent::__construct();
            }
            public function getContent()
            {
                $this->delegate($this->serve);
            }
            private function leave(string $how, string $argument = ''): never
            {
                echo json_encode(['exit' => $how, 'argument' => $argument, 'current' => get_class(\Idno\Core\Idno::site()->currentPage()) === static::class ? 'shell' : get_class(\Idno\Core\Idno::site()->currentPage()), 'status' => $this->response()]);
                exit;
            }
            public function forward(string $location = '', bool $exit = true) { $this->leave('forward', $location); }
            public function deniedContent($title = '') { $this->leave('denied', (string) $title); }
            public function noContent() { $this->leave('none'); }
            public function goneContent() { $this->leave('gone'); }
        };
    };
    PHP;

    protected function setUp(): void
    {
        parent::setUp();
        $cookie = $this->registerUser('ada');
        $this->publishStatus($cookie, 'A note in the stream of the shell test #books');
    }

    /**
     * Runs code in the site that ends the request as a legacy page does (it exits): the output is what the code printed.
     */
    private function runUntilExit(string $code): string
    {
        return $this->runUntilExitWithState($code, 'return null;')['output'];
    }

    /**
     * As runUntilExit(), with the state of the process once the request has ended: `$state` is PHP code, run in a shutdown function, whose
     * returned value is the second element.
     *
     * @return array{output: string, state: mixed}
     */
    private function runUntilExitWithState(string $code, string $state): array
    {
        $result = $this->runInSite(
            'ob_start();'
            . 'register_shutdown_function(static function () { $state = (static function () {' . $state . '})(); fwrite(STDOUT, "\n#RESULT#" . json_encode(["output" => ob_get_clean(), "state" => $state])); });'
            . self::SHELL . $code
        );
        $this->assertIsArray($result);

        return $result;
    }

    public function testTheShellServesWhatTheServiceReturnsAndGivesItWhatItHolds(): void
    {
        $seen = $this->runUntilExitWithState(
            '$request = \Symfony\Component\HttpFoundation\Request::create("/content/note?q=a");'
            . '\Idno\Core\Idno::container()->get("request_stack")->push($request);'
            . '$page = $shell(static function (\Idno\Http\Page\PageContext $context): \Symfony\Component\HttpFoundation\Response {'
            . '  $current = \Idno\Core\Idno::site()->currentPage();'
            . '  return new \Symfony\Component\HttpFoundation\Response(json_encode(["arguments" => $context->arguments(), "set" => $context->input("fixture"), "q" => $context->input("q"), "page" => get_class($current), "pageName" => $current->pageClassName(), "permalink" => $context->isPermalink()]), 201);'
            . '});'
            . '$GLOBALS["shellPage"] = $page; $page->arguments = ["note"]; $page->setInput("fixture", "set"); $page->setPermalink();'
            . '$page->getContent(); echo "not reached";',
            'return [http_response_code(), get_class(\Idno\Core\Idno::site()->currentPage()) === get_class($GLOBALS["shellPage"])];'
        );

        $body = json_decode($seen['output'], true);
        [$status, $restored] = $seen['state'];
        $this->assertSame(['note'], $body['arguments']);
        $this->assertSame('set', $body['set']);
        $this->assertSame('a', $body['q']);
        $this->assertSame('Idno\Legacy\ControllerPage', $body['page'], 'the current page is a controller page while the service runs');
        $this->assertStringStartsWith('Idno\Legacy\PageShell@anonymous', $body['pageName'], 'and the page the visitor asked for by its class');
        $this->assertTrue($body['permalink']);
        $this->assertSame(201, $status, 'the status is the response\'s');
        $this->assertTrue($restored, 'the shell is the current page again once it has served');
    }

    /**
     * A page that is written ends the request, whatever its status, as `drawPage()` ended it (`exit` after the echo): what follows in the
     * handler never runs.
     */
    public function testEveryResponseTheShellWritesEndsThePage(): void
    {
        foreach ([200, 201, 404, 500] as $status) {
            $out = $this->runUntilExit(
                '\Idno\Core\Idno::container()->get("request_stack")->push(\Symfony\Component\HttpFoundation\Request::create("/"));'
                . '$page = $shell(static fn (\Idno\Http\Page\PageContext $context) => new \Symfony\Component\HttpFoundation\Response("the page", ' . $status . '));'
                . '$page->getContent(); echo "not reached";'
            );

            $this->assertSame('the page', $out, (string) $status);
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function exits(): array
    {
        return [
            'a forward' => ['throw new \Idno\Http\Page\PageRedirect("http://example.test/there")', ['exit' => 'forward', 'argument' => 'http://example.test/there']],
            'a refusal' => ['throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException("No entry")', ['exit' => 'denied', 'argument' => 'No entry']],
            'a missing page' => ['throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException()', ['exit' => 'none', 'argument' => '']],
            'a gone page' => ['throw new \Symfony\Component\HttpKernel\Exception\GoneHttpException()', ['exit' => 'gone', 'argument' => '']],
        ];
    }

    /**
     * @dataProvider exits
     *
     * @param array<string, string> $expected
     */
    public function testExceptionsMapToTheLegacyExits(string $throw, array $expected): void
    {
        $out = $this->runUntilExit(
            '\Idno\Core\Idno::container()->get("request_stack")->push(\Symfony\Component\HttpFoundation\Request::create("/"));'
            . '$page = $shell(static fn (\Idno\Http\Page\PageContext $context) => ' . $throw . ');'
            . '$page->getContent();'
        );

        // the legacy exit ran with the shell, not the controller page, as the current page
        $answer = json_decode($out, true);
        unset($answer['status']);
        $this->assertSame($expected + ['current' => 'shell'], $answer);
    }

    public function testAForwardResponseEndsThePage(): void
    {
        $out = $this->runUntilExit(
            '\Idno\Core\Idno::container()->get("request_stack")->push(\Symfony\Component\HttpFoundation\Request::create("/"));'
            . '$page = $shell(static fn (\Idno\Http\Page\PageContext $context) => (new \Symfony\Component\HttpFoundation\Response("", 302, ["Location" => "http://example.test/begin/"])));'
            . '$page->getContent(); echo "not reached";'
        );

        $this->assertStringNotContainsString('not reached', $out);
    }

    public function testOnlyASubclassLogsTheDeprecationOncePerProcess(): void
    {
        $lines = $this->runInSite(
            '$log = sys_get_temp_dir() . "/idno-shell-log-" . bin2hex(random_bytes(4));'
            . 'ini_set("error_log", $log);'
            . 'new \Idno\Pages\Homepage(); new \Idno\Pages\Homepage();'
            . '$beforeSubclass = is_file($log) ? file_get_contents($log) : "";'
            . 'new \Tests\Fixtures\ThirdPartyHomepage(); new \Tests\Fixtures\ThirdPartyHomepage();'
            . '(new \Tests\Fixtures\ThirdPartyHomepage())->webmentionContent("http://a.example/", "http://b.example/", [], []);'
            . '$all = is_file($log) ? (string) file_get_contents($log) : ""; @unlink($log);'
            . 'return [substr_count($beforeSubclass, "Deprecated:"), substr_count($all, "Deprecated: Tests\\\\Fixtures\\\\ThirdPartyHomepage extends Idno\\\\Pages\\\\Homepage"), substr_count($all, "docs/developers/plugins/upgrading.md")];'
        );

        $this->assertSame([0, 1, 1], $lines);
    }

    public function testEachShellLogsItsOwnDeprecationOncePerProcess(): void
    {
        $lines = $this->runInSite(
            '$log = sys_get_temp_dir() . "/idno-shell-log-" . bin2hex(random_bytes(4));'
            . 'ini_set("error_log", $log);'
            . 'eval("class OneShellPage extends \\Idno\\Legacy\\PageShell { protected const SHELL = \'ShellOne\'; } class TwoShellPage extends \\Idno\\Legacy\\PageShell { protected const SHELL = \'ShellTwo\'; }");'
            . 'new \OneShellPage(); new \OneShellPage(); new \TwoShellPage(); new \TwoShellPage();'
            . '$all = is_file($log) ? (string) file_get_contents($log) : ""; @unlink($log);'
            . 'return [substr_count($all, "extends ShellOne,"), substr_count($all, "extends ShellTwo,")];'
        );

        $this->assertSame([1, 1], $lines, 'one line for each shell, not one for all of them');
    }
}
