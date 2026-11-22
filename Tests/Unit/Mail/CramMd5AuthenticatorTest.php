<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use Idno\Mail\CramMd5Authenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Smtp\Auth\CramMd5Authenticator as SymfonyCramMd5Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class CramMd5AuthenticatorTest extends TestCase
{
    /**
     * @return array{EsmtpTransport, \ArrayObject<int, string>} a transport that answers the challenge and records the commands
     */
    private static function transport(string $user, string $password): array
    {
        $sent = new \ArrayObject();
        $transport = new class ($sent) extends EsmtpTransport {
            public function __construct(private readonly \ArrayObject $sent)
            {
                parent::__construct('localhost', 25, false);
            }

            public function executeCommand(string $command, array $codes): string
            {
                $this->sent[] = $command;

                return $command === "AUTH CRAM-MD5\r\n" ? '334 ' . base64_encode('<1896.697170952@postoffice.example.net>') : '235 ok';
            }
        };
        $transport->setUsername($user);
        $transport->setPassword($password);

        return [$transport, $sent];
    }

    public function testItAnswersTheChallengeAsSymfonysAuthenticatorDoes(): void
    {
        foreach (['tanstaaftanstaaf', str_repeat('x', 100), "p@ss:w/rd%#? \u{e9}"] as $password) {
            [$ours, $oursSent] = self::transport('tim', $password);
            [$theirs, $theirsSent] = self::transport('tim', $password);
            (new CramMd5Authenticator())->authenticate($ours);
            (new SymfonyCramMd5Authenticator())->authenticate($theirs);
            $this->assertSame($theirsSent->getArrayCopy(), $oursSent->getArrayCopy());
        }
    }

    public function testAnEmptyPasswordIsUsedAsItIs(): void
    {
        [$transport, $sent] = self::transport('mailer', '');
        (new CramMd5Authenticator())->authenticate($transport);
        $this->assertSame(base64_encode('mailer ' . hash_hmac('md5', '<1896.697170952@postoffice.example.net>', '')) . "\r\n", $sent[1]);
        $this->expectException(\Symfony\Component\Mailer\Exception\InvalidArgumentException::class);
        (new SymfonyCramMd5Authenticator())->authenticate($transport);
    }
}
