<?php

declare(strict_types=1);

namespace App\Controller\Schema;

use App\Attribute\Audited;
use App\Dto\BackupDump;
use App\Dto\Input\BackupFile;
use App\Dto\Input\BackupTarget;
use App\Dto\Input\RestoreBackup;
use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Enum\AuditActionEnum;
use App\Security\SqlClientAccessResolver;
use App\Service\BackupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Backups: immediate or queued, list, view, download, delete, restore.
 *
 * Panel access: server/database ownership through SqlClientAccessResolver (admins see everything);
 * input mapped to the DTOs of App\Dto\Input; routes under /schema (names unchanged).
 */
#[Route(path: '/schema')]
final class BackupController extends AbstractController
{
    public function __construct(
        private readonly SqlClientAccessResolver $access,
        private readonly BackupService $backups,
    ) {
    }

    #[Route(path: '/table-backup', name: 'app_schema_table_backup', methods: ['POST'])]
    public function tableBackup(#[MapRequestPayload] BackupTarget $input): JsonResponse
    {
        $sqlClient = $this->access->database($input->name, $input->dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $outcome = $this->backups->backupNow($sqlClient, $input->dbName, $input->table);

        return $this->json([
            'is_valid' => $outcome->ok,
            'message' => $outcome->ok ? 'Backup completato.' : 'Errore durante il backup: ' . $outcome->error,
            'backup_filename' => $outcome->filename,
        ]);
    }

    #[Route(path: '/enqueue-backup', name: 'app_schema_enqueue_backup', methods: ['POST'])]
    public function enqueueBackup(
        #[MapRequestPayload] BackupTarget $input,
        #[CurrentUser] AppUser $user): JsonResponse
    {
        $dbName = $input->dbName;

        $sqlClient = $this->access->database($input->name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->backups->enqueue($user, $sqlClient, $dbName, $input->table);
        } catch (\Exception $exception) {
            return $this->json([
                'is_valid' => false,
                'message' => 'Errore: ' . $exception->getMessage(),
                'database' => $dbName,
            ]);
        }

        return $this->json([
            'is_valid' => true,
            'message' => 'Backup accodato.',
            'database' => $dbName,
        ]);
    }

    #[Route(path: '/backup-list', name: 'app_schema_backup_list', methods: ['GET'])]
    public function backupList(#[CurrentUser] AppUser $user): Response
    {
        return $this->render('schema/backup_list.html.twig', [
            'backups' => $this->backups->visibleBackups($user),
        ]);
    }

    #[Route(path: '/backup-view', name: 'app_schema_backup_view', methods: ['GET'])]
    public function backupView(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] BackupFile $input,
        #[CurrentUser] AppUser $user): Response
    {
        $backup = $this->backups->findVisible($user, $input->filename)
            ?? throw $this->createNotFoundException('File di backup non trovato.');

        return new Response($this->backups->contents($backup), Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route(path: '/backup-download', name: 'app_schema_backup_download', methods: ['GET'])]
    public function backupDownload(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] BackupFile $input,
        #[CurrentUser] AppUser $user): BinaryFileResponse
    {
        $backup = $this->backups->findVisible($user, $input->filename)
            ?? throw $this->createNotFoundException('File di backup non trovato.');

        $response = new BinaryFileResponse($backup->path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $backup->filename);

        return $response;
    }

    #[Route(path: '/backup-delete', name: 'app_schema_backup_delete', methods: ['POST'])]
    #[Audited(action: AuditActionEnum::BACKUP_DELETE)]
    public function backupDelete(
        #[MapRequestPayload] BackupFile $input,
        #[CurrentUser] AppUser $user): JsonResponse
    {
        $backup = $this->backups->findVisible($user, $input->filename);
        if (!$backup instanceof BackupDump) {
            return $this->json(['is_valid' => false, 'message' => 'File di backup non trovato.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->backups->delete($backup)) {
            return $this->json(['is_valid' => false, 'message' => 'Impossibile eliminare il file: controlla i permessi di BACKUP_PATH.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['is_valid' => true, 'message' => 'Backup eliminato.']);
    }

    #[Route(path: '/table-restore', name: 'app_schema_table_restore', methods: ['GET'])]
    public function tableRestorePage(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] BackupTarget $input,
        #[CurrentUser] AppUser $user,
    ): Response {
        if (!$this->access->database($input->name, $input->dbName) instanceof SqlClient) {
            throw $this->createNotFoundException('Database non trovato.');
        }

        return $this->render('schema/table_restore.html.twig', [
            'name' => $input->name,
            'db_name' => $input->dbName,
            'table' => $input->table ?? '',
            'backups' => $this->backups->visibleBackups($user),
        ]);
    }

    #[Route(path: '/table-restore-exec', name: 'app_schema_table_restore_exec', methods: ['POST'])]
    #[Audited(action: AuditActionEnum::BACKUP_RESTORE)]
    public function tableRestoreExec(
        #[MapRequestPayload] RestoreBackup $input,
        #[CurrentUser] AppUser $user): JsonResponse
    {
        $sqlClient = $this->access->database($input->name, $input->dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $backup = $this->backups->findVisible($user, $input->backupFilename);
        if (!$backup instanceof BackupDump) {
            return $this->json(['is_valid' => false, 'message' => 'File di backup non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $outcome = $this->backups->restore($sqlClient, $input->dbName, $backup);

        return $this->json([
            'is_valid' => $outcome->ok,
            'message' => $outcome->ok ? 'Ripristino completato.' : 'Errore durante il ripristino: ' . $outcome->error,
        ]);
    }
}
