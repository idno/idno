<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Webmention;

use Idno\Http\Page\DrawnPage;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageDrawer;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Http\Webmention\ReceivedWebmention;
use Idno\Http\Webmention\ReceivesWebmentions;
use Idno\Http\Webmention\WebmentionEndpoint;
use Idno\Http\Webmention\WebmentionSources;
use Idno\Http\Webmention\WebmentionTarget;
use Idno\Http\Webmention\WebmentionTargets;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The webmention endpoint on doubles of what it asks the site and a real renderer over a double of the drawing: every branch, in the order the checks are made, and
 * what each asks before it answers (the source is fetched only when everything before the fetch has passed).
 */
final class WebmentionEndpointTest extends TestCase
{
    private const SOURCE = 'https://remote.example/reply';

    private const TARGET = 'https://example.com/2026/a-note';

    /** @var list<string> every question the endpoint asked, in order */
    public array $calls = [];

    /** @var list<array{string, string}> the lines logged */
    public array $logged = [];

    public ?WebmentionTarget $resolved;

    public bool $local = false;

    public bool $safe = true;

    /** @var array<string, mixed>|false */
    public array|false $answer;

    public bool $taken = true;

    public bool $receivesRoute = true;

    /** @var list<ReceivedWebmention> */
    public array $received = [];

    private DrawerDouble $drawer;

    private WebmentionEndpoint $endpoint;

    protected function setUp(): void
    {
        $this->calls = [];
        $this->logged = [];
        $this->received = [];
        $this->taken = true;
        $this->receivesRoute = true;
        $this->safe = true;
        $this->local = false;
        $this->resolved = new WebmentionTarget('Idno\Pages\Entity\View', 'idno_permalink', ['a-note']);
        $this->answer = ['response' => 200, 'content' => '<a href="' . self::TARGET . '">a note</a>'];
        $this->drawer = new DrawerDouble();
        $test = $this;

        $targets = new class($test) implements WebmentionTargets {
            public function __construct(private readonly WebmentionEndpointTest $test)
            {
            }

            public function resolve(string $target): ?WebmentionTarget
            {
                $this->test->calls[] = 'resolve ' . $target;

                return $this->test->resolved;
            }
        };
        $sources = new class($test) implements WebmentionSources {
            public function __construct(private readonly WebmentionEndpointTest $test)
            {
            }

            public function isSafe(string $source): bool
            {
                $this->test->calls[] = 'isSafe ' . $source;

                return $this->test->safe;
            }

            public function fetch(string $source): array|false
            {
                $this->test->calls[] = 'fetch ' . $source;

                return $this->test->answer;
            }

            public function parse(string $content, string $source): array
            {
                $this->test->calls[] = 'parse ' . $source;

                return ['items' => []];
            }

            public function isLocal(string $url): bool
            {
                $this->test->calls[] = 'isLocal ' . $url;

                return $this->test->local;
            }

            public function log(string $level, string $message): void
            {
                $this->test->logged[] = [$level, $message];
            }
        };
        $receiver = new class($test) implements ReceivesWebmentions {
            public function __construct(private readonly WebmentionEndpointTest $test)
            {
            }

            public function receivesFor(string $route): bool
            {
                return $this->test->receivesRoute;
            }

            public function receiveWebmention(string $route, array $arguments, ReceivedWebmention $mention): bool
            {
                $this->test->calls[] = 'receive ' . $route . ' ' . implode(',', $arguments);
                $this->test->received[] = $mention;

                return $this->test->taken;
            }
        };
        $translation = new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return 'translated: ' . $text;
            }
        };
        $this->endpoint = new WebmentionEndpoint(
            $targets,
            $sources,
            new ServiceLocator(['Idno\Pages\Entity\View' => static fn (): ReceivesWebmentions => $receiver]),
            new PageRenderer($this->drawer, $translation),
            $translation
        );
    }

    /** @param string|array<string, mixed> $form */
    private function post(string|array $form): Response
    {
        $body = is_array($form) ? http_build_query($form) : $form;
        $context = new PageContext(Request::create('/webmention/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body), $this->sitePage());

        return $this->endpoint->receive($context);
    }

    private function sitePage(): SitePage
    {
        return new SitePage('Idno\Pages\Webmentions\Endpoint', methods: ['GET', 'POST'], formTokenReplacedBy: 'a test');
    }

    /** @return array{error: string, error_text: string} */
    private function refusal(Response $response, string $error): array
    {
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $decoded = json_decode((string) $response->getContent(), true);
        $this->assertSame(['error', 'error_text'], array_keys($decoded));
        $this->assertSame($error, $decoded['error']);
        $this->assertNotSame('', $decoded['error_text']);

        return $decoded;
    }

    private function mention(): Response
    {
        return $this->post(['source' => self::SOURCE, 'target' => self::TARGET]);
    }

    /**
     * @return array<string, array{0: string|array<string, string>}>
     */
    public static function incompleteRequests(): array
    {
        return [
            'nothing' => [[]],
            'only a source' => [['source' => self::SOURCE]],
            'only a target' => [['target' => self::TARGET]],
            'empty values' => [['source' => '', 'target' => '']],
            'equal addresses' => [['source' => self::TARGET, 'target' => self::TARGET]],
            'arrays' => ['source[]=a&target[]=b'],
            'a multipart body' => ["--b\r\nContent-Disposition: form-data; name=\"source\"\r\n\r\nx\r\n--b--\r\n"],
            'white space in the target' => [['source' => self::SOURCE, 'target' => 'not a url']],
            'a control character in the source' => [['source' => "https://remote.example/a\x00b", 'target' => self::TARGET]],
            'a line break in the target' => [['source' => self::SOURCE, 'target' => "https://example.com/a\r\nX-Injected: 1"]],
            'a very long source' => [['source' => 'https://remote.example/' . str_repeat('a', WebmentionEndpoint::MAX_ADDRESS), 'target' => self::TARGET]],
            'a very long target' => [['source' => self::SOURCE, 'target' => 'https://example.com/' . str_repeat('a', WebmentionEndpoint::MAX_ADDRESS)]],
        ];
    }

    /**
     * @dataProvider incompleteRequests
     *
     * @param string|array<string, string> $form
     */
    public function testARequestThatIsIncompleteOrMalformedIsAnUnknownErrorAndAsksNothing(string|array $form): void
    {
        $this->refusal($this->post($form), 'unknown_error');

        $this->assertSame([], $this->calls, 'nothing is looked up, checked or fetched');
    }

    public function testTheFragmentIsTakenOffTheTargetBeforeItIsResolvedAndHandedOn(): void
    {
        $response = $this->post(['source' => self::SOURCE, 'target' => self::TARGET . '#reply-1']);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('resolve ' . self::TARGET, $this->calls[0]);
        $this->assertSame(self::TARGET, $this->received[0]->target);
    }

    public function testATargetThatIsNoPageThatTakesMentionsIsTargetNotFoundAndTheSourceIsNeverFetched(): void
    {
        $this->resolved = null;

        $answer = $this->refusal($this->mention(), 'target_not_found');

        $this->assertSame('The target page ' . self::TARGET . ' does not exist.', $answer['error_text']);
        $this->assertSame(['resolve ' . self::TARGET], $this->calls, 'no check of the source and no fetch');
        $this->assertContains(['error', 'Could not find handler for ' . self::TARGET], $this->logged);
    }

    public function testAPageCannotMentionItself(): void
    {
        $this->local = true;

        $answer = $this->post(['source' => 'https://example.com/2026/a-note/', 'target' => self::TARGET]);

        $this->assertSame("A page can't webmention itself.", $this->refusal($answer, 'target_not_supported')['error_text']);
        $this->assertSame(['resolve ' . self::TARGET, 'isLocal https://example.com/2026/a-note/'], $this->calls);
    }

    public function testAnotherPageOfThisSiteMayMentionIt(): void
    {
        $this->local = true;

        $answer = $this->post(['source' => 'https://example.com/2026/another-note', 'target' => self::TARGET]);

        $this->assertSame(202, $answer->getStatusCode());
    }

    public function testASourceOnAnotherSiteWithTheTargetsPathIsNotASelfMention(): void
    {
        $this->local = false;

        $this->assertSame(202, $this->post(['source' => 'https://remote.example/2026/a-note', 'target' => self::TARGET])->getStatusCode());
    }

    public function testASourceTheSiteMayNotFetchIsSourceNotAllowed(): void
    {
        $this->safe = false;

        $answer = $this->post(['source' => 'http://127.0.0.1:9/', 'target' => self::TARGET]);

        $this->assertSame('The source URL is not allowed.', $this->refusal($answer, 'source_not_allowed')['error_text']);
        $this->assertSame(['resolve ' . self::TARGET, 'isLocal http://127.0.0.1:9/', 'isSafe http://127.0.0.1:9/'], $this->calls, 'and nothing is fetched');
    }

    public function testASourceThatCannotBeObtainedIsSourceNotFound(): void
    {
        $this->answer = false;

        $answer = $this->mention();

        $this->assertSame('The source content for ' . self::SOURCE . ' could not be obtained.', $this->refusal($answer, 'source_not_found')['error_text']);
        $this->assertSame(['resolve ' . self::TARGET, 'isLocal ' . self::SOURCE, 'isSafe ' . self::SOURCE, 'fetch ' . self::SOURCE], $this->calls);
    }

    public function testASourceThatDoesNotLinkToTheTargetIsNoLinkFound(): void
    {
        $this->answer = ['response' => 200, 'content' => '<p>Nothing here.</p>'];

        $answer = $this->mention();

        $this->assertSame('The source URI does not contain a link to the target URI.', $this->refusal($answer, 'no_link_found')['error_text']);
        $this->assertNotContains('parse ' . self::SOURCE, $this->calls);
        $this->assertSame([], $this->received);
    }

    public function testASourceThatDidNotAnswerHasNoLinkEither(): void
    {
        $this->answer = ['response' => 0, 'content' => '', 'error' => 'timeout'];

        $this->refusal($this->mention(), 'no_link_found');
    }

    public function testASourceThatHasGoneIsParsedAndHandedOnWhateverItHolds(): void
    {
        $this->answer = ['response' => 410, 'content' => ''];

        $this->assertSame(202, $this->mention()->getStatusCode());
        $this->assertSame(410, $this->received[0]->sourceResponse['response']);
    }

    public function testAnAcceptedMentionIs202WithNoBody(): void
    {
        $response = $this->mention();

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    public function testTheMentionIsHandedToTheReceiverOfItsPageWithTheRoutesParametersAndWhatWasFetched(): void
    {
        $this->mention();

        $this->assertSame(['resolve ' . self::TARGET, 'isLocal ' . self::SOURCE, 'isSafe ' . self::SOURCE, 'fetch ' . self::SOURCE, 'parse ' . self::SOURCE, 'receive idno_permalink a-note'], $this->calls);
        $mention = $this->received[0];
        $this->assertSame(self::SOURCE, $mention->source);
        $this->assertSame(self::TARGET, $mention->target);
        $this->assertSame($this->answer, $mention->sourceResponse);
        $this->assertSame(['items' => []], $mention->sourceMf2);
    }

    public function testAMentionThePageDoesNotTakeIsSourceNotSupported(): void
    {
        $this->taken = false;

        $this->assertSame('Could not interpret source as a comment.', $this->refusal($this->mention(), 'source_not_supported')['error_text']);
    }

    public function testAPageWithNoReceiverOrOneThatTakesNoMentionForTheRouteIsATargetThatDoesNotExist(): void
    {
        $this->receivesRoute = false;
        $this->refusal($this->mention(), 'target_not_found');
        $this->assertSame([], $this->received);

        $this->resolved = new WebmentionTarget('Idno\Pages\Nobody', 'idno_nobody', []);
        $this->refusal($this->mention(), 'target_not_found');
    }

    public function testALegacyPageReceivesThroughItsOwnClosure(): void
    {
        $seen = [];
        $this->resolved = new WebmentionTarget('Plugin\Page', '', ['x'], static function (ReceivedWebmention $mention) use (&$seen): bool {
            $seen[] = $mention;

            return true;
        });

        $this->assertSame(202, $this->mention()->getStatusCode());
        $this->assertCount(1, $seen);
        $this->assertSame([], $this->received, 'no service took it');
    }

    public function testEveryErrorIsJson(): void
    {
        $this->refusal($this->post([]), 'unknown_error');

        $this->resolved = null;
        $this->refusal($this->mention(), 'target_not_found');
        $this->resolved = new WebmentionTarget('Idno\Pages\Entity\View', 'idno_permalink', ['a-note']);

        $this->safe = false;
        $this->refusal($this->mention(), 'source_not_allowed');
        $this->safe = true;

        $this->answer = false;
        $this->refusal($this->mention(), 'source_not_found');

        $this->answer = ['response' => 200, 'content' => ''];
        $this->refusal($this->mention(), 'no_link_found');

        $this->answer = ['response' => 200, 'content' => self::TARGET];
        $this->taken = false;
        $this->refusal($this->mention(), 'source_not_supported');
    }

    public function testWhatTheSenderSuppliedIsCutInTheLog(): void
    {
        $this->resolved = null;
        $long = 'https://example.com/' . str_repeat('a', 400);

        $this->post(['source' => self::SOURCE, 'target' => $long]);

        $this->assertNotSame([], $this->logged);
        foreach ($this->logged as [, $line]) {
            $this->assertLessThanOrEqual(300, strlen($line), $line);
        }
    }

    public function testTheEndpointsPageIsItsOwnTemplateWithATranslatedTitle(): void
    {
        $response = $this->endpoint->page(new PageContext(Request::create('/webmention/'), $this->sitePage()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([['pages/webmention', []]], $this->drawer->templates);
        $this->assertSame('translated: Webmention endpoint', $this->drawer->pages[0]['title']);
        $this->assertSame('<pages/webmention>', $this->drawer->pages[0]['body']);
        $this->assertSame([], $this->calls);
    }
}

final class DrawerDouble implements PageDrawer
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $templates = [];

    /** @var list<array<string, mixed>> */
    public array $pages = [];

    public function templateType(): string
    {
        return 'default';
    }

    public function isApiRequest(): bool
    {
        return false;
    }

    public function finishSession(): void
    {
    }

    public function forwardTrace(?array $frames): ?string
    {
        return null;
    }

    public function displayUrl(): string
    {
        return 'https://example.com/';
    }

    public function urlWithVar(string $name, string $value, string $url): string
    {
        return $url;
    }

    public function drawTemplate(string $name, array $vars): string
    {
        $this->templates[] = [$name, $vars];

        return '<' . $name . '>';
    }

    public function drawPage(array $vars, int $status, string $shell = 'shell'): DrawnPage
    {
        $this->pages[] = $vars;

        return new DrawnPage('page', [['Content-type', 'text/html']]);
    }
}
