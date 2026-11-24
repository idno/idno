<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Acceptance\Browser;
use Tests\Support\PrivateSiteTestCase;

/**
 * Changing the email address or the password in the account settings takes the member's current password, counted
 * as a login for the account is; a change of the name does not.
 */
final class AccountSettingsCurrentPasswordTest extends PrivateSiteTestCase
{
    private const PASSWORD = 'correct-horse-battery-9';

    public function testTheFormAsksForTheCurrentPasswordAccessibly(): void
    {
        $cookie = $this->registerUser('ada');

        $page = $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->body;

        $this->assertMatchesRegularExpression('/<label for="inputCurrentPassword">/', $page);
        $this->assertMatchesRegularExpression('/<input[^>]*name="current_password"[^>]*autocomplete="current-password"|<input[^>]*autocomplete="current-password"[^>]*name="current_password"/', $page);
        $this->assertMatchesRegularExpression('/<input[^>]*name="password"[^>]*autocomplete="new-password"|<input[^>]*autocomplete="new-password"[^>]*name="password"/', $page);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: bool}> the fields posted besides the name, and whether they ask for the current password
     */
    public static function changes(): array
    {
        return [
            'a new email address' => [['email' => 'ada2@example.com'], true],
            'a new password' => [['email' => 'ada@example.com', 'password' => 'a-new-passphrase-77'], true],
            'the name only' => [['email' => 'ada@example.com'], false],
        ];
    }

    /**
     * @dataProvider changes
     * @param array<string, string> $fields
     */
    public function testWithoutAWithAWrongAndWithTheRightCurrentPassword(array $fields, bool $asksForIt): void
    {
        $cookie = $this->registerUser('ada');

        $without = $this->post($cookie, $fields + ['name' => 'Name Without']);
        $this->assertSame($asksForIt ? 'Ada' : 'Name Without', $this->stored('ada', 'title'), 'without it');
        if ($asksForIt) {
            $this->assertStringContainsString("Your current password isn't right", Browser::text($this->settings($cookie, $without)->body));
        }

        $wrong = $this->post($cookie, $fields + ['name' => 'Name Wrong', 'current_password' => 'not the password']);
        $this->assertSame($asksForIt ? 'Ada' : 'Name Wrong', $this->stored('ada', 'title'), 'with a wrong one');
        if ($asksForIt) {
            $this->assertStringContainsString("Your current password isn't right", Browser::text($this->settings($cookie, $wrong)->body));
            $this->assertSame('ada@example.com', $this->stored('ada', 'email'), 'nothing was changed');
            $this->assertNotNull((new Browser($this->site()))->tryLogin('ada', self::PASSWORD), 'the password is the old one');
        }

        $this->post($cookie, $fields + ['name' => 'Name Right', 'current_password' => self::PASSWORD]);
        $this->assertSame('Name Right', $this->stored('ada', 'title'), 'with the right one');
        $this->assertSame($fields['email'], $this->stored('ada', 'email'));
        if (isset($fields['password'])) {
            $this->assertNotNull((new Browser($this->site()))->tryLogin('ada', $fields['password']));
        }
    }

    public function testWrongCurrentPasswordsCountLikeFailedLogins(): void
    {
        $cookie = $this->registerUser('ada');
        for ($i = 0; $i < 5; $i++) {
            $this->post($cookie, ['name' => 'Ada', 'email' => 'ada2@example.com', 'current_password' => 'not the password']);
        }

        $locked = $this->post($cookie, ['name' => 'Ada', 'email' => 'ada2@example.com', 'current_password' => self::PASSWORD]);

        $this->assertStringContainsString('Too many failed sign-in attempts', Browser::text($this->settings($cookie, $locked)->body));
        $this->assertSame('ada@example.com', $this->stored('ada', 'email'), 'even the right password changes nothing while the account is locked');
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $cookie, array $fields): SiteResponse
    {
        $page = $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie]);

        return $this->site()->request('POST', 'account/settings/', ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . 'account/settings/'], self::formToken($page->body, 'account/settings/') + $fields);
    }

    private function settings(string $cookie, SiteResponse $after): SiteResponse
    {
        $this->assertSame(302, $after->status);

        return $this->site()->request('GET', 'account/settings/', ['Cookie' => self::sessionCookieOrNull($after) ?? $cookie]);
    }

    private function stored(string $handle, string $field): string
    {
        return (string) $this->runInSite('$u = \Idno\Entities\User::getByHandle(' . $this->literal($handle) . '); return $u ? $u->' . $field . ' : null;');
    }
}
