<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controller\Profile;

use Idno\Common\Entity;
use Idno\Entities\User;
use Idno\Http\Controller\Profile\ProfilePages;
use Idno\Http\Controller\Profile\Profiles;
use Idno\Http\Page\DrawnPage;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageDrawer;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Http\Webmention\ReceivedWebmention;
use Idno\Http\Webmention\ReceivesWebmentions;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpFoundation\Request;

/**
 * The profile page's one implementation (`ProfilePages`), on a double of what it asks the site and a real renderer over a double of the drawing: what it
 * looks up and in which order, what it draws and with which variables, the headers it asks for, what it does with the edit form's POST, and the
 * webmentions it takes.
 */
final class ProfilePagesTest extends TestCase
{
    private ProfilesDouble $site;

    private ProfileDrawerDouble $drawer;

    private ProfileLogger $logger;

    private ProfilePages $pages;

    protected function setUp(): void
    {
        $this->site = new ProfilesDouble();
        $this->drawer = new ProfileDrawerDouble();
        $this->logger = new ProfileLogger();
        $translation = new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return vsprintf($text, $subs);
            }
        };
        $this->pages = new ProfilePages(new PageRenderer($this->drawer, $translation), $this->site, $translation, fn () => $this->logger);
    }

    /** @param list<string> $arguments */
    private function context(array $arguments = ['ada'], string $method = 'GET', string $query = ''): PageContext
    {
        $request = Request::create('/profile/' . ($arguments[0] ?? '') . $query, $method);
        $context = new PageContext($request, new SitePage('Idno\Pages\User\View', shell: true));
        $context->setArguments($arguments);

        return $context;
    }

    private function mention(): ReceivedWebmention
    {
        return new ReceivedWebmention('https://elsewhere.example/a', 'https://example.com/profile/ada', ['response' => 200, 'content' => '<html></html>'], ['items' => []]);
    }

    public function testAProfileIsDrawnWithItsOwnerAndTheStream(): void
    {
        $first = (object) ['updated' => 1_600_000_000];
        $second = (object) ['updated' => 1_500_000_000];
        $this->site->items = [$first, $second];
        $this->site->count = 12;
        $page = $this->context();

        $response = $this->pages->view($page);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->site->user, $page->owner(), 'the account owns its own profile');
        $this->assertTrue($page->isPermalink(), 'the profile is a permalink');
        $this->assertSame(['entity/User/profile'], array_column($this->drawer->templates, 0));
        $vars = $this->drawer->templates[0][1];
        $this->assertSame(['user' => $this->site->user, 'items' => [$first, $second], 'count' => 12, 'offset' => 0], $vars);
        $this->assertSame('Ada Admin', $this->drawer->pages[0][0]['title']);
        $this->assertSame('The Example Site profile for Ada Admin', $this->drawer->pages[0][0]['description']);
        $this->assertSame('<entity/User/profile>', $this->drawer->pages[0][0]['body']);
    }

    public function testTheLookupsAreMadeInTheOrderTheLegacyPageMadeThem(): void
    {
        $this->pages->view($this->context(['ada', 'notes']));

        $this->assertSame(['byHandle:ada', 'typesFor:notes', 'stream:offset=0', 'updated', 'title', 'siteTitle', 'title'], $this->site->calls);
    }

    public function testAMissingProfileIsTheMissingPage(): void
    {
        $this->site->user = null;

        $response = $this->pages->view($this->context(['nobody']));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['pages/404'], array_column($this->drawer->templates, 0));
        $this->assertSame(['byHandle:nobody'], $this->site->calls, 'nothing else is asked');
    }

    public function testAnAddressWithNoHandleAndOneOfZeroNameNoProfile(): void
    {
        // the legacy page asked `empty()` of the argument, which '0' is
        foreach ([[], [''], ['0']] as $arguments) {
            $this->assertSame(404, $this->pages->view($this->context($arguments))->getStatusCode());
        }
        $this->assertSame([], $this->site->calls, 'no lookup is made for an address with no handle');
    }

    public function testNoFriendlyPathIsTheDefaultTypes(): void
    {
        foreach ([['ada'], ['ada', ''], ['ada', '0']] as $arguments) {
            $this->site->calls = [];
            $this->pages->view($this->context($arguments));
            $this->assertContains('typesFor:', $this->site->calls, 'null');
        }
    }

    public function testTheFriendlyPathIsHandedOnAsGiven(): void
    {
        $this->pages->view($this->context(['ada', 'articles/notes']));

        $this->assertContains('typesFor:articles/notes', $this->site->calls);
    }

    public function testTheOffsetIsTheRequestsAsAnInteger(): void
    {
        foreach (['?offset=10' => 10, '?offset=abc' => 0, '?offset=-5' => -5, '?offset=10.7' => 10, '?offset[]=1' => 1, '' => 0] as $query => $offset) {
            $this->site->calls = [];
            $this->pages->view($this->context(['ada'], 'GET', $query));
            $this->assertContains('stream:offset=' . $offset, $this->site->calls, $query);
        }
    }

    public function testTheProfileIsLastModifiedWhenItsAccountOrItsNewestPostWas(): void
    {
        $this->site->updatedAt = 1_700_000_000;
        $this->site->items = [new class {
            public int $updated = 1_700_000_500;
        }];
        $page = $this->context();
        $this->pages->view($page);
        $this->assertSame('Tue, 14 Nov 2023 22:21:40 GMT', $page->headers()['Last-Modified'][0], 'the newest post, when it is later');

        $this->site->items = [new class {
            public int $updated = 1_600_000_000;
        }];
        $page = $this->context();
        $this->pages->view($page);
        $this->assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $page->headers()['Last-Modified'][0], 'the account, when it is later');

        $this->site->items = false;
        $page = $this->context();
        $this->pages->view($page);
        $this->assertSame('Tue, 14 Nov 2023 22:13:20 GMT', $page->headers()['Last-Modified'][0], 'a stream the database could not answer');
    }

    public function testAProfileNeverAnswersAConditionalRequest(): void
    {
        $request = Request::create('/profile/ada', 'GET', [], [], [], ['HTTP_IF_MODIFIED_SINCE' => 'Tue, 14 Nov 2033 22:13:20 GMT']);
        $page = new PageContext($request, new SitePage('Idno\Pages\User\View', shell: true));
        $page->setArguments(['ada']);

        $this->assertSame(200, $this->pages->view($page)->getStatusCode());
    }

    public function testTheEditFormSavesAndGoesToTheProfile(): void
    {
        $this->site->url = 'https://example.com/profile/ada';
        $this->site->saved = true;

        $response = $this->pages->save($this->context(['ada'], 'POST'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.com/profile/ada', $response->headers->get('Location'));
        $this->assertSame(['byHandle:ada', 'saveFromInput', 'url'], $this->site->calls);
    }

    public function testAnEditThatIsNotSavedGoesBackToWhereTheRequestCameFrom(): void
    {
        $this->site->saved = false;
        $this->site->referrer = 'https://example.com/somewhere';

        $response = $this->pages->save($this->context(['ada'], 'POST'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.com/somewhere', $response->headers->get('Location'));
        $this->assertNotContains('url', $this->site->calls);
    }

    public function testAnEditOfAProfileThatIsNotThereIsTheMissingPageAndSavesNothing(): void
    {
        $this->site->user = null;

        foreach ([false, true] as $script) {
            $page = $this->context(['nobody'], 'POST');
            $response = $this->pages->save($page);
            $this->assertSame(404, $response->getStatusCode(), $script ? 'a script' : 'a request');
        }
        $this->assertNotContains('saveFromInput', $this->site->calls);
        $this->assertSame(404, $this->pages->save($this->context([], 'POST'))->getStatusCode(), 'no handle');
    }

    public function testNothingIsStoredBeforeTheSiteSaysWhoMayEdit(): void
    {
        // the permission is the site's (the account's own `canEdit()`), asked inside the one call that stores: the service stores nothing itself
        $this->site->saved = false;
        $this->pages->save($this->context(['ada'], 'POST'));

        $this->assertSame(['byHandle:ada', 'saveFromInput'], array_slice($this->site->calls, 0, 2));
        $this->assertSame(1, count(array_keys($this->site->calls, 'saveFromInput', true)));
    }

    public function testOnlyTheProfileRoutesReceive(): void
    {
        $this->assertTrue($this->pages->receivesFor('idno_profile'));
        foreach (['idno_home', 'idno_permalink', 'idno_feed', ''] as $route) {
            $this->assertFalse($this->pages->receivesFor($route), $route);
            $this->assertFalse($this->pages->receiveWebmention($route, ['ada'], $this->mention()), $route);
        }
        $this->assertSame([], $this->site->calls);
    }

    public function testItIsTheReceiverOfTheProfileUnderItsPagesName(): void
    {
        $this->assertInstanceOf(ReceivesWebmentions::class, $this->pages);
        $attributes = (new \ReflectionClass(ProfilePages::class))->getAttributes(AsTaggedItem::class);
        $this->assertCount(1, $attributes);
        $this->assertSame('Idno\Pages\User\View', $attributes[0]->newInstance()->index);
    }

    public function testAMentionOfAProfileIsAcceptedAndNothingIsStored(): void
    {
        $this->assertTrue($this->pages->receiveWebmention('idno_profile', ['ada'], $this->mention()));

        $this->assertSame(['byHandle:ada', 'handle'], $this->site->calls);
        $this->assertSame([
            ['info', 'received user mention from https://elsewhere.example/a to https://example.com/profile/ada'],
            ['debug', 'found target user ada'],
        ], $this->logger->lines);
    }

    public function testAMentionWhoseSourceIsNotAnOrdinaryPageIsAcceptedAndNothingIsDone(): void
    {
        $gone = new ReceivedWebmention('https://elsewhere.example/a', 'https://example.com/profile/ada', ['response' => 410, 'content' => ''], ['items' => []]);

        $this->assertTrue($this->pages->receiveWebmention('idno_profile', ['ada'], $gone));
        $this->assertSame([
            ['info', 'received user mention from https://elsewhere.example/a to https://example.com/profile/ada'],
            ['debug', 'found target user ada'],
        ], $this->logger->lines);
    }

    public function testAMentionWithNoArgumentsOrForAHandleNobodyHasIsNotTaken(): void
    {
        $this->assertFalse($this->pages->receiveWebmention('idno_profile', [], $this->mention()));
        $this->assertSame([
            ['info', 'received user mention from https://elsewhere.example/a to https://example.com/profile/ada'],
            ['debug', 'could not process user mention, no pagehandler arguments'],
        ], $this->logger->lines);

        $this->logger->lines = [];
        $this->site->user = null;
        $this->assertFalse($this->pages->receiveWebmention('idno_profile', ['nobody'], $this->mention()));
        $this->assertSame([
            ['info', 'received user mention from https://elsewhere.example/a to https://example.com/profile/ada'],
            ['debug', 'could not process user mention, no user for handle nobody'],
        ], $this->logger->lines);
    }

    public function testTheSendersAddressesAreLoggedWithNoControlCharactersAndBounded(): void
    {
        $mention = new ReceivedWebmention("https://elsewhere.example/a\nb" . str_repeat('x', 400), "https://example.com/profile/ada\r\n", ['response' => 200], ['items' => []]);

        $this->pages->receiveWebmention('idno_profile', ['ada'], $mention);

        $line = $this->logger->lines[0][1];
        $this->assertStringNotContainsString("\n", $line);
        $this->assertStringNotContainsString("\r", $line);
        $this->assertLessThanOrEqual(strlen('received user mention from  to ') + 200 + 200, strlen($line));
    }
}

final class ProfilesDouble implements Profiles
{
    /** @var list<string> */
    public array $calls = [];

    public ?User $user;

    /** @var array<int, mixed>|false */
    public array|false $items = [];

    public int $count = 0;

    public int $updatedAt = 1_700_000_000;

    public bool $saved = true;

    public string $url = 'https://example.com/profile/ada';

    public string $referrer = 'https://example.com/';

    public function __construct()
    {
        $this->user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
    }

    public function byHandle(string $handle): ?User
    {
        $this->calls[] = 'byHandle:' . $handle;

        return $this->user;
    }

    public function typesFor(?string $friendly): array
    {
        $this->calls[] = 'typesFor:' . ($friendly ?? '');

        return ['a-type'];
    }

    public function stream(User $user, array $types, int $offset): array
    {
        $this->calls[] = 'stream:offset=' . $offset;

        return ['items' => $this->items, 'count' => $this->count];
    }

    public function title(User $user): string
    {
        $this->calls[] = 'title';

        return 'Ada Admin';
    }

    public function siteTitle(): string
    {
        $this->calls[] = 'siteTitle';

        return 'Example Site';
    }

    public function updated(User $user): int
    {
        $this->calls[] = 'updated';

        return $this->updatedAt;
    }

    public function handle(User $user): string
    {
        $this->calls[] = 'handle';

        return 'ada';
    }

    public function saveFromInput(User $user): bool
    {
        $this->calls[] = 'saveFromInput';

        return $this->saved;
    }

    public function url(User $user): string
    {
        $this->calls[] = 'url';

        return $this->url;
    }

    public function referrer(PageContext $page): string
    {
        $this->calls[] = 'referrer';

        return $this->referrer;
    }
}

final class ProfileDrawerDouble implements PageDrawer
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $templates = [];

    /** @var list<array{0: array<string, mixed>, 1: int, 2: string}> */
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
        $this->pages[] = [$vars, $status, $shell];

        return new DrawnPage('page:' . ($vars['title'] ?? '') . ':' . ($vars['body'] ?? ''), [['Content-type', 'text/html']]);
    }
}

final class ProfileLogger extends AbstractLogger
{
    /** @var list<array{0: string, 1: string}> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = [(string) $level, (string) $message];
    }
}
