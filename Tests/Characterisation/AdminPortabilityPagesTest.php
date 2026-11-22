<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Core\Idno;
use Idno\Entities\File;
use Idno\Entities\User;
use IdnoPlugins\Text\Entry;
use Tests\Fixtures\RecordingMailTransport;

/**
 * The pages of the import and the export, over HTTP on the test site: who reaches which page, what the
 * downloads hold, what the account export offers, and who can fetch a generated archive.
 *
 * The import page and the archive page answer at once and do their work after they have closed the
 * connection, ten seconds later (sleep(10) in Pages\Admin\Import and Pages\Admin\Export\Generate), then mail
 * the admin. Those two are driven once, together, by scenario(): both requests are made, and the mails are
 * waited for (at most 45 seconds) in the record the test container's mail transport keeps, which is what
 * /_test/mail/log serves. The rest answer at once.
 *
 * Tests named "testToday..." pin what the site does now where that is a defect or a risk (the next change
 * must flip them); each is followed by the test of the wanted behaviour, marked incomplete until it passes.
 *
 * A logged-in user is logged in over HTTP (the login form, its CSRF token and the session cookie), with a
 * password this test sets.
 */
final class AdminPortabilityPagesTest extends CharacterisationTestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $scenario = null;

    /** @var list<\Idno\Common\Entity> posts made with a private access: the harness cannot find them again to remove them */
    private array $privatePosts = [];

    /** @var list<string> directories the web server left in the temporary directory, removed in tearDown() */
    private array $leftBehind = [];

    private ?array $savedConfig = null;

    protected function tearDown(): void
    {
        $this->removePrivatePosts();
        $this->cleanFiles();
        foreach ($this->leftBehind as $directory) {
            $this->removeDirectory($directory);
        }
        $this->leftBehind = [];
        if ($this->savedConfig !== null) {
            $config = Idno::site()->config();
            foreach (['from_email', 'export_file_id', 'export_filename', 'export_in_progress', 'export_last_requested'] as $key) {
                if (array_key_exists($key, $this->savedConfig)) {
                    $config->config[$key] = $this->savedConfig[$key];
                } else {
                    unset($config->config[$key]);
                }
            }
            $config->save();
            $this->savedConfig = null;
        }
        parent::tearDown();
    }

    /**
     * A post with a private access (the owner's UUID), which the harness's own cleanup does not find (it
     * looks posts up as nobody), removed in tearDown().
     */
    private function privateArticle(User $owner, string $title, string $body): \IdnoPlugins\Text\Entry
    {
        $entry = $this->createArticle($owner, $title, $body, $owner->getUUID());
        $this->privatePosts[] = $entry;

        return $entry;
    }

    private function privateStatus(User $owner, string $body): \IdnoPlugins\Status\Status
    {
        $status = $this->createStatus($owner, $body, $owner->getUUID());
        $this->privatePosts[] = $status;

        return $status;
    }

    private function removePrivatePosts(): void
    {
        if ($this->privatePosts === []) {
            return;
        }
        $session = Idno::site()->session();
        $session->logUserOff();
        $session->logUserOn($this->admin());
        foreach ($this->privatePosts as $post) {
            \Idno\Common\Entity::invalidateCache($post->getUUID());
            $fresh = \Idno\Common\Entity::getByUUID($post->getUUID());
            if ($fresh instanceof \Idno\Common\Entity) {
                $fresh->delete();
            }
        }
        $session->logUserOff();
        $this->privatePosts = [];
    }

    // ------------------------------------------------------------------ who reaches what

    public function testAnonymousVisitorsAreRefusedTheExportAndImportPages(): void
    {
        $other = $this->site()->request('GET', 'admin/plugins/');
        $this->assertSame(403, $other->status);

        foreach (['admin/export/', 'admin/import/'] as $path) {
            $response = $this->site()->request('GET', $path);
            $this->assertSame(403, $response->status, $path);
            $this->assertNull($response->header('Location'), $path);
            $this->assertStringNotContainsString('admin/export/rss', $response->body, $path);
            $this->assertStringNotContainsString('name="import"', $response->body, $path);
        }
    }

    public function testTodayTheGetOfTheArchivePageHasNoGatekeeperAndSendsAnyoneToTheExportPage(): void
    {
        // Pages\Admin\Export\Generate::getContent() forwards without asking who is there. It does no harm
        // (the export page it forwards to refuses the visitor), and shows the page is not guarded as the
        // others are.
        $anonymous = $this->site()->request('GET', 'admin/export/generate/');
        $this->assertSame(302, $anonymous->status);
        $this->assertSame($this->site()->baseUrl() . 'admin/export/', $anonymous->header('Location'));
    }

    public function testAnonymousPostsToTheExportsAndTheImportAreSentHomeAndDoNothing(): void
    {
        foreach (['admin/export/rss', 'admin/export/wxr', 'admin/export/generate/', 'admin/import/', 'account/export/rss', 'account/export/wxr'] as $path) {
            $response = $this->site()->request('POST', $path, [], ['allposts' => '1']);

            $this->assertSame(302, $response->status, $path);
            $this->assertSame($this->site()->baseUrl(), $response->header('Location'), $path);
            $this->assertStringNotContainsString('<rss', $response->body, $path);
        }
        $this->assertArrayNotHasKey('export_in_progress', $this->storedConfig(), 'nothing was started');
    }

    public function testAMemberIsRefusedTheAdminPagesEvenWithAValidToken(): void
    {
        $cookie = $this->logIn($this->user());
        $token = $this->formToken($this->site()->request('GET', 'account/export/', ['Cookie' => $cookie])->body, 'account/export/rss');

        foreach (['admin/export/', 'admin/import/'] as $path) {
            $this->assertSame(403, $this->site()->request('GET', $path, ['Cookie' => $cookie])->status, $path);
        }
        foreach (['admin/export/rss', 'admin/export/wxr', 'admin/export/generate/', 'admin/import/'] as $path) {
            $response = $this->site()->request('POST', $path, ['Cookie' => $cookie], $token + ['allposts' => '1']);

            $this->assertSame(403, $response->status, $path);
            $this->assertStringNotContainsString('<rss', $response->body, $path);
        }
        $this->assertArrayNotHasKey('export_in_progress', $this->storedConfig());
    }

    public function testAnAdminSeesTheExportPageWithAnRssFormAndAWxrFormAndNoWayToCreateAnArchive(): void
    {
        $cookie = $this->logIn($this->admin());

        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie]);

        $this->assertSame(200, $page->status, substr($page->body, 0, 1500));
        $this->assertStringContainsString('action="' . $this->site()->baseUrl() . 'admin/export/rss"', $page->body);
        $this->assertStringContainsString('action="' . $this->site()->baseUrl() . 'admin/export/wxr"', $page->body);
        $this->assertSame(2, substr_count($page->body, 'name="allposts"'), 'one "include private posts" box for each form');
        // The archive generator, /admin/export/generate/, has a template for its form and for the download link
        // (admin/export/generate, admin/export/download), and no page draws either.
        $this->assertStringNotContainsString('admin/export/generate', $page->body);
    }

    public function testTodayTheExportPageHasNoLinkToAnArchiveThatHasBeenMade(): void
    {
        // The mail "Your data export is ready" links to this page, which draws nothing from the settings
        // the generator records (export_file_id, export_filename). The next change must flip this to the
        // test below.
        $this->rememberConfig();
        $config = Idno::site()->config();
        $config->export_file_id = 'abcdefabcdefabcdefabcdefabcdefab';
        $config->export_filename = 'example_zip_name.zip';
        $config->export_last_requested = time();
        $config->save();
        $cookie = $this->logIn($this->admin());

        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie]);

        $this->assertSame(200, $page->status);
        $this->assertStringNotContainsString('abcdefabcdefabcdefabcdefabcdefab', $page->body);
        $this->assertStringNotContainsString('example_zip_name.zip', $page->body);
    }

    public function testTheExportPageLinksToTheArchiveThatHasBeenMade(): void
    {
        $this->markTestIncomplete('Pages\Admin\Export draws admin/export only; templates/default/admin/export/{generate,download,wait}.tpl.php are never drawn, so there is no "Create archive" button and no link to the archive');

        $this->rememberConfig();
        $config = Idno::site()->config();
        $config->export_file_id = 'abcdefabcdefabcdefabcdefabcdefab';
        $config->export_filename = 'example_zip_name.zip';
        $config->export_last_requested = time();
        $config->save();
        $cookie = $this->logIn($this->admin());

        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie]);

        $this->assertStringContainsString('file/abcdefabcdefabcdefabcdefabcdefab/example_zip_name.zip', $page->body);
        $this->assertStringContainsString('admin/export/generate', $page->body);
    }

    public function testAnAdminSeesTheImportPageWithAFormForEachSourceIncludingOneTheImportDoesNotHandle(): void
    {
        $cookie = $this->logIn($this->admin());

        $page = $this->site()->request('GET', 'admin/import/', ['Cookie' => $cookie]);

        $this->assertSame(200, $page->status, substr($page->body, 0, 1500));
        $this->assertSame(3, substr_count($page->body, 'action="' . $this->site()->baseUrl() . 'admin/import/"'));
        $this->assertSame(3, substr_count($page->body, 'enctype="multipart/form-data"'));
        $this->assertSame(3, substr_count($page->body, 'name="import" '), 'a file field in each');
        foreach (['Known', 'WordPress', 'Blogger'] as $type) {
            $this->assertStringContainsString('<input type="hidden" name="import_type" value="' . $type . '">', $page->body, $type);
        }
    }

    public function testAnAdminsPostsToTheArchiveAndImportPagesWithoutATokenDoNothing(): void
    {
        $cookie = $this->logIn($this->admin());
        RecordingMailTransport::drain();

        foreach (['admin/export/generate/', 'admin/import/'] as $path) {
            $response = $this->site()->request('POST', $path, ['Cookie' => $cookie], ['import_type' => 'WordPress']);

            $this->assertSame(302, $response->status, $path);
        }
        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie]);
        $this->assertStringContainsString('Invalid token.', $page->body);
        $this->assertArrayNotHasKey('export_in_progress', $this->storedConfig(), 'the archive was not started');
        $this->assertSame([], RecordingMailTransport::drain(), 'and no mail was sent');
    }

    // ------------------------------------------------------------------ the downloads

    public function testAnAdminsRssDownloadIsAnAttachmentHoldingTheSitesPublicPosts(): void
    {
        $public = $this->createArticle($this->admin(), 'Admin download: public', '<p>Public body</p>');
        $before = $this->temporaryDirectories();
        $cookie = $this->logIn($this->admin());
        $token = $this->formToken($this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie])->body, 'admin/export/rss');

        $response = $this->site()->request('POST', 'admin/export/rss', ['Cookie' => $cookie], $token);
        $this->leftBehind = $this->newTemporaryDirectories($before);

        $this->assertSame(200, $response->status, substr($response->body, 0, 1500));
        $this->assertStringStartsWith('text/rss', (string) $response->header('Content-Type'), 'not application/rss+xml');
        $this->assertSame('attachment; filename=export.rss', $response->header('Content-Disposition'));
        $this->assertSame((string) strlen($response->body), $response->header('Content-Length'));
        $dom = $this->wellFormed($response->body);
        $this->assertStringContainsString('<guid>' . $public->getUUID() . '</guid>', $response->body);
        $this->assertSame(0.0, (new \DOMXPath($dom))->evaluate('count(//channel/*[local-name()="wxr_version"])'));
    }

    public function testAnAdminsWxrDownloadIsAnXmlAttachmentWithWordPressElements(): void
    {
        $public = $this->createArticle($this->admin(), 'Admin download: wxr', '<p>Public body</p>');
        $before = $this->temporaryDirectories();
        $cookie = $this->logIn($this->admin());
        $token = $this->formToken($this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie])->body, 'admin/export/wxr');

        $response = $this->site()->request('POST', 'admin/export/wxr', ['Cookie' => $cookie], $token);
        $this->leftBehind = $this->newTemporaryDirectories($before);

        $this->assertSame(200, $response->status, substr($response->body, 0, 1500));
        $this->assertStringStartsWith('application/xml', (string) $response->header('Content-Type'));
        $this->assertSame('attachment; filename=export.xml', $response->header('Content-Disposition'));
        $this->wellFormed($response->body);
        $this->assertStringContainsString('<wp:wxr_version>1.2</wp:wxr_version>', $response->body);
        $this->assertStringContainsString('<guid>' . $public->getUUID() . '</guid>', $response->body);
        $this->assertStringContainsString('<content:encoded>', $response->body);
    }

    public function testTodayAnAdminsDownloadHoldsPrivatePostsWhetherOrNotTheyAskedForThem(): void
    {
        // The box "Include private posts?" decides nothing for an administrator: they read every post.
        // See FeedExportTest::testTodayAnAdminsExportThatHidesPrivatePostsStillHoldsThem. The next change
        // must flip this.
        $private = $this->privateStatus($this->admin(), 'The private note in the admin download');
        $before = $this->temporaryDirectories();
        $cookie = $this->logIn($this->admin());
        $token = $this->formToken($this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie])->body, 'admin/export/rss');

        $without = $this->site()->request('POST', 'admin/export/rss', ['Cookie' => $cookie], $token);
        $with = $this->site()->request('POST', 'admin/export/rss', ['Cookie' => $cookie], $token + ['allposts' => '1']);
        $this->leftBehind = $this->newTemporaryDirectories($before);

        foreach ([$without, $with] as $response) {
            $this->assertSame(200, $response->status);
            $this->assertStringContainsString('<guid>' . $private->getUUID() . '</guid>', $response->body);
        }
    }

    public function testAMembersAccountExportHoldsTheirPublicPostsAndNoOneElses(): void
    {
        $member = $this->user();
        $mine = $this->createArticle($member, 'Member export: mine', '<p>Mine</p>');
        $mineHidden = $this->privateArticle($member, 'Member export: private', '<p>Mine, private</p>');
        $others = $this->createArticle($this->admin(), 'Member export: the admin\'s', '<p>Not mine</p>');
        $before = $this->temporaryDirectories();
        $cookie = $this->logIn($member);
        $token = $this->formToken($this->site()->request('GET', 'account/export/', ['Cookie' => $cookie])->body, 'account/export/rss');

        $rss = $this->site()->request('POST', 'account/export/rss', ['Cookie' => $cookie], $token + ['allposts' => '1']);
        $wxr = $this->site()->request('POST', 'account/export/wxr', ['Cookie' => $cookie], $token);
        $this->leftBehind = $this->newTemporaryDirectories($before);

        $this->assertSame(200, $rss->status, substr($rss->body, 0, 1500));
        $this->assertStringStartsWith('text/rss', (string) $rss->header('Content-Type'));
        $this->assertSame('attachment; filename=user_export.rss', $rss->header('Content-Disposition'));
        $this->assertStringContainsString($mine->getUUID(), $rss->body);
        $this->assertStringContainsString($mineHidden->getUUID(), $rss->body, 'allposts=1 includes their private post');
        $this->assertStringNotContainsString($others->getUUID(), $rss->body);

        $this->assertSame(200, $wxr->status);
        $this->assertSame('attachment; filename=user_export.xml', $wxr->header('Content-Disposition'));
        $this->assertStringContainsString($mine->getUUID(), $wxr->body);
        $this->assertStringNotContainsString($mineHidden->getUUID(), $wxr->body, 'no allposts: only the public ones');
        $this->assertStringNotContainsString($others->getUUID(), $wxr->body);
        $this->assertStringContainsString('<wp:wxr_version>1.2</wp:wxr_version>', $wxr->body);
    }

    public function testTodayTheIncludePrivatePostsBoxOfTheAccountExportSendsZero(): void
    {
        // The box's value is "0" in the default theme's templates/default/account/export.tpl.php (the rig serves
        // it; Twenty26's sends 1): ticking it posts allposts=0, which the page reads as false, so a member can
        // never include their private posts from the page. The next change must flip this to the test below.
        $member = $this->user();
        $mineHidden = $this->privateArticle($member, 'Member export box: private', '<p>Mine, private</p>');
        $before = $this->temporaryDirectories();
        $cookie = $this->logIn($member);
        $page = $this->site()->request('GET', 'account/export/', ['Cookie' => $cookie]);
        $token = $this->formToken($page->body, 'account/export/rss');

        $this->assertSame(2, preg_match_all('~<input type="checkbox"[^>]* name="allposts"[^>]* value="0"~', $page->body), 'the box of the RSS form and the box of the WXR form');
        $ticked = $this->site()->request('POST', 'account/export/rss', ['Cookie' => $cookie], $token + ['allposts' => '0']);
        $this->leftBehind = $this->newTemporaryDirectories($before);

        $this->assertSame(200, $ticked->status);
        $this->assertStringNotContainsString($mineHidden->getUUID(), $ticked->body);
    }

    public function testTheIncludePrivatePostsBoxOfTheAccountExportIncludesPrivatePostsWhenTicked(): void
    {
        $this->markTestIncomplete('templates/default/account/export.tpl.php (the default theme; Twenty26\'s sends 1) gives the allposts checkboxes value="0", and the page reads allposts as a boolean (Pages\Account\Export\RSS and WXR), so ticking the box includes nothing more');

        $page = $this->site()->request('GET', 'account/export/', ['Cookie' => $this->logIn($this->user())]);

        $this->assertSame(2, preg_match_all('~<input type="checkbox"[^>]* name="allposts"[^>]* value="1"~', $page->body));
    }

    // ------------------------------------------------------------------ who can fetch an archive

    /**
     * Stores a small zip file in the site's file store exactly as Pages\Admin\Export\Generate does
     * (File::createFromFile($path, $host . '.zip'), no entity attached) and returns its file id.
     *
     * @return array{id: string, bytes: string}
     */
    private function storedArchive(): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'idno-portability-');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('known.json', '{"url":"http://example.test/"}');
        $zip->addFromString('config.json', '{"config":{"site_secret":"not-a-real-secret"}}');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        $id = File::createFromFile($path, Idno::site()->config()->host . '.zip');
        @unlink($path);
        $this->assertNotFalse($id);
        $this->registerFile((string) $id);

        return ['id' => (string) $id, 'bytes' => $bytes];
    }

    /** @var list<string> */
    private array $files = [];

    private function registerFile(string $id): void
    {
        $this->files[] = $id;
    }

    public function testAStoredArchiveHasNoEntityAttachedToIt(): void
    {
        $archive = $this->storedArchive();

        $file = File::getByID($archive['id']);
        $this->assertNotFalse($file);
        $this->assertArrayNotHasKey('attached_entity', $file->file, 'the file route checks access only for a file that has one');
        $this->assertSame('application/octet-stream', $file->file['mime_type']);
    }

    public function testTodayAGeneratedArchiveIsServedToAnonymousVisitorsAndMembersWhoKnowItsFileId(): void
    {
        // Pages\File\View checks access only when a file has attached_entity (View.php, getContent()); the
        // archive has none. Whoever has the address of /file/<id>/<name> gets the archive (the data of the
        // whole site: its config.json holds the site's secrets). The next change must flip this to the test
        // below.
        $archive = $this->storedArchive();
        $name = Idno::site()->config()->host . '.zip';

        foreach (['anonymous' => [], 'member' => ['Cookie' => $this->logIn($this->user())], 'admin' => ['Cookie' => $this->logIn($this->admin())]] as $who => $headers) {
            $response = $this->site()->request('GET', 'file/' . $archive['id'] . '/' . $name, $headers);

            $this->assertSame(200, $response->status, $who);
            $this->assertSame($archive['bytes'], $response->body, $who);
            $this->assertSame('application/octet-stream', $response->header('Content-Type'), $who);
            $this->assertStringStartsWith('attachment; filename="', (string) $response->header('Content-Disposition'), $who);
        }
    }

    public function testAGeneratedArchiveIsServedToAdminsOnly(): void
    {
        $this->markTestIncomplete('The archive is stored with File::createFromFile() and no attached_entity (Pages\Admin\Export\Generate), and Pages\File\View checks access only for a file that has one, so anyone with /file/<id>/<name> downloads it');

        $archive = $this->storedArchive();
        $name = Idno::site()->config()->host . '.zip';

        $anonymous = $this->site()->request('GET', 'file/' . $archive['id'] . '/' . $name);
        $member = $this->site()->request('GET', 'file/' . $archive['id'] . '/' . $name, ['Cookie' => $this->logIn($this->user())]);
        $admin = $this->site()->request('GET', 'file/' . $archive['id'] . '/' . $name, ['Cookie' => $this->logIn($this->admin())]);

        $this->assertContains($anonymous->status, [401, 403]);
        $this->assertContains($member->status, [401, 403]);
        $this->assertSame(200, $admin->status);
    }

    private function cleanFiles(): void
    {
        foreach ($this->files as $id) {
            $file = File::getByID($id);
            if (is_object($file)) {
                $file->remove();
            }
        }
        $this->files = [];
    }

    // ------------------------------------------------------------------ the work done after the answer

    public function testTheImportPageAnswersAtOnceAndMailsTheAdminWhenItIsDone(): void
    {
        $scenario = $this->scenario();

        $this->assertSame(302, $scenario['import']['status']);
        $this->assertSame($this->site()->baseUrl() . 'admin/import/', $scenario['import']['location']);
        $this->assertLessThan(5.0, $scenario['import']['seconds'], 'the page answers before its ten seconds of waiting');

        $mail = $scenario['mails']['importer'];
        $this->assertSame('Idno - Your data import is complete', $mail['subject']);
        $this->assertSame('Your data import is done!', trim(strtok($mail['text'], "\n")));
        $this->assertStringContainsString('Visit your site: ' . $this->site()->baseUrl(), $mail['text']);
        $this->assertStringContainsString('Enjoy!', $mail['text']);
        $this->assertNotEmpty($scenario['imported'], 'the post of the file was created');
        $this->assertSame($scenario['importerUuid'], $scenario['imported']['owner'], 'owned by the administrator who ran the import');
    }

    public function testTodayTheKnownImportTypeIsAnsweredWithTheFailureMailAndImportsNothing(): void
    {
        // The form offers "Idno" (import_type Known) and Pages\Admin\Import has a case for blogger and wordpress
        // only (Import.php:63-71), so the file is never read: the failure mail, nothing imported. The next
        // change must flip this to the test below.
        $scenario = $this->scenario();

        $this->assertSame(302, $scenario['known']['status']);
        $this->assertSame('Idno - Problem with your import', $scenario['mails']['known']['subject']);
        $this->assertStringContainsString('Sorry, your data import was unsuccessful.', $scenario['mails']['known']['text']);
        $this->assertSame([], $scenario['knownImported']);
    }

    public function testTheKnownImportTypeImportsAnIdnoRssFile(): void
    {
        $this->markTestIncomplete('Pages\Admin\Import has no case for import_type=Known (Import.php:63-71) although the page offers it; Migration::importFeedXML() reads Idno\'s RSS (ImportTest) but nothing calls it for this type');

        $scenario = $this->scenario();

        $this->assertSame('Idno - Your data import is complete', $scenario['mails']['known']['subject']);
        $this->assertSame(['Scenario known import: one post'], $scenario['knownImported']);
    }

    public function testTheArchivePageAnswersAtOnceAndMailsTheAdminAPointerToTheExportPage(): void
    {
        $scenario = $this->scenario();

        $this->assertSame(302, $scenario['generate']['status']);
        $this->assertSame($this->site()->baseUrl() . 'admin/export/', $scenario['generate']['location']);
        $this->assertLessThan(5.0, $scenario['generate']['seconds']);

        $mail = $scenario['mails']['exporter'];
        $this->assertSame('Your data export is ready', $mail['subject']);
        $this->assertStringContainsString('Get your data: ' . $this->site()->baseUrl() . 'admin/export/', $mail['text'], 'the mail links to the export page, not to the archive');
        $this->assertStringNotContainsString('/file/', $mail['text']);
        $this->assertStringContainsString('href="' . $this->site()->baseUrl() . 'admin/export/"', $mail['html']);
    }

    public function testTheArchivePageRecordsTheArchiveInTheSettings(): void
    {
        $recorded = $this->scenario()['recorded'];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $recorded['export_file_id']);
        $this->assertSame(Idno::site()->config()->host . '.zip', $recorded['export_filename']);
        $this->assertSame(0, $recorded['export_in_progress']);
        $this->assertEqualsWithDelta(time(), (int) $recorded['export_last_requested'], 120);
    }

    public function testTheArchiveThePageMadeIsAZipOfTheSite(): void
    {
        $archive = $this->scenario()['archive'];

        $this->assertSame("PK\x03\x04", substr($archive['bytes'], 0, 4));
        $this->assertContains('known.json', $archive['entries']);
        $this->assertContains('config.json', $archive['entries']);
        $this->assertContains('entities.json', $archive['entries']);
        $this->assertContains('exported_data.sql', $archive['entries']);
        $this->assertSame(Idno::site()->config()->getURL(), $archive['known']['url']);
    }

    public function testTodayTheArchiveThePageMadeIsServedToAnAnonymousVisitor(): void
    {
        // The same as testTodayAGeneratedArchiveIsServedToAnonymousVisitorsAndMembersWhoKnowItsFileId(), on
        // the archive the page really made: no login, no token, only the file id.
        $archive = $this->scenario()['archive'];

        $this->assertSame(200, $archive['anonymousStatus']);
        $this->assertSame('application/octet-stream', $archive['anonymousContentType']);
        $this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $archive['id']));
        $this->assertNotEmpty($archive['config'], 'config.json is in what an anonymous visitor downloads');
    }

    public function testNoStaleArchiveIsLeftInTheTemporaryDirectoryByThePage(): void
    {
        $this->assertFalse($this->scenario()['zipLeftBehind'], 'the page removes the zip after storing it');
    }

    /**
     * Runs the import page and the archive page once, as two administrators at the same time, and reads
     * what they did. The requests answer at once; the work is done ten seconds later and ends in a mail
     * to each, which this waits for.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        if (self::$scenario !== null) {
            return self::$scenario;
        }
        $this->rememberConfig();
        $config = Idno::site()->config();
        $config->from_email = 'site@example.com';
        $config->save();
        RecordingMailTransport::drain();

        $importer = $this->adminNamed('portability-importer');
        $exporter = $this->adminNamed('portability-exporter');
        $knowner = $this->adminNamed('portability-known');
        $importerCookie = $this->logIn($importer);
        $exporterCookie = $this->logIn($exporter);
        $knownCookie = $this->logIn($knowner);

        $wordpress = '<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>Inline</title>'
            . '<item><title>Scenario import: one post</title><wp:post_date>2024-02-10 08:30:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type><content:encoded><![CDATA[<p>The only post.</p>]]></content:encoded></item></channel></rss>';
        $page = $this->site()->request('GET', 'admin/import/', ['Cookie' => $importerCookie]);
        [$boundary, $body] = $this->multipart($this->formToken($page->body, 'admin/import/') + ['import_type' => 'WordPress'], ['scenario.xml', $wordpress]);
        $started = microtime(true);
        $import = $this->site()->request('POST', 'admin/import/', ['Cookie' => $importerCookie, 'Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body);
        $importSeconds = microtime(true) - $started;

        // The import type the form offers as "Idno": an RSS file as Idno exports it.
        $rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>An Idno site</title><item><title>Scenario known import: one post</title><link>https://idno.example/2024/one</link><guid>https://idno.example/view/1</guid><pubDate>Sat, 10 Feb 2024 08:30:00 +0000</pubDate><description><![CDATA[<p>The only post.</p>]]></description></item></channel></rss>';
        $page = $this->site()->request('GET', 'admin/import/', ['Cookie' => $knownCookie]);
        [$boundary, $body] = $this->multipart($this->formToken($page->body, 'admin/import/') + ['import_type' => 'Known'], ['export.rss', $rss]);
        $known = $this->site()->request('POST', 'admin/import/', ['Cookie' => $knownCookie, 'Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body);

        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $exporterCookie]);
        $started = microtime(true);
        $generate = $this->site()->request('POST', 'admin/export/generate/', ['Cookie' => $exporterCookie], $this->formToken($page->body, 'admin/export/rss'));
        $generateSeconds = microtime(true) - $started;

        // The pages are asleep now (ten seconds each, on three of the four workers); the mails come after.
        $mails = [];
        $deadline = microtime(true) + 45;
        while (count($mails) < 3 && microtime(true) < $deadline) {
            usleep(250_000);
            foreach (RecordingMailTransport::drain() as $mail) {
                $mails[$mail['to'][0]['address']] = $mail;
            }
        }

        $recorded = Idno::site()->db()->getAnyRecord('config');
        $archive = ['id' => (string) ($recorded['export_file_id'] ?? '')];
        if ($archive['id'] !== '') {
            $response = $this->site()->request('GET', 'file/' . $archive['id'] . '/x.zip');
            $archive['anonymousStatus'] = $response->status;
            $archive['anonymousContentType'] = $response->header('Content-Type');
            $archive['bytes'] = $response->body;
            $zip = new \ZipArchive();
            $path = (string) tempnam(sys_get_temp_dir(), 'idno-portability-');
            file_put_contents($path, $response->body);
            $archive['entries'] = [];
            if ($zip->open($path) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $archive['entries'][] = (string) $zip->getNameIndex($i);
                }
                $archive['known'] = json_decode((string) $zip->getFromName('known.json'), true);
                $archive['config'] = json_decode((string) $zip->getFromName('config.json'), true);
                $zip->close();
            }
            @unlink($path);
            $file = File::getByID($archive['id']);
            if (is_object($file)) {
                $file->remove();
            }
        }

        $imported = [];
        foreach (Entry::get(['title' => 'Scenario import: one post'], [], 10) as $entry) {
            $imported = ['owner' => $entry->getOwnerID()];
            $entry->delete();
        }
        $knownImported = [];
        foreach (Entry::get(['title' => 'Scenario known import: one post'], [], 10) as $entry) {
            $knownImported[] = $entry->title;
            $entry->delete();
        }

        return self::$scenario = [
            'import' => ['status' => $import->status, 'location' => $import->header('Location'), 'seconds' => $importSeconds],
            'generate' => ['status' => $generate->status, 'location' => $generate->header('Location'), 'seconds' => $generateSeconds],
            'known' => ['status' => $known->status, 'location' => $known->header('Location')],
            'knownImported' => $knownImported,
            'mails' => [
                'importer' => $mails[$importer->email] ?? $this->failScenario($mails, 'the import mail'),
                'exporter' => $mails[$exporter->email] ?? $this->failScenario($mails, 'the export mail'),
                'known' => $mails[$knowner->email] ?? $this->failScenario($mails, 'the mail of the Known import'),
            ],
            'recorded' => $recorded,
            'archive' => $archive + ['bytes' => '', 'entries' => [], 'known' => [], 'config' => [], 'anonymousStatus' => 0, 'anonymousContentType' => null],
            'imported' => $imported,
            'importerUuid' => $importer->getUUID(),
            'zipLeftBehind' => is_file(Idno::site()->config()->getTempDir() . str_replace('.', '_', (string) Idno::site()->config()->host) . '.zip'),
        ];
    }

    /**
     * @param array<string, mixed> $mails
     */
    private function failScenario(array $mails, string $what): never
    {
        $this->fail('No ' . $what . ' arrived within 45 seconds. Mails: ' . json_encode(array_keys($mails)) . ' (the web server\'s log is Tests/rig/.server.log)');
    }

    // ------------------------------------------------------------------ helpers

    private function rememberConfig(): void
    {
        if ($this->savedConfig === null) {
            $config = Idno::site()->config()->config;
            $this->savedConfig = [];
            foreach (['from_email', 'export_file_id', 'export_filename', 'export_in_progress', 'export_last_requested'] as $key) {
                if (array_key_exists($key, $config)) {
                    $this->savedConfig[$key] = $config[$key];
                }
            }
        }
    }

    /**
     * @return array<string, mixed> the stored configuration, as the web server reads it
     */
    private function storedConfig(): array
    {
        return (array) Idno::site()->db()->getAnyRecord('config');
    }

    private function adminNamed(string $handle): User
    {
        $user = new User();
        $user->handle = $handle;
        $user->email = $handle . '@example.test';
        $user->setTitle(ucfirst($handle));
        $user->setAdmin(true);
        $user->setPassword(md5((string) mt_rand()));
        $this->registerFixture($user);
        $user->save(true);

        return $user;
    }

    /**
     * @param array<string, string>        $fields
     * @param array{0: string, 1: string}|null $file name and contents of the "import" file
     *
     * @return array{0: string, 1: string} the boundary and the body of a multipart/form-data request
     */
    private function multipart(array $fields, ?array $file): array
    {
        $boundary = 'idno' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        if ($file !== null) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"import\"; filename=\"{$file[0]}\"\r\nContent-Type: text/xml\r\n\r\n{$file[1]}\r\n";
        }

        return [$boundary, $body . "--$boundary--\r\n"];
    }

    private function wellFormed(string $xml): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertTrue($loaded, 'well-formed XML');

        return $document;
    }

    /**
     * @return list<string> the hash-named directories in the temporary directory
     */
    private function temporaryDirectories(): array
    {
        return glob(rtrim(Idno::site()->config()->getTempDir(), '/') . '/????????????????????????????????', GLOB_ONLYDIR) ?: [];
    }

    /**
     * @param list<string> $before
     *
     * @return list<string> the directories made since, which hold an export (rss/ or known.json)
     */
    private function newTemporaryDirectories(array $before): array
    {
        return array_values(array_filter(
            array_diff($this->temporaryDirectories(), $before),
            static fn (string $directory): bool => is_dir($directory . '/rss') || is_file($directory . '/known.json')
        ));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                is_dir($path . '/' . $name) ? $this->removeDirectory($path . '/' . $name) : @unlink($path . '/' . $name);
            }
        }
        @rmdir($path);
    }

    private function logIn(User $user): string
    {
        $password = bin2hex(random_bytes(12));
        $user->setPassword($password);
        $user->save(true);

        $form = $this->site()->request('GET', 'session/login');
        $cookie = $this->sessionCookie($form) ?? '';
        $this->assertNotSame('', $cookie, 'the login form starts a session');
        $login = $this->site()->request(
            'POST',
            'session/login',
            ['Cookie' => $cookie],
            $this->formToken($form->body, 'session/login') + ['email' => $user->handle, 'password' => $password],
        );
        $this->assertSame(302, $login->status, substr($login->body, 0, 2000));
        $cookie = $this->sessionCookie($login) ?? $cookie;

        $check = $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie]);
        $this->assertSame(200, $check->status, 'logged in');

        return $cookie;
    }

    private function sessionCookie(SiteResponse $response): ?string
    {
        $name = (string) Idno::site()->config()->sessionname;
        if (preg_match_all('/(?:^|[,;]\s*)' . preg_quote($name, '/') . '=([^;,\s]+)/', (string) $response->header('Set-Cookie'), $m) > 0) {
            return $name . '=' . end($m[1]);
        }

        return null;
    }

    /**
     * The CSRF token fields of the form in $html whose action ends with $action.
     *
     * @return array<string, string>
     */
    private function formToken(string $html, string $action): array
    {
        $forms = preg_split('/<form\b/i', $html) ?: [];
        foreach ($forms as $form) {
            if (preg_match('/^[^>]*action="[^"]*' . preg_quote($action, '/') . '"/', $form) !== 1) {
                continue;
            }
            $fields = [];
            foreach (['__bTs', '__bTk', '__bTa'] as $field) {
                if (preg_match('/name="' . $field . '" value="([^"]*)"/', $form, $m) === 1) {
                    $fields[$field] = html_entity_decode($m[1], ENT_QUOTES);
                }
            }
            if (count($fields) === 3) {
                return $fields;
            }
        }
        $this->fail('No signed form posting to ' . $action . ' in the page.');
    }
}
