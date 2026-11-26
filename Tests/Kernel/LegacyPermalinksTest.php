<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * What the post permalink asks the site (`Idno\Legacy\LegacyPermalinks`), each method against the legacy expression it wraps, on the same posts of a site of
 * the test's own: a public note, a note private to its owner, an article that has a short description, and an account.
 */
final class LegacyPermalinksTest extends PrivateSiteTestCase
{
    private const SEED = <<<'PHP'
    $site = \Idno\Core\Idno::site();
    $bob = new \Idno\Entities\User();
    $bob->handle = 'bob';
    $bob->email = 'bob@example.com';
    $bob->setTitle('Bob');
    $bob->setPassword('correct-horse-battery-9');
    $bob->save(true);
    $make = static function (\Idno\Common\Entity $entity, string $id, string $access, array $fields) use ($bob): \Idno\Common\Entity {
        $entity->_id = $id;
        foreach ($fields as $name => $value) {
            $entity->$name = $value;
        }
        $entity->setOwner($bob);
        $entity->setAccess($access);
        $entity->setPublishStatus('published');
        $entity->created = (int) strtotime('2026-03-14 12:00:00 UTC');
        $entity->setSlugResilient((string) ($fields['title'] ?? $fields['body']));
        $entity->save(true);

        return $entity;
    };
    $public = $make(new \IdnoPlugins\Status\Status(), '00000000000040008000000000000041', 'PUBLIC', ['body' => 'Bob writes a note for everyone to read.']);
    $private = $make(new \IdnoPlugins\Status\Status(), '00000000000040008000000000000043', $bob->getUUID(), ['body' => 'Bob keeps a note to himself.']);
    $article = $make(new \IdnoPlugins\Text\Entry(), '00000000000040008000000000000046', 'PUBLIC', ['title' => 'An article', 'body' => '<p>The article\'s body, in a paragraph.</p>', 'short_description' => 'A short description.']);
    PHP;

    /**
     * The visitor's view of the posts: nobody is signed in.
     */
    public function testWhatAVisitorAsksAboutAPost(): void
    {
        $answers = $this->runInSite(self::SEED . <<<'PHP'
        $permalinks = new \Idno\Legacy\LegacyPermalinks();
        $out = [];
        foreach (['public' => $public, 'private' => $private, 'article' => $article] as $name => $post) {
            $found = $permalinks->find((string) $post->getID());
            $bySlug = $permalinks->find((string) $post->getSlug());
            $legacy = \Idno\Common\Entity::getByID((string) $post->getID()) ?: \Idno\Common\Entity::getBySlug((string) $post->getID());
            $out[$name] = [
                'byId' => $found !== null ? $found->getUUID() : null,
                'bySlug' => $bySlug !== null ? $bySlug->getUUID() : null,
                'legacyUuid' => $legacy ? $legacy->getUUID() : null,
                'canRead' => [$permalinks->canRead($post), (bool) $post->canRead()],
                'canEdit' => [$permalinks->canEdit($post), (bool) $post->canEdit()],
                'isUser' => $permalinks->isUser($post),
                'displayUrl' => [$permalinks->displayUrl($post), $post->getDisplayURL()],
                'owner' => [$permalinks->owner($post)?->getUUID(), $post->getOwner()->getUUID()],
                'title' => [$permalinks->title($post), $post->getTitle()],
                'description' => $permalinks->description($post),
                'shortDescription' => $post->getShortDescription(),
                'sample' => \Idno\Core\Idno::site()->template()->sampleText($post->getDescription()),
                'updated' => [$permalinks->updated($post), $post->updated],
                'objectUrl' => [$permalinks->objectUrl($post), $post->getObjectURL()],
            ];
        }
        $user = \Idno\Entities\User::getByHandle('bob');
        $out['user'] = ['isUser' => $permalinks->isUser($user), 'found' => $permalinks->find((string) $user->getID())?->getUUID(), 'uuid' => $user->getUUID()];
        $out['none'] = [$permalinks->find('no-such-post'), $permalinks->find('')];
        $out['session'] = [$permalinks->isLoggedIn(), $permalinks->isAdministrator()];

        return $out;
        PHP);

        foreach (['public', 'private', 'article'] as $name) {
            $a = $answers[$name];
            $this->assertSame($a['legacyUuid'], $a['byId'], $name . ' by its id');
            $this->assertSame($a['byId'], $a['bySlug'], $name . ' by its slug');
            $this->assertSame($a['canRead'][1], $a['canRead'][0], $name);
            $this->assertSame($a['canEdit'][1], $a['canEdit'][0], $name);
            $this->assertFalse($a['isUser'], $name);
            $this->assertSame($a['displayUrl'][1], $a['displayUrl'][0], $name);
            $this->assertSame($a['owner'][1], $a['owner'][0], $name);
            $this->assertSame((string) $a['title'][1], $a['title'][0], $name);
            $this->assertSame((int) $a['updated'][1], $a['updated'][0], $name);
            $this->assertSame($a['objectUrl'][1], $a['objectUrl'][0], $name);
        }
        $this->assertNotNull($answers['public']['byId']);
        $this->assertNotNull($answers['article']['byId']);
        $this->assertNull($answers['private']['byId'], 'the finder does not find what the viewer may not read');
        $this->assertTrue($answers['public']['canRead'][0]);
        $this->assertFalse($answers['private']['canRead'][0], 'a post private to its owner is not read by a visitor');
        $this->assertSame('A short description.', $answers['article']['description'], 'the short description, when there is one');
        $this->assertSame($answers['public']['sample'], $answers['public']['description'], 'else a sample of the description');
        $this->assertNotSame('', $answers['public']['description']);
        $this->assertTrue($answers['user']['isUser']);
        $this->assertSame($answers['user']['uuid'], $answers['user']['found'], 'an account is found by its id');
        $this->assertSame([null, null], $answers['none']);
        $this->assertSame([false, false], $answers['session'], 'nobody is signed in');
    }

    /**
     * What a signed-in member and an administrator are, what deleting a post does, and where a request is sent back to.
     */
    public function testWhatASignedInMemberAsksAndWhatDeletingDoes(): void
    {
        $answers = $this->runInSite(self::SEED . <<<'PHP'
        $site = \Idno\Core\Idno::site();
        $permalinks = new \Idno\Legacy\LegacyPermalinks();
        $out = [];
        $site->session()->logUserOn($bob);
        $out['session'] = [$permalinks->isLoggedIn(), $permalinks->isAdministrator(), $bob->isAdmin()];
        $out['canEdit'] = [$permalinks->canEdit($public), (bool) $public->canEdit()];
        $out['canReadPrivate'] = [$permalinks->canRead($private), (bool) $private->canRead()];

        $referrer = static function (string $header) use ($permalinks): string {
            $request = \Symfony\Component\HttpFoundation\Request::create('/2026/a-note', 'DELETE', [], [], [], $header === '' ? [] : ['HTTP_REFERER' => $header]);

            return $permalinks->referrer(new \Idno\Http\Page\PageContext($request, new \Idno\Http\Page\SitePage('Idno\Pages\Entity\View')));
        };
        $base = $site->config()->getDisplayURL();
        $out['base'] = $base;
        $out['referrer'] = [
            'none' => [$referrer(''), \Idno\Pages\Session\Login::sanitizeRedirectUrl('')],
            'own' => [$referrer($base . 'somewhere/else'), \Idno\Pages\Session\Login::sanitizeRedirectUrl($base . 'somewhere/else')],
            'foreign' => [$referrer('https://elsewhere.example/page'), \Idno\Pages\Session\Login::sanitizeRedirectUrl('https://elsewhere.example/page')],
            'protocol-relative' => [$referrer('//elsewhere.example/page'), \Idno\Pages\Session\Login::sanitizeRedirectUrl('//elsewhere.example/page')],
        ];

        $site->session()->flushMessages();
        $out['deleted'] = $permalinks->delete($article);
        $out['messages'] = array_map(static fn ($message): string => is_array($message) ? (string) ($message['message'] ?? '') : (string) $message, array_values($site->session()->getMessages()));
        $out['stillThere'] = \Idno\Common\Entity::getByID((string) $article->getID()) ? true : false;
        $out['expected'] = $site->language()->esc_('%s was deleted.', ['An article']);

        return $out;
        PHP);

        $this->assertSame([true, false, false], $answers['session']);
        $this->assertSame([true, true], $answers['canEdit']);
        $this->assertSame([true, true], $answers['canReadPrivate'], 'the owner reads a post private to them');
        foreach ($answers['referrer'] as $kind => [$asked, $legacy]) {
            $this->assertSame($legacy, $asked, $kind);
        }
        $this->assertSame($answers['base'], $answers['referrer']['foreign'][0], 'a page of another site is not where a request is sent back to');
        $this->assertTrue($answers['deleted']);
        $this->assertFalse($answers['stillThere']);
        $this->assertContains($answers['expected'], $answers['messages'], 'the member is told the post was deleted');
    }

    /**
     * The Markdown form of a post, and what a webmention does to it.
     */
    public function testTheMarkdownFormAndTheWebmentionsOfAPost(): void
    {
        $answers = $this->runInSite(self::SEED . <<<'PHP'
        $site = \Idno\Core\Idno::site();
        $permalinks = new \Idno\Legacy\LegacyPermalinks();
        $out = [];
        $contextOf = static function (string $uri): \Idno\Http\Page\PageContext {
            $context = new \Idno\Http\Page\PageContext(\Symfony\Component\HttpFoundation\Request::create($uri), new \Idno\Http\Page\SitePage('Idno\Pages\Entity\View', shell: true, markdownPermalink: true));
            $site = \Idno\Core\Idno::site();
            $site->setCurrentPage(new \Idno\Legacy\ControllerPage($context));

            return $context;
        };
        $plain = $contextOf('/2026/a-note');
        $out['wantsPlain'] = $permalinks->wantsMarkdown($plain);
        $asked = $contextOf('/2026/a-note?format=markdown');
        $out['wantsAsked'] = $permalinks->wantsMarkdown($asked);
        $permalinks->selectMarkdownIfWanted($asked);
        $out['type'] = $site->template()->getTemplateType();
        $response = $permalinks->markdown($asked, $public);
        $out['status'] = $response->getStatusCode();
        $out['body'] = $response->getContent();
        $out['length'] = $response->headers->get('Content-Length');
        $out['headers'] = $asked->headers();

        // a mention from the post itself is not stored, and a mention of it from elsewhere is (the legacy call)
        $site->setCurrentPage(new \Idno\Legacy\BarePage());
        $mention = new \Idno\Http\Webmention\ReceivedWebmention('https://elsewhere.example/a', $public->getURL(), ['response' => 200, 'content' => ''], []);
        $out['mention'] = [$permalinks->addWebmentions($public, $mention), (bool) $article->addWebmentions('https://elsewhere.example/a', $article->getURL(), ['response' => 200, 'content' => ''], [])];

        return $out;
        PHP);

        $this->assertFalse($answers['wantsPlain']);
        $this->assertTrue($answers['wantsAsked']);
        $this->assertSame('markdown', $answers['type']);
        $this->assertSame(200, $answers['status']);
        $this->assertStringStartsWith("---\n", $answers['body'], 'a Markdown document begins with its front matter');
        $this->assertStringContainsString('Bob writes a note for everyone to read.', $answers['body']);
        $this->assertSame((string) strlen($answers['body']), $answers['length'], 'the length of the document, in bytes');
        $this->assertStringContainsString('markdown', strtolower(json_encode($answers['headers'], JSON_THROW_ON_ERROR)), 'the type of the document');
        $this->assertSame($answers['mention'][1], $answers['mention'][0], 'a mention is added as the post adds it');
    }
}
