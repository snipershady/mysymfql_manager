<?php

declare(strict_types=1);

namespace App\Controller\Schema;

use App\Dto\BackupDump;
use App\Dto\Input\BackupFile;
use App\Dto\Input\BackupTarget;
use App\Dto\Input\RestoreBackup;
use App\Entity\AppUser;
use App\Entity\BackupQueue;
use App\Entity\SqlClient;
use App\Repository\DatabaseOwnerRepository;
use App\Security\SqlClientAccessResolver;
use App\Service\MysqldumpManager;
use Doctrine\ORM\EntityManagerInterface;
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
    ) {
    }

    #[Route(path: '/table-backup', name: 'app_schema_table_backup', methods: ['POST'])]
    public function tableBackup(
        #[MapRequestPayload] BackupTarget $input,
        MysqldumpManager $mysqldumpManager): JsonResponse
    {
        $name = $input->name;
        $dbName = $input->dbName;
        $table = $input->table;

        $sqlClient = $this->access->database($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $result = $mysqldumpManager->createBackup($sqlClient, $dbName, $table);

        return $this->json([
            'is_valid' => $result['is_valid'],
            'message' => $result['is_valid'] ? 'Backup completato.' : 'Errore durante il backup.',
            'backup_filename' => basename($result['backup_filename']),
            'msg' => $result['msg'],
        ]);
    }

    #[Route(path: '/enqueue-backup', name: 'app_schema_enqueue_backup', methods: ['POST'])]
    public function enqueueBackup(
        #[MapRequestPayload] BackupTarget $input,
        #[CurrentUser] AppUser $user,
        MysqldumpManager $mysqldumpManager,
        EntityManagerInterface $entityManagerInterface): JsonResponse
    {
        $name = $input->name;
        $dbName = $input->dbName;
        $table = $input->table;

        $sqlClient = $this->access->database($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $databaseQueue = new BackupQueue();
        $databaseQueue
                ->setDbName($dbName)
                ->setOwner($user)
                ->setSqlClient($sqlClient)
                ->setTable($table);
        try {
            $entityManagerInterface->persist($databaseQueue);
            $entityManagerInterface->flush();
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
    public function backupList(#[CurrentUser] AppUser $user, MysqldumpManager $mysqldumpManager, DatabaseOwnerRepository $databaseOwnerRepository): Response
    {
        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);

        return $this->render('schema/backup_list.html.twig', [
            'backups' => $mysqldumpManager->listBackups($user, $allOwnedDatabased),
        ]);
    }

    #[Route(path: '/backup-view', name: 'app_schema_backup_view', methods: ['GET'])]
    public function backupView(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] BackupFile $input,
        #[CurrentUser] AppUser $user, MysqldumpManager $mysqldumpManager, DatabaseOwnerRepository $databaseOwnerRepository): Response
    {
        $filename = $input->filename;

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);

        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);
        $backup = array_find($backups, static fn (BackupDump $b): bool => $b->id === $filename);

        if (!$backup) {
            throw $this->createNotFoundException('File di backup non trovato.');
        }

        $content = file_get_contents($backup->path);
        if (false === $content) {
            throw new \RuntimeException('Unable to read the backup file.');
        }

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route(path: '/backup-download', name: 'app_schema_backup_download', methods: ['GET'])]
    public function backupDownload(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] BackupFile $input,
        #[CurrentUser] AppUser $user,
        MysqldumpManager $mysqldumpManager,
        DatabaseOwnerRepository $allOwnedDatabased,
        DatabaseOwnerRepository $databaseOwnerRepository): BinaryFileResponse
    {
        $filename = $input->filename;

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);
        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);
        $backup = array_find($backups, static fn (BackupDump $b): bool => $b->id === $filename);

        if (!$backup) {
            throw $this->createNotFoundException('File di backup non trovato.');
        }

        $response = new BinaryFileResponse($backup->path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $backup->filename);

        return $response;
    }

    #[Route(path: '/backup-delete', name: 'app_schema_backup_delete', methods: ['POST'])]
    public function backupDelete(
        #[MapRequestPayload] BackupFile $input,
        #[CurrentUser] AppUser $user, MysqldumpManager $mysqldumpManager, DatabaseOwnerRepository $databaseOwnerRepository): JsonResponse
    {
        $filename = $input->filename;

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);
        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);
        $backup = array_find($backups, static fn (BackupDump $b): bool => $b->id === $filename);

        if (!$backup) {
            return $this->json(['is_valid' => false, 'message' => 'File di backup non trovato.'], Response::HTTP_NOT_FOUND);
        }

        if (!unlink($backup->path)) {
            return $this->json(['is_valid' => false, 'message' => 'Impossibile eliminare il file.'], 500);
        }

        return $this->json(['is_valid' => true, 'message' => 'Backup eliminato.']);
    }

    #[Route(path: '/table-restore', name: 'app_schema_table_restore', methods: ['GET'])]
    public function tableRestorePage(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] BackupTarget $input,
        #[CurrentUser] AppUser $user,
        MysqldumpManager $mysqldumpManager,
        DatabaseOwnerRepository $databaseOwnerRepository
    ): Response {
        $name = $input->name;
        $dbName = $input->dbName;
        $table = $input->table ?? '';
        if (!$this->access->database($name, $dbName) instanceof SqlClient) {
            throw $this->createNotFoundException('Database non trovato.');
        }

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);
        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);

        return $this->render('schema/table_restore.html.twig', [
            'name' => $name,
            'db_name' => $dbName,
            'table' => $table,
            'backups' => $backups,
        ]);
    }

    #[Route(path: '/table-restore-exec', name: 'app_schema_table_restore_exec', methods: ['POST'])]
    public function tableRestoreExec(
        #[MapRequestPayload] RestoreBackup $input,
        #[CurrentUser] AppUser $user,
        MysqldumpManager $mysqldumpManager,
        DatabaseOwnerRepository $databaseOwnerRepository): JsonResponse
    {
        $name = $input->name;
        $dbName = $input->dbName;
        $backupFilename = $input->backupFilename;

        $sqlClient = $this->access->database($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);
        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);
        $selectedBackup = array_find($backups, static fn (BackupDump $backup): bool => $backup->id === $backupFilename);

        if (!$selectedBackup) {
            return $this->json(['is_valid' => false, 'message' => 'File di backup non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $result = $mysqldumpManager->restoreBackup($sqlClient, $dbName, $selectedBackup->path);

        return $this->json([
            'is_valid' => $result['is_valid'],
            'message' => $result['is_valid'] ? 'Ripristino completato.' : 'Errore durante il ripristino.',
        ]);
    }
}
