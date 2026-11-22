<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Email;
use Idno\Entities\User;
use Idno\Portability\ResultNotifier;
use Psr\Log\LoggerInterface;

/**
 * The mail that ends an import or an export, with the templates it always had.
 */
final class LegacyResultNotifier implements ResultNotifier
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function importFinished(string $userUuid, bool $succeeded, int $imported = 0, int $skipped = 0, bool $startedBefore = false, bool $stopped = false): void
    {
        $this->send(
            $userUuid,
            $succeeded ? 'admin/import' : 'admin/import_failure',
            $succeeded ? 'Idno - Your data import is complete' : 'Idno - Problem with your import',
            ['imported' => $imported, 'skipped' => $skipped, 'startedBefore' => $startedBefore, 'stopped' => $stopped]
        );
    }

    public function exportFinished(string $userUuid, bool $succeeded, int $unrendered = 0): void
    {
        $this->send(
            $userUuid,
            $succeeded ? 'admin/export' : 'admin/export_failure',
            $succeeded ? 'Your data export is ready' : 'Idno - Problem with your data export',
            ['unrendered' => $unrendered]
        );
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function send(string $userUuid, string $template, string $subject, array $vars = []): void
    {
        try {
            $user = User::getByUUID($userUuid, false);
            if (!$user instanceof User || empty($user->email)) {
                $this->logger->warning('There is nobody to tell how the import or export ended.');

                return;
            }
            $mail = new Email();
            $mail->setHTMLBodyFromTemplate($template, $vars);
            $mail->setTextBodyFromTemplate($template, $vars);
            $mail->addTo($user->email);
            $mail->setSubject($subject);
            if ($mail->send() === 0) {
                $this->logger->warning('The mail that ends an import or export was not sent (' . (string) $mail->lastFailure() . ').');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('The mail that ends an import or export could not be made: ' . $e::class);
        }
    }
}
