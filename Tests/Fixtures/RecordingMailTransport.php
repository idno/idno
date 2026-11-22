<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Test-environment only (config/services_test.php): the mail transport of the test container. It sends
 * nothing: each message is appended, as one JSON line, to a file in a directory under the system
 * temporary directory named after this checkout's real path (as the /_test/sink files are: CI's servers
 * do not use the rig's directory, so it is made on demand), under an exclusive lock because the web
 * server has several workers. The PHPUnit process and the rig's web server are different processes and
 * both append to the one file; drain() and /_test/mail/log read it and empty it.
 *
 * What is recorded of the transport is its DSN without the password (a recorded secret would be a
 * secret in a file).
 */
final class RecordingMailTransport extends AbstractTransport
{
    /**
     * @param array{scheme: string, host: string, port: ?int, user: ?string, require_tls: bool} $transport
     */
    public function __construct(private readonly array $transport)
    {
        parent::__construct();
    }

    public static function forDsn(Dsn $dsn): self
    {
        return new self([
            'scheme' => $dsn->getScheme(),
            'host' => $dsn->getHost(),
            'port' => $dsn->getPort(),
            'user' => $dsn->getUser(),
            'require_tls' => $dsn->getBooleanOption('require_tls'),
        ]);
    }

    public function __toString(): string
    {
        return 'recording://' . $this->transport['host'];
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        $record = [
            'transport' => $this->transport,
            'envelope_sender' => $message->getEnvelope()->getSender()->getAddress(),
            'envelope_recipients' => array_map(static fn (Address $a): string => $a->getAddress(), $message->getEnvelope()->getRecipients()),
        ];
        if ($original instanceof Email) {
            $addresses = static fn (array $list): array => array_map(
                static fn (Address $a): array => ['address' => $a->getAddress(), 'name' => $a->getName()],
                $list
            );
            $record += [
                'from' => $addresses($original->getFrom()),
                'to' => $addresses($original->getTo()),
                'bcc' => $addresses($original->getBcc()),
                'reply_to' => $addresses($original->getReplyTo()),
                'subject' => (string) $original->getSubject(),
                'text' => (string) $original->getTextBody(),
                'html' => (string) $original->getHtmlBody(),
                'headers' => $original->getPreparedHeaders()->toString(),
            ];
        }

        file_put_contents(self::file(), json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * The messages recorded so far, oldest first; the file is emptied.
     *
     * @return list<array<string, mixed>>
     */
    public static function drain(): array
    {
        $messages = [];
        $handle = fopen(self::file(), 'c+');
        if ($handle !== false) {
            if (flock($handle, LOCK_EX)) {
                $contents = (string) stream_get_contents($handle);
                ftruncate($handle, 0);
                flock($handle, LOCK_UN);
                foreach (explode("\n", $contents) as $line) {
                    if ($line !== '') {
                        $messages[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    }
                }
            }
            fclose($handle);
        }

        return $messages;
    }

    private static function file(): string
    {
        $directory = rtrim(sys_get_temp_dir(), '/') . '/idno-test-mail-' . substr(hash('sha256', (string) realpath(dirname(__DIR__, 2))), 0, 12);
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        return $directory . '/mail.jsonl';
    }
}
