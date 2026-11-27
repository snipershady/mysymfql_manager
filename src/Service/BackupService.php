<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\BackupDump;
use App\Dto\BackupOutcome;
use App\Entity\AppUser;
use App\Entity\BackupQueue;
use App\Entity\SqlClient;
use App\Repository\DatabaseOwnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Backup use cases of the panel: immediate dump, queued dump, restore, and the backups a user may see.
 *
 * Panel role/ownership of the server and database is checked by the caller (SqlClientAccessResolver);
 * the visibility of existing dumps is enforced here: admins see every dump (legacy flat ones included),
 * other users only those under a (server, database) pair they own. MySQL privileges on the SqlClient
 * account: those of mysqldump (SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES) for a backup, those of the
 * statements in the dump for a restore. Failures are logged and returned with the native error output.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\Service\BackupServiceTest
 */
final readonly class BackupService
{
    public function __construct(
        private MysqldumpManager $mysqldumpManager,
        private DatabaseOwnerRepository $databaseOwnerRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<BackupDump>
     */
    public function visibleBackups(AppUser $user): array
    {
        return $this->mysqldumpManager->listBackups($user, $this->databaseOwnerRepository->findAllByOwner($user));
    }

    /**
     * A dump visible to $user, by its id (path relative to BACKUP_PATH); null when it does not exist or is
     * not visible (the caller answers 404 in both cases).
     */
    public function findVisible(AppUser $user, string $id): ?BackupDump
    {
        return array_find($this->visibleBackups($user), static fn (BackupDump $dump): bool => $dump->id === $id);
    }

    /**
     * @throws \RuntimeException when the file cannot be read
     */
    public function contents(BackupDump $dump): string
    {
        $content = file_get_contents($dump->path);
        if (false === $content) {
            throw new \RuntimeException(\sprintf('Unable to read the backup file "%s".', $dump->id));
        }

        return $content;
    }

    /**
     * Dumps $dbName (or one table) now, in the request.
     */
    public function backupNow(SqlClient $sqlClient, string $dbName, ?string $table): BackupOutcome
    {
        $result = $this->mysqldumpManager->createBackup($sqlClient, $dbName, $table);
        $filename = basename($result['backup_filename']);
        if ($result['is_valid']) {
            return BackupOutcome::success($filename);
        }

        $this->logger->error('Backup failed: {error}', ['error' => $result['msg'], 'server' => $sqlClient->getName(), 'db' => $dbName, 'table' => $table]);

        return BackupOutcome::failure($filename, $result['msg']);
    }

    /**
     * Queues a dump for app:process-backup-queue (cron, FIFO).
     */
    public function enqueue(AppUser $user, SqlClient $sqlClient, string $dbName, ?string $table): BackupQueue
    {
        $entry = new BackupQueue()
            ->setDbName($dbName)
            ->setOwner($user)
            ->setSqlClient($sqlClient)
            ->setTable($table);

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    /**
     * Loads a visible dump into $dbName.
     */
    public function restore(SqlClient $sqlClient, string $dbName, BackupDump $dump): BackupOutcome
    {
        $result = $this->mysqldumpManager->restoreBackup($sqlClient, $dbName, $dump->path);
        if ($result['is_valid']) {
            return BackupOutcome::success($dump->filename);
        }

        $this->logger->error('Restore failed: {error}', ['error' => $result['msg'], 'server' => $sqlClient->getName(), 'db' => $dbName, 'backup' => $dump->id]);

        return BackupOutcome::failure($dump->filename, $result['msg']);
    }

    public function delete(BackupDump $dump): bool
    {
        $deleted = $this->mysqldumpManager->deleteBackup($dump);
        if (!$deleted) {
            $this->logger->error('Backup deletion failed.', ['backup' => $dump->id]);
        }

        return $deleted;
    }
}
