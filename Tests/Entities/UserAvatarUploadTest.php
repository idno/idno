<?php

namespace Tests\Entities;

use Idno\Core\Idno;

/**
 * A profile picture that PHP did not keep (too large, cut short) is not dropped in
 * silence: the profile is saved, and the member is told that the picture did not change
 * and why.
 */
class UserAvatarUploadTest extends \Tests\IdnoTestCase
{

    private $previousUser;
    private $previousPage;
    private $previousFiles;

    public function setUp(): void
    {
        $this->previousUser = $this->swapUser($this->user());
        $this->previousPage = Idno::site()->currentPage();
        $this->previousFiles = $_FILES;
        Idno::site()->session()->getAndFlushMessages();
        Idno::site()->setCurrentPage(new \Idno\Pages\User\Edit());
    }

    public function tearDown(): void
    {
        $_FILES = $this->previousFiles;
        Idno::site()->setCurrentPage($this->previousPage ?: new \Idno\Pages\Homepage());
        Idno::site()->session()->getAndFlushMessages();
        $this->swapUser($this->previousUser);
    }

    private function messages(): string
    {
        $text = '';
        foreach (Idno::site()->session()->getAndFlushMessages() as $message) {
            $text .= html_entity_decode($message['message'], ENT_QUOTES, 'UTF-8') . "\n";
        }

        return $text;
    }

    public function testAPictureThatWasRefusedIsReported()
    {
        $user = $this->user();
        $icon = $user->icon;
        $_FILES = ['avatar' => ['name' => 'me.jpg', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]];

        $this->assertNotEmpty($user->saveDataFromInput(), 'The rest of the profile is saved.');

        $messages = $this->messages();
        $this->assertStringContainsString('"me.jpg" is too large', $messages);
        $this->assertStringContainsString('Your picture was not changed', $messages);
        $this->assertSame($icon, $user->icon);
    }

    public function testNoPictureChosenSaysNothing()
    {
        $_FILES = ['avatar' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]];

        $this->assertNotEmpty($this->user()->saveDataFromInput());
        $this->assertSame('', $this->messages());
    }
}
