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
 * The import page and the archive page answer at once and do their work after the answer (the rig's queue is
 * synchronous; with the asynchronous one the work is queued: QueuedPortabilityTest), then mail the admin.
 * Those are driven once, together, by scenario(): the requests are made and the mails waited for (at most 45
 * seconds) in the record the test container's mail transport keeps, which is what /_test/mail/log serves.
 * The rest answer at once.
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
            foreach (['from_email', 'export_file_id', 'export_filename', 'export_in_progress', 'export_last_requested', 'export_file_created', 'portability_last_sweep'] as $key) {
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
        $flag = $this->storedConfig()['export_in_progress'] ?? null; // whatever an earlier test left
        foreach (['admin/export/rss', 'admin/export/wxr', 'admin/export/generate/', 'admin/import/', 'account/export/rss', 'account/export/wxr'] as $path) {
            $response = $this->site()->request('POST', $path, [], ['allposts' => '1']);

            $this->assertSame(302, $response->status, $path);
            $this->assertSame($this->site()->baseUrl(), $response->header('Location'), $path);
            $this->assertStringNotContainsString('<rss', $response->body, $path);
        }
        $this->assertSame($flag, $this->storedConfig()['export_in_progress'] ?? null, 'nothing was started');
    }

    public function testAMemberIsRefusedTheAdminPagesEvenWithAValidToken(): void
    {
        $flag = $this->storedConfig()['export_in_progress'] ?? null; // whatever an earlier test left
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
        $this->assertSame($flag, $this->storedConfig()['export_in_progress'] ?? null, 'nothing was started');
    }

    public function testABearerTokenDoesNotReachTheExportAndImportPagesWithoutAFormToken(): void
    {
        // The plugin that takes IndieAuth tokens also takes the first administrator's API key as one.
        // A token authenticates at the Micropub endpoint only, so on these pages it is no credential
        // at all, whether it comes alone or beside the administrator's own session.
        $flag = $this->storedConfig()['export_in_progress'] ?? null; // whatever an earlier test left
        $admin = $this->admin();
        $bearer = ['Authorization' => 'Bearer ' . $admin->getAPIkey()];
        RecordingMailTransport::drain();

        foreach (['admin/export/rss', 'admin/export/wxr', 'admin/export/generate/', 'admin/import/'] as $path) {
            $alone = $this->site()->request('POST', $path, $bearer, ['allposts' => '1', 'import_type' => 'WordPress']);
            $this->assertSame(302, $alone->status, $path);
            $this->assertSame($this->site()->baseUrl(), $alone->header('Location'), $path);
            $this->assertStringNotContainsString('<rss', $alone->body, $path);
        }
        $this->assertSame(403, $this->site()->request('GET', 'admin/export/', $bearer)->status, 'a token opens no admin page');

        $cookie = $this->logIn($admin);
        foreach (['admin/export/generate/', 'admin/import/'] as $path) {
            $this->assertSame(302, $this->site()->request('POST', $path, $bearer + ['Cookie' => $cookie], ['import_type' => 'WordPress'])->status, $path);
        }
        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie]);
        $this->assertStringContainsString('Invalid token.', $page->body, 'a token does not stand in for the form token');
        $this->assertSame($flag, $this->storedConfig()['export_in_progress'] ?? null, 'nothing was started');
        $this->assertSame([], RecordingMailTransport::drain(), 'and no mail was sent');
    }

    public function testAnAdminSeesTheExportPageWithAnRssFormAndAWxrFormAndTheFullArchive(): void
    {
        $cookie = $this->logIn($this->admin());

        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie]);

        $this->assertSame(200, $page->status, substr($page->body, 0, 1500));
        $this->assertStringContainsString('action="' . $this->site()->baseUrl() . 'admin/export/rss"', $page->body);
        $this->assertStringContainsString('action="' . $this->site()->baseUrl() . 'admin/export/wxr"', $page->body);
        $this->assertSame(2, substr_count($page->body, 'name="allposts"'), 'one "include private posts" box for each form');
        // The archive: a button that makes one, and what it holds said plainly next to it.
        $this->assertStringContainsString('action="' . $this->site()->baseUrl() . 'admin/export/generate/"', $page->body);
        $this->assertStringContainsString('>Create archive</button>', $page->body);
        $this->assertStringContainsString("every account's password hash", $page->body);
        $this->assertStringContainsString('as carefully as a database backup', $page->body);
        $this->assertStringContainsString('after a week', $page->body);
        $this->assertStringContainsString("includes every member's private posts", $page->body, 'what an administrator\'s export holds');
        $this->assertStringNotContainsString('file/', substr($page->body, strpos($page->body, 'Full archive') ?: 0), 'there is no archive to download yet');
    }

    public function testTheExportPageLinksToTheArchiveThatHasBeenMade(): void
    {
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
        $this->assertStringContainsString('>Re-archive your data</button>', $page->body);
    }

    public function testWhileAnExportRunsThePageSaysSoAndOffersNoSecondOne(): void
    {
        $this->rememberConfig();
        $config = Idno::site()->config();
        $config->export_in_progress = 1;
        $config->export_last_requested = time();
        $config->save();
        $cookie = $this->logIn($this->admin());

        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie]);

        $this->assertStringContainsString('is being created', $page->body);
        $this->assertStringNotContainsString('action="' . $this->site()->baseUrl() . 'admin/export/generate/"', $page->body);
    }

    public function testASecondExportIsRefusedWhileOneRunsAndAnOldFlagIsIgnored(): void
    {
        $this->rememberConfig();
        $config = Idno::site()->config();
        $config->from_email = 'site@example.com';
        $config->export_in_progress = 1;
        $config->export_last_requested = time() - 600;
        $config->save();
        $cookie = $this->logIn($this->admin());
        RecordingMailTransport::drain();
        $token = $this->formToken($this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie])->body, 'admin/export/rss');

        $refused = $this->site()->request('POST', 'admin/export/generate/', ['Cookie' => $cookie], $token);

        $this->assertSame(302, $refused->status);
        $this->assertStringContainsString('An export is already being made', $this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie])->body);
        $this->assertEqualsWithDelta(time() - 600, (int) $this->storedConfig()['export_last_requested'], 120, 'nothing was changed');
        sleep(1);
        $this->assertSame([], RecordingMailTransport::drain(), 'nothing ran');

        // A flag older than six hours is a job that died: ignored.
        $config = Idno::site()->config();
        $config->export_last_requested = time() - 7 * 3600;
        $config->save();
        $token = $this->formToken($this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie])->body, 'admin/export/rss');
        $started = $this->site()->request('POST', 'admin/export/generate/', ['Cookie' => $cookie], $token);
        $this->assertSame(302, $started->status);
        $this->waitForMails(1);
        $recorded = $this->storedConfig();
        $this->assertSame(0, $recorded['export_in_progress'], 'cleared when it ended');
        $this->removeRecordedArchive($recorded);
    }

    public function testAnArchiveAWeekOldIsRemovedWhenTheExportPageIsLoaded(): void
    {
        $this->rememberConfig();
        $old = $this->storedArchive();
        $recent = $this->storedArchive();
        $config = Idno::site()->config();
        $config->export_file_id = $old['id'];
        $config->export_filename = 'old.zip';
        $config->export_last_requested = time() - 8 * 86400;
        $config->save();
        $cookie = $this->logIn($this->admin());

        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie]);

        $this->assertStringNotContainsString($old['id'], $page->body, 'no link to it');
        $this->assertArrayNotHasKey('export_file_id', array_filter($this->storedConfig()), 'forgotten');
        $this->assertFalse(File::getByID($old['id']), 'and gone from the file store');
        $this->assertNotFalse(File::getByID($recent['id']), 'a file the page knows nothing of stays');
    }

    public function testAnArchiveLessThanAWeekOldStays(): void
    {
        $this->rememberConfig();
        $kept = $this->storedArchive();
        $config = Idno::site()->config();
        $config->export_file_id = $kept['id'];
        $config->export_filename = 'kept.zip';
        $config->export_last_requested = time() - 6 * 86400;
        $config->save();
        $cookie = $this->logIn($this->admin());

        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie]);

        $this->assertStringContainsString('file/' . $kept['id'] . '/kept.zip', $page->body);
        $this->assertNotFalse(File::getByID($kept['id']));
    }

    public function testTheDiagnosticsReportHidesWhatTheArchiveHides(): void
    {
        $this->rememberConfig();
        $config = Idno::site()->config();
        $config->smtp_password = 'sentinel-smtp-password';
        $config->aws_secret = 'sentinel-aws-secret';
        $config->save();
        $cookie = $this->logIn($this->admin());

        $report = $this->site()->request('GET', 'admin/diagnostics/', ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest']);

        $this->assertSame(200, $report->status, substr($report->body, 0, 500));
        $this->assertStringContainsString('Running config', $report->body);
        $this->assertStringContainsString('** REDACTED **', $report->body);
        $this->assertStringNotContainsString('sentinel-smtp-password', $report->body);
        $this->assertStringNotContainsString('sentinel-aws-secret', $report->body);
        $this->assertStringNotContainsString((string) Idno::site()->config()->site_secret, $report->body);
        $this->forgetSettings(['smtp_password', 'aws_secret']);
    }

    public function testTheDiagnosticsReportDoesNotDumpTheRequestsOrTheServersSecrets(): void
    {
        $cookie = $this->logIn($this->admin());
        $sessionValue = substr($cookie, (int) strpos($cookie, '=') + 1);
        $this->assertNotSame('', $sessionValue);

        $report = $this->site()->request('GET', 'admin/diagnostics/', ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest', 'X-Sentinel-Token' => 'sentinel-header-token']);

        $this->assertSame(200, $report->status);
        $this->assertStringContainsString('$_SERVER:', $report->body, 'the dump is still there');
        $this->assertStringContainsString('HTTP_HOST', $report->body, 'with what is no secret');
        $this->assertStringNotContainsString('sentinel-header-token', $report->body);
        $this->assertStringNotContainsString($sessionValue, $report->body, 'the session cookie is not in the report');
    }

    public function testAnAdminSeesTheImportPageWithAFormForEachSourceItHandles(): void
    {
        $cookie = $this->logIn($this->admin());

        $page = $this->site()->request('GET', 'admin/import/', ['Cookie' => $cookie]);

        $this->assertSame(200, $page->status, substr($page->body, 0, 1500));
        $this->assertSame(2, substr_count($page->body, 'action="' . $this->site()->baseUrl() . 'admin/import/"'));
        $this->assertSame(2, substr_count($page->body, 'enctype="multipart/form-data"'));
        $this->assertSame(2, substr_count($page->body, 'name="import" '), 'a file field in each');
        foreach (['WordPress', 'Blogger'] as $type) {
            $this->assertStringContainsString('<input type="hidden" name="import_type" value="' . $type . '">', $page->body, $type);
        }
        $this->assertStringNotContainsString('value="Known"', $page->body, 'the source the import never handled is not offered');
    }

    public function testAnAdminsPostsToTheArchiveAndImportPagesWithoutATokenDoNothing(): void
    {
        $flag = $this->storedConfig()['export_in_progress'] ?? null; // whatever an earlier test left
        $cookie = $this->logIn($this->admin());
        RecordingMailTransport::drain();

        foreach (['admin/export/generate/', 'admin/import/'] as $path) {
            $response = $this->site()->request('POST', $path, ['Cookie' => $cookie], ['import_type' => 'WordPress']);

            $this->assertSame(302, $response->status, $path);
        }
        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie]);
        $this->assertStringContainsString('Invalid token.', $page->body);
        $this->assertSame($flag, $this->storedConfig()['export_in_progress'] ?? null, 'the archive was not started');
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

    public function testAnAdminsDownloadHoldsPrivatePostsOnlyWhenTheyAskForThem(): void
    {
        $private = $this->privateStatus($this->admin(), 'The private note in the admin download');
        $before = $this->temporaryDirectories();
        $cookie = $this->logIn($this->admin());
        $token = $this->formToken($this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie])->body, 'admin/export/rss');

        $without = $this->site()->request('POST', 'admin/export/rss', ['Cookie' => $cookie], $token);
        $with = $this->site()->request('POST', 'admin/export/rss', ['Cookie' => $cookie], $token + ['allposts' => '1']);
        $this->leftBehind = $this->newTemporaryDirectories($before);

        $this->assertSame(200, $without->status);
        $this->assertStringNotContainsString($private->getUUID(), $without->body, 'hidden from an administrator too');
        $this->assertSame(200, $with->status);
        $this->assertStringContainsString('<guid>' . $private->getUUID() . '</guid>', $with->body);
        $this->assertStringContainsString('<wp:status>private</wp:status>', $with->body, 'and marked as private');
        $this->assertSame([], $this->leftBehind, 'no temporary directory is left behind');
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

    public function testTheIncludePrivatePostsBoxOfTheAccountExportIncludesPrivatePostsWhenTicked(): void
    {
        $member = $this->user();
        $mineHidden = $this->privateArticle($member, 'Member export box: private', '<p>Mine, private</p>');
        $before = $this->temporaryDirectories();
        $cookie = $this->logIn($member);
        $page = $this->site()->request('GET', 'account/export/', ['Cookie' => $cookie]);
        $token = $this->formToken($page->body, 'account/export/rss');

        // The box of the template (the rig serves it) sends 1 when ticked.
        $this->assertSame(2, preg_match_all('~<input type="checkbox"[^>]* name="allposts"[^>]* value="1"~', $page->body), 'the box of the RSS form and the box of the WXR form');
        $ticked = $this->site()->request('POST', 'account/export/rss', ['Cookie' => $cookie], $token + ['allposts' => '1']);
        $unticked = $this->site()->request('POST', 'account/export/rss', ['Cookie' => $cookie], $token);
        $this->leftBehind = $this->newTemporaryDirectories($before);

        $this->assertSame(200, $ticked->status);
        $this->assertStringContainsString($mineHidden->getUUID(), $ticked->body);
        $this->assertStringNotContainsString($mineHidden->getUUID(), $unticked->body);
    }

    // ------------------------------------------------------------------ who can fetch an archive

    /**
     * Stores a small zip file in the site's file store with the metadata the archive export gives its file
     * (a `restricted` of admin: Idno\Portability\ArchiveExporter::RESTRICTED_TO), and returns its file id.
     *
     * @param array<string, mixed> $metadata the metadata, instead of the export's
     *
     * @return array{id: string, bytes: string}
     */
    private function storedArchive(?array $metadata = null): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'idno-portability-');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('known.json', '{"url":"http://example.test/"}');
        $zip->addFromString('config.json', '{"config":{"title":"not a real site"}}');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        $stream = fopen($path, 'rb');
        $id = Idno::container()->get(\Idno\Storage\Files\FileStore::class)->store($stream, $metadata ?? [
            'filename' => Idno::site()->config()->host . '.zip',
            'mime_type' => 'application/zip',
            'restricted' => \Idno\Portability\ArchiveExporter::RESTRICTED_TO,
            'purpose' => 'export',
        ]);
        fclose($stream);
        @unlink($path);
        $this->registerFile($id);

        return ['id' => $id, 'bytes' => $bytes];
    }

    /** @var list<string> */
    private array $files = [];

    private function registerFile(string $id): void
    {
        $this->files[] = $id;
    }

    /**
     * What each kind of visitor gets for the file's address.
     *
     * @return array<string, SiteResponse>
     */
    private function fetchedBy(string $id, string $name = 'archive.zip'): array
    {
        $response = [];
        foreach (['anonymous' => [], 'member' => ['Cookie' => $this->logIn($this->user())], 'admin' => ['Cookie' => $this->logIn($this->admin())]] as $who => $headers) {
            $response[$who] = $this->site()->request('GET', 'file/' . $id . '/' . $name, $headers);
        }

        return $response;
    }

    public function testAGeneratedArchiveIsServedToAdminsOnly(): void
    {
        $archive = $this->storedArchive();

        $responses = $this->fetchedBy($archive['id']);

        $this->assertContains($responses['anonymous']->status, [401, 403]);
        $this->assertContains($responses['member']->status, [401, 403]);
        foreach (['anonymous', 'member'] as $who) {
            $this->assertStringNotContainsString('known.json', $responses[$who]->body, $who . ' gets none of it');
            $this->assertSame('', $responses[$who]->body, $who);
        }
        $this->assertSame(200, $responses['admin']->status);
        $this->assertSame($archive['bytes'], $responses['admin']->body);
    }

    public function testTheArchiveIsNeverCachedByASharedCacheOrABrowser(): void
    {
        $archive = $this->storedArchive();

        $admin = $this->fetchedBy($archive['id'])['admin'];

        $this->assertSame(200, $admin->status);
        $cache = (string) $admin->header('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringNotContainsString('public', $cache);
        $expires = $admin->header('Expires');
        $this->assertTrue($expires === null || strtotime((string) $expires) <= time(), 'no thirty-day expiry: ' . $expires);
        $this->assertStringStartsWith('attachment; filename="', (string) $admin->header('Content-Disposition'));
        $this->assertSame('application/zip', $admin->header('Content-Type'));
    }

    public function testARestrictedFileFailsClosedWhateverTheValueOfItsRestriction(): void
    {
        // The key being there is what restricts: whatever it holds, even something that reads as "no".
        foreach (['admin', 'anyone', 'true', '1', 'nobody', '0', '', false, true, 0, []] as $value) {
            $archive = $this->storedArchive(['filename' => 'x.zip', 'mime_type' => 'application/zip', 'restricted' => $value]);

            $responses = $this->fetchedBy($archive['id'], 'x.zip');

            $label = 'restricted = ' . json_encode($value);
            $this->assertContains($responses['anonymous']->status, [401, 403], $label);
            $this->assertContains($responses['member']->status, [401, 403], $label);
            $this->assertSame(200, $responses['admin']->status, $label);
        }
    }

    public function testAHeadOrARangeRequestForARestrictedFileIsRefusedToo(): void
    {
        $archive = $this->storedArchive();
        $name = 'file/' . $archive['id'] . '/archive.zip';
        $member = ['Cookie' => $this->logIn($this->user())];

        foreach ([[], $member] as $headers) {
            $head = $this->site()->request('HEAD', $name, $headers);
            $this->assertContains($head->status, [401, 403]);
            $this->assertNull($head->header('Content-Length'), 'the size of a restricted file is not told');
            $this->assertNull($head->header('Content-Disposition'));
            $this->assertNull($head->header('ETag'));
            $this->assertNull($head->header('Last-Modified'));
            $range = $this->site()->request('GET', $name, $headers + ['Range' => 'bytes=0-9']);
            $this->assertContains($range->status, [401, 403]);
            $this->assertSame('', $range->body, 'not a byte of it');
        }
        $admin = $this->site()->request('GET', $name, ['Cookie' => $this->logIn($this->admin()), 'Range' => 'bytes=0-3']);
        $this->assertSame(206, $admin->status, 'an administrator can still fetch a part');
        $this->assertSame(substr($archive['bytes'], 0, 4), $admin->body);
    }

    public function testAnArchiveRecordedByAnEarlierVersionWithNoMarkIsServedToAdministratorsOnly(): void
    {
        // The settings name the archive (export_file_id), but its file has no `restricted` mark: what the first
        // version stored. It is restricted by being the recorded one, not by its metadata.
        $this->rememberConfig();
        $archive = $this->storedArchive(['filename' => 'old.zip', 'mime_type' => 'application/zip']);
        $config = Idno::site()->config();
        $config->export_file_id = $archive['id'];
        $config->export_filename = 'old.zip';
        $config->save();

        $responses = $this->fetchedBy($archive['id'], 'old.zip');

        $this->assertContains($responses['anonymous']->status, [401, 403]);
        $this->assertContains($responses['member']->status, [401, 403]);
        $this->assertSame(200, $responses['admin']->status);
        $this->assertStringContainsString('no-store', (string) $responses['admin']->header('Cache-Control'));
        $this->assertContains($this->site()->request('HEAD', 'file/' . $archive['id'] . '/old.zip')->status, [401, 403]);
    }

    public function testLoadingTheExportPageMarksAnArchiveAnEarlierVersionLeftUnmarked(): void
    {
        $this->rememberConfig();
        $archive = $this->storedArchive(['filename' => 'old.zip', 'mime_type' => 'application/zip']);
        $config = Idno::site()->config();
        $config->export_file_id = $archive['id'];
        $config->export_filename = 'old.zip';
        $config->export_last_requested = time() - 3600;
        $config->save();
        $files = Idno::container()->get(\Idno\Storage\Files\FileStore::class);
        $this->assertArrayNotHasKey('restricted', $files->find($archive['id'])->metadata);

        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $this->logIn($this->admin())]);

        $this->assertSame(200, $page->status);
        $this->assertSame('admin', $files->find($archive['id'])->metadata['restricted'] ?? null, 'recorded as restricted for good');
    }

    public function testAnImportUploadWaitingForItsImportIsForAdministratorsToo(): void
    {
        $archive = $this->storedArchive(['filename' => 'export.xml', 'mime_type' => 'application/xml', 'restricted' => 'admin', 'purpose' => 'import']);

        $responses = $this->fetchedBy($archive['id'], 'export.xml');

        $this->assertContains($responses['anonymous']->status, [401, 403]);
        $this->assertContains($responses['member']->status, [401, 403]);
    }

    public function testAFileWithNoRestrictionIsServedToEveryoneAsBefore(): void
    {
        $archive = $this->storedArchive(['filename' => 'plain.zip', 'mime_type' => 'application/zip']);

        $responses = $this->fetchedBy($archive['id'], 'plain.zip');

        foreach ($responses as $who => $response) {
            $this->assertSame(200, $response->status, $who);
            $this->assertSame($archive['bytes'], $response->body, $who);
            $this->assertStringContainsString('public', (string) $response->header('Cache-Control'), $who);
        }
    }

    private function cleanFiles(): void
    {
        foreach ($this->files as $id) {
            try {
                Idno::container()->get(\Idno\Storage\Files\FileStore::class)->delete($id);
            } catch (\Throwable) {
            }
        }
        $this->files = [];
    }

    public function testAnUploadThatPhpRefusesForItsSizeIsAnsweredWithAMessageAndNoJob(): void
    {
        $limit = (int) ini_get('upload_max_filesize') ?: 2;
        $unit = strtoupper(substr((string) ini_get('upload_max_filesize'), -1));
        $bytes = $unit === 'G' ? $limit * 1073741824 : ($unit === 'M' ? $limit * 1048576 : ($unit === 'K' ? $limit * 1024 : $limit));
        if ($bytes > 6 * 1048576 || $bytes === 0) {
            $this->markTestSkipped('The web server accepts uploads this large (upload_max_filesize ' . ini_get('upload_max_filesize') . '): a file over it is too big to send in a test.');
        }
        $cookie = $this->logIn($this->admin());
        $filler = str_repeat('x', $bytes + 1024);
        $upload = '<?xml version="1.0"?><rss version="2.0"><channel><title>' . $filler . '</title></channel></rss>';

        $response = $this->importAs($cookie, 'WordPress', ['big.xml', $upload]);

        $this->assertSame(302, $response->status);
        $page = html_entity_decode($this->site()->request('GET', 'admin/import/', ['Cookie' => $cookie])->body, ENT_QUOTES);
        $this->assertStringContainsString('larger than', $page);
        $this->assertStringNotContainsString('You need to upload an import file', $page, 'not the message for no file');
    }

    // ------------------------------------------------------------------ the work done after the answer

    public function testTheImportPageAnswersAtOnceAndMailsTheAdminWhenItIsDone(): void
    {
        $scenario = $this->scenario();

        $this->assertSame(302, $scenario['import']['status']);
        $this->assertSame($this->site()->baseUrl() . 'admin/import/', $scenario['import']['location']);
        $this->assertLessThan(5.0, $scenario['import']['seconds'], 'the page answers before the work');

        $mail = $scenario['mails']['importer'];
        $this->assertSame('Idno - Your data import is complete', $mail['subject']);
        $this->assertSame('Your data import is done!', trim(strtok($mail['text'], "\n")));
        $this->assertStringContainsString('Visit your site: ' . $this->site()->baseUrl(), $mail['text']);
        $this->assertStringContainsString('Enjoy!', $mail['text']);
        $this->assertSame(['Scenario import: a draft', 'Scenario import: one post', 'Scenario import: private'], $scenario['imported']['titles']);
        $this->assertSame($scenario['importerUuid'], $scenario['imported']['owner'], 'owned by the administrator who ran the import');
        $this->assertSame(['draft', 'published', 'published'], $scenario['imported']['statuses']);
        $this->assertSame(['PUBLIC', 'PUBLIC', $scenario['importerUuid']], $scenario['imported']['access'], 'the private post stays private to the administrator');
    }

    public function testTheImportPageSaysTheImportHasStarted(): void
    {
        $this->assertStringContainsString("Your import has started. We'll email you when it's done.", $this->scenario()['startedMessage']);
    }

    public function testTheImportPageNeverAnnouncesWhatItImports(): void
    {
        $scenario = $this->scenario();

        $this->assertSame([], $scenario['delivered'], 'a follower of the administrator is sent nothing');
    }

    public function testTheUploadIsRemovedFromTheFileStoreOnceTheImportHasRun(): void
    {
        $scenario = $this->scenario();

        $this->assertSame([], $scenario['uploadsLeft'], 'no import upload is left in the file store');
    }

    public function testAnUploadThatIsNotXmlEndsInTheFailureMailAndCreatesNothing(): void
    {
        $scenario = $this->scenario();

        $mail = $scenario['mails']['bad'];
        $this->assertSame('Idno - Problem with your import', $mail['subject']);
        $this->assertStringContainsString('Sorry, your data import was unsuccessful.', $mail['text']);
        $this->assertSame([], $scenario['badImported'], 'nothing was created');
    }

    public function testARequestWithNoUsableFileOrSourceIsAnsweredWithAMessageAndNoJob(): void
    {
        $scenario = $this->scenario();

        foreach (['no file' => 'You need to upload an import file to continue.', 'empty file' => "We couldn't open the file you uploaded.", 'Known' => 'Choose where your file comes from', 'no type' => 'Choose where your file comes from'] as $name => $message) {
            $this->assertSame(302, $scenario['refused'][$name]['status'], $name);
            $this->assertStringContainsString($message, $scenario['refused'][$name]['message'], $name . ': the next page says what is wrong');
        }
        $this->assertSame([], $scenario['lateMails'], 'and no mail was sent for them');
    }

    public function testTheArchivePageAnswersAtOnceAndMailsTheAdminAPointerToTheExportPage(): void
    {
        $scenario = $this->scenario();

        $this->assertSame(302, $scenario['generate']['status']);
        $this->assertSame($this->site()->baseUrl() . 'admin/export/', $scenario['generate']['location']);
        $this->assertLessThan(5.0, $scenario['generate']['seconds']);

        $mail = $scenario['mails']['exporter'];
        $this->assertSame('Your data export is ready', $mail['subject']);
        $this->assertStringContainsString('Get your data: ' . $this->site()->baseUrl() . 'admin/export/', $mail['text'], 'the mail links to the export page, which links to the archive');
        $this->assertStringNotContainsString('/file/', $mail['text']);
        $this->assertStringContainsString('href="' . $this->site()->baseUrl() . 'admin/export/"', $mail['html']);
        $this->assertStringContainsString('file/' . $scenario['recorded']['export_file_id'] . '/', $scenario['exportPageAfter'], 'and the page does show the link');
    }

    public function testTheArchivePageRecordsTheArchiveInTheSettings(): void
    {
        $recorded = $this->scenario()['recorded'];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $recorded['export_file_id']);
        $this->assertSame(str_replace('.', '_', Idno::site()->config()->host) . '.zip', $recorded['export_filename']);
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

    public function testTheArchiveThePageMadeIsServedToTheAdministratorOnly(): void
    {
        $archive = $this->scenario()['archive'];

        $this->assertContains($archive['anonymousStatus'], [401, 403]);
        $this->assertContains($archive['memberStatus'], [401, 403]);
        $this->assertSame(200, $archive['adminStatus']);
        $this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $archive['id']));
        $this->assertNotEmpty($archive['config'], 'config.json is in what the administrator downloads');
        $this->assertArrayNotHasKey('site_secret', $archive['config']['config'], 'and holds none of the site\'s secrets');
        $this->assertArrayNotHasKey('ini_config', $archive['config']);
    }

    public function testNoStaleArchiveOrWorkingFilesAreLeftInTheTemporaryDirectoryByThePages(): void
    {
        $scenario = $this->scenario();

        $this->assertFalse($scenario['zipLeftBehind']);
        $this->assertSame([], $scenario['workingFilesLeft'], 'no working directory of the export, no import file');
    }

    public function testAnExportThatFailsEndsInTheFailureMailAndFreesTheFlag(): void
    {
        $scenario = $this->scenario();

        $mail = $scenario['mails']['failing'];
        $this->assertSame('Idno - Problem with your data export', $mail['subject']);
        $this->assertStringContainsString('Sorry, your data export was unsuccessful.', $mail['text']);
        $this->assertSame(0, $scenario['failedFlag']);
    }

    /**
     * Runs the import page and the archive page, as three administrators, and reads what they did. The
     * requests answer at once; the work is done after the answer and ends in a mail to each, which this
     * waits for.
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
        $bad = $this->adminNamed('portability-bad');
        $importerCookie = $this->logIn($importer);
        $exporterCookie = $this->logIn($exporter);
        $badCookie = $this->logIn($bad);
        $uploadsBefore = $this->uploadsLeft();

        // A follower of the importer, whose inbox is a sink of the site: what ActivityPub delivers is sent
        // while the import runs (the rig's queue is synchronous), so anything announced shows there.
        $sink = 'portability-' . bin2hex(random_bytes(4));
        $follower = new \IdnoPlugins\ActivityPub\Entities\ActivityPubFollower();
        $follower->user_uuid = $importer->getUUID();
        $follower->actor_uri = 'https://remote.example/users/bob';
        $follower->actor_inbox = $this->site()->baseUrl() . '_test/sink/' . $sink;
        $this->assertNotFalse($follower->save(true));
        $this->registerFixture($follower);

        $wordpress = '<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>Inline</title>'
            . '<item><title>Scenario import: one post</title><wp:post_date>2024-02-10 08:30:00</wp:post_date><wp:status>publish</wp:status><wp:post_type>post</wp:post_type><content:encoded><![CDATA[<p>The only public post.</p>]]></content:encoded></item>'
            . '<item><title>Scenario import: a draft</title><wp:post_date>2024-02-11 08:30:00</wp:post_date><wp:status>draft</wp:status><wp:post_type>post</wp:post_type><content:encoded><![CDATA[<p>Not ready.</p>]]></content:encoded></item>'
            . '<item><title>Scenario import: private</title><wp:post_date>2024-02-12 08:30:00</wp:post_date><wp:status>private</wp:status><wp:post_type>post</wp:post_type><content:encoded><![CDATA[<p>Only for me.</p>]]></content:encoded></item>'
            . '</channel></rss>';
        $started = microtime(true);
        $import = $this->importAs($importerCookie, 'WordPress', ['scenario.xml', $wordpress]);
        $importSeconds = microtime(true) - $started;
        $startedMessage = html_entity_decode($this->site()->request('GET', 'admin/import/', ['Cookie' => $importerCookie])->body, ENT_QUOTES);

        // A file that is not XML: the job runs and ends in the failure mail.
        $this->importAs($badCookie, 'WordPress', ['notes.txt', 'These are my notes, not an export.']);

        // The exporter's archive.
        $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $exporterCookie]);
        $started = microtime(true);
        $generate = $this->site()->request('POST', 'admin/export/generate/', ['Cookie' => $exporterCookie], $this->formToken($page->body, 'admin/export/rss'));
        $generateSeconds = microtime(true) - $started;

        $mails = $this->waitForMails(3);

        // Requests that must start nothing: no file, an empty one, a source the page does not handle, none at all.
        $refused = [];
        foreach ([
            'no file' => ['WordPress', null],
            'empty file' => ['WordPress', ['empty.xml', '']],
            'Known' => ['Known', ['export.rss', '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>']],
            'no type' => ['', ['export.xml', $wordpress]],
        ] as $name => [$type, $file]) {
            $response = $this->importAs($importerCookie, $type, $file);
            $page = $this->site()->request('GET', 'admin/import/', ['Cookie' => $importerCookie]);
            $refused[$name] = ['status' => $response->status, 'message' => html_entity_decode($page->body, ENT_QUOTES)];
        }
        $late = $this->waitForMails(0, 1); // what they must not do, they would have done by now
        $mails = [...$mails, ...$late];

        $recorded = Idno::site()->db()->getAnyRecord('config');
        $archive = ['id' => (string) ($recorded['export_file_id'] ?? '')];
        if ($archive['id'] !== '') {
            $name = (string) $recorded['export_filename'];
            $anonymous = $this->site()->request('GET', 'file/' . $archive['id'] . '/' . $name);
            $member = $this->site()->request('GET', 'file/' . $archive['id'] . '/' . $name, ['Cookie' => $this->logIn($this->user())]);
            $response = $this->site()->request('GET', 'file/' . $archive['id'] . '/' . $name, ['Cookie' => $exporterCookie]);
            $archive['anonymousStatus'] = $anonymous->status;
            $archive['memberStatus'] = $member->status;
            $archive['adminStatus'] = $response->status;
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
        }
        $exportPageAfter = $this->site()->request('GET', 'admin/export/', ['Cookie' => $exporterCookie])->body;
        $delivered = $this->site()->request('GET', '_test/sink/' . $sink . '/log')->json();

        // Now an export that fails: the file store cannot take the archive (its directory is a file).
        $failing = $this->failingExport($exporterCookie);

        $titles = [];
        $statuses = [];
        $access = [];
        $imported = [];
        $session = Idno::site()->session();
        $session->logUserOff();
        $session->logUserOn($this->admin());
        $entries = Entry::get(['owner' => $importer->getUUID()], [], 20);
        usort($entries, static fn ($a, $b): int => strcmp((string) $a->title, (string) $b->title));
        foreach ($entries as $entry) {
            $imported = ['owner' => $entry->getOwnerID()];
            $titles[] = $entry->title;
            $statuses[] = $entry->getPublishStatus();
            $access[] = $entry->access;
            $entry->delete();
        }
        $imported += ['titles' => $titles, 'statuses' => $statuses, 'access' => $access];
        $badImported = [];
        foreach (Entry::get(['owner' => $bad->getUUID()], [], 20) as $entry) {
            $badImported[] = $entry->title;
            $entry->delete();
        }
        $session->logUserOff();

        if ($archive['id'] !== '') {
            $this->removeRecordedArchive($recorded);
        }

        return self::$scenario = [
            'import' => ['status' => $import->status, 'location' => $import->header('Location'), 'seconds' => $importSeconds],
            'generate' => ['status' => $generate->status, 'location' => $generate->header('Location'), 'seconds' => $generateSeconds],
            'refused' => $refused,
            'startedMessage' => $startedMessage,
            'lateMails' => array_keys($late),
            'mails' => [
                'importer' => $mails[$importer->email] ?? $this->failScenario($mails, 'the import mail'),
                'exporter' => $mails[$exporter->email] ?? $this->failScenario($mails, 'the export mail'),
                'bad' => $mails[$bad->email] ?? $this->failScenario($mails, 'the failure mail of the bad import'),
                'failing' => $failing['mail'],
            ],
            'failedFlag' => $failing['flag'],
            'recorded' => $recorded,
            'archive' => $archive + ['bytes' => '', 'entries' => [], 'known' => [], 'config' => [], 'anonymousStatus' => 0, 'memberStatus' => 0, 'adminStatus' => 0],
            'exportPageAfter' => $exportPageAfter,
            'delivered' => $delivered,
            'imported' => $imported,
            'badImported' => $badImported,
            'importerUuid' => $importer->getUUID(),
            'uploadsLeft' => array_values(array_diff($this->uploadsLeft(), $uploadsBefore)),
            'zipLeftBehind' => is_file(Idno::site()->config()->getTempDir() . str_replace('.', '_', (string) Idno::site()->config()->host) . '.zip'),
            'workingFilesLeft' => $this->workingFilesLeft(),
        ];
    }

    /**
     * An export that fails inside the job: the archive's store is made unwritable by turning the day's
     * upload directory into a file for the length of the request.
     *
     * @return array{mail: array<string, mixed>, flag: int}
     */
    private function failingExport(string $cookie): array
    {
        $uploads = (string) Idno::site()->config()->uploadpath;
        $directory = rtrim($uploads, '/') . '/' . Idno::site()->config()->getFileBaseDirName();
        $moved = $directory . '.moved-by-test';
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        rename($directory, $moved);
        file_put_contents($directory, 'not a directory');
        try {
            $page = $this->site()->request('GET', 'admin/export/', ['Cookie' => $cookie]);
            $this->site()->request('POST', 'admin/export/generate/', ['Cookie' => $cookie], $this->formToken($page->body, 'admin/export/rss'));
            $mails = $this->waitForMails(1);
        } finally {
            @unlink($directory);
            rename($moved, $directory);
        }
        $mail = reset($mails);
        if ($mail === false) {
            $this->fail('No mail arrived for the failing export.');
        }

        return ['mail' => $mail, 'flag' => (int) ($this->storedConfig()['export_in_progress'] ?? 1)];
    }

    /**
     * @param int $least   wait until at least this many mails have arrived (at most 45 seconds)
     * @param int $seconds when $least is 0, how long to listen
     *
     * @return array<string, array<string, mixed>> the mails by the address they were sent to
     */
    private function waitForMails(int $least, int $seconds = 45): array
    {
        $mails = [];
        $deadline = microtime(true) + $seconds;
        while ((count($mails) < $least || $least === 0) && microtime(true) < $deadline) {
            usleep(250_000);
            foreach (RecordingMailTransport::drain() as $mail) {
                $mails[$mail['to'][0]['address']] = $mail;
            }
        }

        return $mails;
    }

    /**
     * @param array{0: string, 1: string}|null $file
     */
    private function importAs(string $cookie, string $type, ?array $file): SiteResponse
    {
        $page = $this->site()->request('GET', 'admin/import/', ['Cookie' => $cookie]);
        $fields = $this->formToken($page->body, 'admin/import/');
        if ($type !== '') {
            $fields['import_type'] = $type;
        }
        [$boundary, $body] = $this->multipart($fields, $file);

        return $this->site()->request('POST', 'admin/import/', ['Cookie' => $cookie, 'Content-Type' => 'multipart/form-data; boundary=' . $boundary], $body);
    }

    /**
     * @return list<string> the ids of the files of the file store that wait for an import
     */
    private function uploadsLeft(): array
    {
        $found = [];
        $store = Idno::container()->get(\Idno\Storage\Files\FileStore::class);
        $root = rtrim((string) Idno::site()->config()->uploadpath, '/') . '/' . Idno::site()->config()->getFileBaseDirName();
        foreach (glob($root . '/?/?/?/?/*.data') ?: [] as $data) {
            $metadata = json_decode((string) file_get_contents($data), true);
            if (is_array($metadata) && ($metadata['purpose'] ?? null) === 'import') {
                $found[] = basename($data, '.data');
            }
        }

        return $found;
    }

    /**
     * @return list<string> the working directories and files the pages' work left in the temporary directory
     */
    private function workingFilesLeft(): array
    {
        $temporary = rtrim(Idno::site()->config()->getTempDir(), '/');
        $made = array_filter(
            [...(glob($temporary . '/idno-export-*') ?: []), ...(glob($temporary . '/idno-import-*') ?: []), ...(glob($temporary . '/import_*') ?: [])],
            static fn (string $path): bool => preg_match('~/(idno-(export|import)-[0-9a-f]{32}|import_[A-Za-z0-9]+)$~', $path) === 1
        );

        return array_values(array_map('basename', $made));
    }

    /**
     * @param array<string, mixed> $mails
     */
    private function failScenario(array $mails, string $what): never
    {
        $this->fail('No ' . $what . ' arrived within 45 seconds. Mails: ' . json_encode(array_keys($mails)) . ' (the web server\'s log is Tests/rig/.server.log)');
    }

    /**
     * Removes the archive a test recorded, with the settings that name it.
     *
     * @param array<string, mixed> $recorded
     */
    private function removeRecordedArchive(array $recorded): void
    {
        if (!empty($recorded['export_file_id'])) {
            $this->registerFile((string) $recorded['export_file_id']);
        }
    }

    /**
     * Takes settings a test stored out of the stored configuration again.
     *
     * @param list<string> $keys
     */
    private function forgetSettings(array $keys): void
    {
        $config = Idno::site()->config();
        foreach ($keys as $key) {
            unset($config->config[$key]);
        }
        $config->save();
    }

    // ------------------------------------------------------------------ helpers

    private function rememberConfig(): void
    {
        if ($this->savedConfig === null) {
            $config = Idno::site()->config()->config;
            $this->savedConfig = [];
            foreach (['from_email', 'export_file_id', 'export_filename', 'export_in_progress', 'export_last_requested', 'export_file_created', 'portability_last_sweep'] as $key) {
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
