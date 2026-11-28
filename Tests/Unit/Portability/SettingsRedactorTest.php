<?php

declare(strict_types=1);

namespace Tests\Unit\Portability;

use Idno\Portability\SettingsRedactor;
use PHPUnit\Framework\TestCase;

final class SettingsRedactorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function secrets(): array
    {
        $keys = ['smtp_password', 'dbpass', 'dbuser', 'site_secret', 'aws_key', 'aws_secret', 'apikey', 'api_key', 'consumerKey', 'consumer_secret', 'access_token', 'oauth-token', 'client-secret', 'private_key', 'privateKey', 'smtp_username', 'password', 'passwd', 'key', 'salt', 'webhook_signature', 'auth', 'database_dsn', 'github_pass', 'jwt_secret', 'jwt', 'hmac_key', 'hmac', 'authorization', 'HTTP_AUTHORIZATION', 'auth_token', 'credential', 'private', 'cookie', 'HTTP_COOKIE', 'password_salt', 'IDNO_DBPASS', 'IDNO_SITE_SECRET', 'bearer', 'bearer_value', 'oauth_secret_x', 'oauth', 'github_oauth', 'indieauth_codes', 'indieauth_code', 'access_token', 'refresh_token', 'session_secret', 'session_key', 'form_token', 'PHP_AUTH_PW', 'MYSQL_PWD', 'DB_PWD', 'PGPASSWORD_PWD', 'IDNO_SMTP_PWD', 'sessiontoken', 'dbstring', 'proxy_string', 'passcode', '_csrf', 'csrf', 'CSRF', 'xsrf', 'XSRF-TOKEN', 'csrf_token', 'HTTP_X_CSRF_TOKEN'];

        return array_combine($keys, array_map(static fn (string $key): array => [$key], $keys));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notSecrets(): array
    {
        $keys = ['title', 'description', 'plugins', 'timezone', 'url', 'theme', 'items_per_page', 'hub', 'smtp_host', 'smtp_port', 'from_email', 'dbname', 'author', 'keywords', 'monkey', 'hashtags', 'export_file_id', 'client_id', 'oauth_client_id', 'github_client_id', 'session_hash_function', 'session_path', 'sessionname', 'sessions_database', 'sessions_storage', 'loglevel', 'single_user', 'default_plugins', 'PWD', 'OLDPWD'];

        return array_combine($keys, array_map(static fn (string $key): array => [$key], $keys));
    }

    /**
     * @dataProvider secrets
     */
    public function testASecretKeyIsRecognised(string $key): void
    {
        $this->assertTrue(SettingsRedactor::isSecretKey($key));
    }

    /**
     * @dataProvider notSecrets
     */
    public function testAnOrdinaryKeyIsLeftAlone(string $key): void
    {
        $this->assertFalse(SettingsRedactor::isSecretKey($key));
    }

    public function testTheSessionsFormTokenIsRemovedAtAnyDepth(): void
    {
        $token = str_repeat('a1', 32);
        $session = ['user_uuid' => 'u', '_csrf' => ['idno' => $token], 'nested' => ['xsrf' => $token]];

        $redacted = SettingsRedactor::redact($session);

        $this->assertStringNotContainsString($token, json_encode($redacted));
        $this->assertSame('u', $redacted['user_uuid']);
    }

    public function testSecretsAreReplacedAtEveryDepthAndTheRestIsKept(): void
    {
        $settings = ['title' => 'My site', 'smtp_password' => 'p', 'twitter' => ['consumer_key' => 'k', 'consumer_secret' => 's', 'screen_name' => 'me'], 'plugins' => ['Status', 'Text'], 'list' => [['token' => 't', 'name' => 'n']]];

        $this->assertSame(
            ['title' => 'My site', 'smtp_password' => '** REDACTED **', 'twitter' => ['consumer_key' => '** REDACTED **', 'consumer_secret' => '** REDACTED **', 'screen_name' => 'me'], 'plugins' => ['Status', 'Text'], 'list' => [['token' => '** REDACTED **', 'name' => 'n']]],
            SettingsRedactor::redact($settings)
        );
    }

    public function testWithNoReplacementTheKeysAreRemoved(): void
    {
        $this->assertSame(
            ['title' => 'x', 'nested' => ['kept' => 1]],
            SettingsRedactor::redact(['title' => 'x', 'site_secret' => 's', 'nested' => ['kept' => 1, 'apikey' => 'a']], null)
        );
    }

    public function testASecretThatIsAnArrayIsReplacedWhole(): void
    {
        $this->assertSame(['credentials' => '** REDACTED **'], SettingsRedactor::redact(['credentials' => ['a' => 1, 'b' => 2]]));
    }

    public function testAddressesLoseTheirUserNameAndPasswordAtAnyDepth(): void
    {
        $redacted = SettingsRedactor::redact([
            'mirror' => 'https://user:pass@mirror.example/path?q=1',
            'nested' => ['feed' => 'http://token@feed.example/rss', 'plain' => 'https://example.test/a@b'],
            'email' => 'someone@example.test',
            'title' => 'Mail me at me@example.test or https://x:y@z.example/',
        ]);

        $this->assertSame('https://mirror.example/path?q=1', $redacted['mirror']);
        $this->assertSame('http://feed.example/rss', $redacted['nested']['feed']);
        $this->assertSame('https://example.test/a@b', $redacted['nested']['plain'], 'an @ in a path is not a user name');
        $this->assertSame('someone@example.test', $redacted['email'], 'an e-mail address is not an address with a user name');
        $this->assertSame('Mail me at me@example.test or https://z.example/', $redacted['title']);
    }

    public function testAWebhookKeepsItsEntriesWithEachAddressReducedToSchemeAndHost(): void
    {
        $redacted = SettingsRedactor::redact(['webhook_syndication' => ['https://hooks.slack.example/services/T000/B000/SECRET', 'https://user:pw@discord.example/api/webhooks/1/abc?x=y'], 'webhooks' => ['one' => 'https://a.example/x/y'], 'title' => 'kept']);

        $this->assertSame(['https://hooks.slack.example', 'https://discord.example'], $redacted['webhook_syndication']);
        $this->assertSame(['one' => 'https://a.example'], $redacted['webhooks']);
        $this->assertSame('kept', $redacted['title']);
        $this->assertSame(['webhook_syndication' => ['https://hooks.slack.example', 'https://discord.example'], 'webhooks' => ['one' => 'https://a.example'], 'title' => 'kept'], SettingsRedactor::redact(['webhook_syndication' => ['https://hooks.slack.example/services/T000/B000/SECRET', 'https://user:pw@discord.example/api/webhooks/1/abc?x=y'], 'webhooks' => ['one' => 'https://a.example/x/y'], 'title' => 'kept'], null), 'also when secrets are removed');
    }

    public function testANumberUnderAKeyThatNamesADurationIsNoSecret(): void
    {
        $redacted = SettingsRedactor::redact(['form_token_expiry' => 3600, 'session_token_ttl' => '900', 'form_token' => 'abc123', 'api_token_expiry' => 'abc']);

        $this->assertSame(3600, $redacted['form_token_expiry']);
        $this->assertSame('900', $redacted['session_token_ttl']);
        $this->assertSame('** REDACTED **', $redacted['form_token'], 'a token itself is hidden');
        $this->assertSame('** REDACTED **', $redacted['api_token_expiry'], 'and a non-number under such a key could be one');
    }

    public function testTheRealSessionSettingsStayVisibleAndTheRealSecretsDoNot(): void
    {
        $visible = ['session_hash_function' => 'sha256', 'session_path' => '/tmp', 'sessionname' => 'idno', 'sessions_database' => true, 'sessions_storage' => 'x', 'form_token_expiry' => 3600];
        $secrets = ['site_secret' => 's', 'session_secret' => 's', 'session_key' => 'k', 'smtp_password' => 'p', 'aws_secret' => 'a', 'HTTP_COOKIE' => 'idno=abc', 'HTTP_AUTHORIZATION' => 'Bearer x'];

        $this->assertSame($visible, SettingsRedactor::redact($visible));
        foreach (SettingsRedactor::redact($secrets) as $key => $value) {
            $this->assertSame('** REDACTED **', $value, $key);
        }
    }

    public function testAValueThatIsAPaymentProvidersSecretKeyIsRemovedWhateverItsKey(): void
    {
        $redacted = SettingsRedactor::redact(['stripe' => 'sk_live_abc123', 'something' => ['x' => 'rk_test_zzz'], 'publishable' => 'pk_live_abc', 'note' => 'ask_live_abc', 'title' => 'sk_ is not it']);

        $this->assertSame('** REDACTED **', $redacted['stripe']);
        $this->assertSame('** REDACTED **', $redacted['something']['x']);
        $this->assertSame('pk_live_abc', $redacted['publishable'], 'a publishable key is public');
        $this->assertSame('ask_live_abc', $redacted['note'], 'only a value that starts with the prefix');
        $this->assertSame('sk_ is not it', $redacted['title']);
        $this->assertSame(['publishable' => 'pk_live_abc'], SettingsRedactor::redact(['stripe' => 'sk_live_abc123', 'publishable' => 'pk_live_abc'], null), 'removed, with no marker, for the archive');
    }

    /** @return array<string, array{string, string}> */
    public static function addressesWithPasswords(): array
    {
        $db = 'mysql://u:%s@db.example/idno';
        $rows = [];
        foreach (['p@ss', 'p@ss@word', 'pa:ss', 'p%40ss', 'pa%2Fss', 'p?ss', 'p#ss', 'p/ss', 'p/s?s#s@s'] as $password) {
            $rows['password ' . $password] = [sprintf($db, $password), 'mysql://db.example/idno'];
        }
        $rows['a password with a space'] = ['mysql://u:pa ss@db.example/idno', 'mysql://db.example/idno'];
        $rows['a password with a tab'] = ["mysql://u:pa\tss@db.example/idno", 'mysql://db.example/idno'];
        $rows['a list of hosts'] = ['mongodb://u:p@ss@h1,h2/db', 'mongodb://h1,h2/db'];
        $rows['no scheme, a proxy'] = ['u:p@ss@host.example:3128', 'host.example:3128'];
        $rows['no scheme, a password with a slash'] = ['u:p/ss@host.example:3128', 'host.example:3128'];

        return $rows;
    }

    /**
     * A database password holding an @, a colon, a slash, a ? or a # must not be partly shown in the diagnostics report or the archive.
     *
     * @dataProvider addressesWithPasswords
     */
    public function testAPasswordIsRemovedWholeWhateverItHolds(string $value, string $expected): void
    {
        $this->assertSame($expected, SettingsRedactor::withoutUserInfo($value));
        $this->assertSame(['mirror' => $expected], SettingsRedactor::redact(['mirror' => $value]));
    }

    /** @return array<string, array{string, string}> */
    public static function connectionStrings(): array
    {
        return [
            'password=' => ['Server=db;Database=x;User Id=u;Password=s3cr3t;Encrypt=true', 'Server=db;Database=x;User Id=u;Password=;Encrypt=true'],
            'pwd= at the end' => ['host=h;uid=u;pwd=s3cr3t', 'host=h;uid=u;pwd='],
            'password= holding an @' => ['DSN=mysql:host=h;user=u;password=s3cr@t;', 'DSN=mysql:host=h;user=u;password=;'],
            'password= holding a space and a slash' => ['host=h;password=a b/c;port=1', 'host=h;password=;port=1'],
        ];
    }

    /**
     * A password in a connection string (`password=`, `pwd=`) is removed whole, up to the next `;` (or the end), whatever the setting is called.
     *
     * @dataProvider connectionStrings
     */
    public function testAConnectionStringsPasswordIsRemovedWhole(string $value, string $expected): void
    {
        $this->assertSame($expected, SettingsRedactor::withoutUserInfo($value));
        $this->assertSame(['plugin_setting' => $expected], SettingsRedactor::redact(['plugin_setting' => $value]));
    }

    /** Text that only looks like a connection string's password stays. */
    public function testWordsThatAreNotPasswordParametersSurvive(): void
    {
        foreach (['passport=ok;compass=north', 'password_reset=1;pwdx=2', 'user=u;host=h', 'Reset your password at the link'] as $value) {
            $this->assertSame($value, SettingsRedactor::withoutUserInfo($value));
        }
    }

    /** An address with no user name and password in it, and an e-mail address, are shown as they are. */
    public function testAddressesThatHoldNoPasswordSurvive(): void
    {
        // (an address with a port before a later @, `https://host:8080/@user`, is over-redacted on purpose: see withoutUserInfo())
        foreach (['https://host.example/@user', 'https://example.test/a@b', 'someone@example.test', 'Mail me@example.test about it', 'https://host.example/a:b@c'] as $value) {
            $this->assertSame($value, SettingsRedactor::withoutUserInfo($value));
        }
    }
}
