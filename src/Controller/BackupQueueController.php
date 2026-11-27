<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppUser;
use App\Service\BackupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Queued backups from the dashboard.
 *
 * Retry: panel role ROLE_USER, the entry must be visible to the user (admin, or owner of its database on that
 * server; otherwise 404). No MySQL privilege here: the entry only goes back to pending and the next
 * app:process-backup-queue run dumps it. Only a failed entry can be retried (conditional UPDATE: a second
 * click finds it pending and changes nothing).
 *
 * @see \App\Tests\Functional\BackupQueueTest
 */
#[IsGranted(attribute: 'ROLE_USER')]
final class BackupQueueController extends AbstractController
{
    public const string FLASH = 'backup_queue';

    public const string CSRF_RETRY = 'backup-queue-retry';

    #[Route(path: '/backup-queue/{id}/retry', name: 'app_backup_queue_retry', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(id: self::CSRF_RETRY)]
    public function retry(int $id, #[CurrentUser] AppUser $user, BackupService $backups): RedirectResponse
    {
        $entry = $backups->findVisibleQueueEntry($user, $id)
            ?? throw $this->createNotFoundException('Richiesta di backup non trovata.');

        $this->addFlash(self::FLASH, $backups->retry($entry)
            ? 'Backup rimesso in coda: verrà eseguito al prossimo passaggio del cron.'
            : 'Il backup non è in stato "Fallito": nessuna modifica.');

        return $this->redirectToRoute('app_default', status: Response::HTTP_SEE_OTHER);
    }
}
