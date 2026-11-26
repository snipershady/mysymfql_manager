<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\BackupDump;
use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

/**
 * Description of MysqldumpManager.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\Service\MysqldumpManagerTest
 */
final readonly class MysqldumpManager
{
    private string $backupPath;

    public function __construct(?EffectivePrimitiveTypeIdentifierService $epti = null)
    {
        $disabledFunctions = array_map(trim(...), explode(',', \ini_get('disable_functions') ?: ''));
        if (\in_array('exec', $disabledFunctions, strict: true)) {
            throw new \RuntimeException('The exec() function is disabled in PHP. To enable it, remove "exec" from "disable_functions" in the php.ini file (e.g. /etc/php/8.x/fpm/php.ini) and restart PHP-FPM.');
        }

        if (!shell_exec('which mysqldump 2>/dev/null')) {
            throw new \RuntimeException('The mysqldump command was not found in the system PATH. Install it with: "apt install mysql-client" (Debian/Ubuntu) or "yum install mysql" (RHEL/CentOS), then verify it is accessible in the PATH of the user running PHP.');
        }

        $epti ??= new EffectivePrimitiveTypeIdentifierService();

        $this->backupPath = $epti->getStringValueFromEnv(
            needle: 'BACKUP_PATH', trim: true, forceString: true, sanitizeHtml: false
        );
    }

    /**
     * @return array{is_valid: bool, exec_result: string|false|null, output: list<string>|null, result_code: int|null, backup_filename: string, msg: string}
     */
    public function createBackup(SqlClient $sqlClient, string $dbName, ?string $table = null): array
    {
        if (!preg_match('/^\w+$/', $dbName)) {
            throw new \InvalidArgumentException(\sprintf('Invalid database name: "%s". Only alphanumeric characters and underscores are allowed.', $dbName));
        }

        if (null !== $table && !preg_match('/^\w+$/', $table)) {
            throw new \InvalidArgumentException(\sprintf('Invalid table name: "%s". Only alphanumeric characters and underscores are allowed.', $table));
        }

        $host = $sqlClient->getHost();
        $user = $sqlClient->getUsername();
        $pass = $sqlClient->getPassword();
        $now = new \DateTime();
        $dateString = $now->format('Y-m-d_H-i-s');

        $sqlClientId = $sqlClient->getId()
            ?? throw new \InvalidArgumentException('Only a persisted SqlClient can be backed up: its id is part of the backup path.');
        $backupDir = \sprintf('%s/%d/%s', $this->backupPath, $sqlClientId, $dbName);

        if (!$this->ensureBackupDirectory($backupDir)) {
            $msg = \sprintf('Unable to create the backup directory: %s', $backupDir);

            // throw new \RuntimeException(sprintf('Unable to create the backup directory: %s', $this->backupPath));
            return [
                'is_valid' => false,
                'exec_result' => null,
                'output' => null,
                'result_code' => null,
                'backup_filename' => $this->backupPath,
                'msg' => $msg,
            ];
        }

        // Avoid exposing the database password in plaintext
        $cnfFile = tempnam(sys_get_temp_dir(), 'mysqldump_');
        if (false === $cnfFile) {
            $msg = 'Unable to create the temporary file for mysqldump credentials.';

            // throw new \RuntimeException($msg);
            return [
                'is_valid' => false,
                'exec_result' => null,
                'output' => null,
                'result_code' => null,
                'backup_filename' => $this->backupPath,
                'msg' => $msg,
            ];
        }

        file_put_contents($cnfFile, \sprintf("[client]\npassword=%s\n", addslashes((string) $pass)));
        chmod($cnfFile, 0600);

        $suffix = null !== $table ? '_' . $table : '_full';
        $backupFilename = $backupDir . '/bkp_' . $dateString . '_' . $dbName . $suffix . '.sql';
        $partialFilename = $backupFilename . '.part';
        $completed = false;
        try {
            $dbLevelFlags = null === $table ? '--routines --events' : '';
            $command = \sprintf(
                'mysqldump --defaults-extra-file=%s -h %s -P %d -u %s --single-transaction --set-gtid-purged=OFF --triggers %s %s %s > %s',
                escapeshellarg($cnfFile),
                escapeshellarg((string) $host),
                $sqlClient->getPort(),
                escapeshellarg((string) $user),
                $dbLevelFlags,
                escapeshellarg($dbName),
                null !== $table ? escapeshellarg($table) : '',
                escapeshellarg($partialFilename)
            );
            $output = [];
            $resultCode = 0;
            $execResult = exec($command, $output, $resultCode);

            // The dump is written to "<name>.part" and renamed only when complete: an interrupted or failed
            // run never leaves a truncated bkp_*.sql that the listing would offer for restore.
            if (false !== $execResult && 0 === $resultCode && rename($partialFilename, $backupFilename)) {
                $completed = true;
            }
        } finally {
            unlink($cnfFile);
            if (!$completed && file_exists($partialFilename)) {
                unlink($partialFilename);
            }
        }

        return [
            'is_valid' => $completed,
            'exec_result' => $execResult,
            'output' => $output,
            'result_code' => $resultCode,
            'backup_filename' => $backupFilename,
            'msg' => 'ok',
        ];
    }

    /**
     * Backups visible to $user: admins see everything (legacy flat dumps included);
     * other users only dumps stored under a (server id, database) pair they own.
     *
     * @param DatabaseOwner[] $allOwnedDatabased
     *
     * @return list<BackupDump>
     */
    public function listBackups(AppUser $user, array $allOwnedDatabased): array
    {
        if (\in_array('ROLE_ADMIN', $user->getRoles(), strict: true)) {
            return $this->listAllBackups();
        }

        $allowed = [];
        foreach ($allOwnedDatabased as $owned) {
            $sqlClientId = $owned->getSqlClient()?->getId();
            $dbName = $owned->getDbName();
            if (null !== $sqlClientId && null !== $dbName) {
                $allowed[$sqlClientId . '/' . $dbName] = true;
            }
        }

        return array_values(array_filter(
            $this->listAllBackups(),
            static fn (BackupDump $b): bool => null !== $b->sqlClientId && isset($allowed[$b->sqlClientId . '/' . $b->dbName])
        ));
    }

    /**
     * Every dump under BACKUP_PATH, newest first: structured dumps ({sqlClientId}/{dbName}/bkp_*.sql)
     * plus legacy flat dumps (bkp_*.sql in the root, not attributable to a server).
     *
     * @return list<BackupDump>
     */
    public function listAllBackups(): array
    {
        if (!is_dir($this->backupPath)) {
            return [];
        }

        $backups = [];
        foreach (glob($this->backupPath . '/bkp_*.sql') ?: [] as $file) {
            $backups[] = $this->toBackupDump($file, basename($file), sqlClientId: null, dbName: null);
        }

        foreach (glob($this->backupPath . '/*/*/bkp_*.sql') ?: [] as $file) {
            $relative = substr($file, \strlen($this->backupPath) + 1);
            [$serverDir, $dbDir] = explode('/', $relative, 3);
            // Only directories this class creates: numeric server id and \w+ database name.
            if (!ctype_digit($serverDir) || !preg_match('/^\w+$/', $dbDir)) {
                continue;
            }

            $backups[] = $this->toBackupDump($file, $relative, (int) $serverDir, $dbDir);
        }

        usort($backups, static fn (BackupDump $a, BackupDump $b): int => $b->mtime <=> $a->mtime);

        return $backups;
    }

    /**
     * Legacy flat dumps (BACKUP_PATH/bkp_*.sql), not attributable to a server.
     *
     * @return list<BackupDump>
     */
    public function listLegacyBackups(): array
    {
        return array_values(array_filter($this->listAllBackups(), static fn (BackupDump $b): bool => null === $b->sqlClientId));
    }

    /**
     * Moves a legacy flat dump to BACKUP_PATH/{sqlClientId}/{dbName}/ (same filename), making it visible
     * to the owners of that database. Returns the new path.
     *
     * @throws \InvalidArgumentException when the dump is not a legacy one, the database name is invalid or the client is not persisted
     * @throws \RuntimeException         when the target exists or the file cannot be moved
     */
    public function relocateLegacyBackup(BackupDump $legacy, SqlClient $sqlClient, string $dbName): string
    {
        if (null !== $legacy->sqlClientId || \dirname($legacy->path) !== $this->backupPath) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a legacy dump in the root of BACKUP_PATH.', $legacy->id));
        }

        if (!preg_match('/^\w+$/', $dbName)) {
            throw new \InvalidArgumentException(\sprintf('Invalid database name: "%s".', $dbName));
        }

        $sqlClientId = $sqlClient->getId() ?? throw new \InvalidArgumentException('Only a persisted SqlClient can own a backup.');
        $targetDir = \sprintf('%s/%d/%s', $this->backupPath, $sqlClientId, $dbName);
        $target = $targetDir . '/' . $legacy->filename;

        if (file_exists($target)) {
            throw new \RuntimeException(\sprintf('Target already exists: %s', $target));
        }

        if (!$this->ensureBackupDirectory($targetDir) || !rename($legacy->path, $target)) {
            throw new \RuntimeException(\sprintf('Unable to move %s to %s', $legacy->path, $target));
        }

        return $target;
    }

    /**
     * Creates BACKUP_PATH/{id}/{db} giving every new level the same permission bits as BACKUP_PATH:
     * the web user (www-data) and the CLI/cron user both write dumps here, so the operator decides
     * the access policy once, on the root directory (see "Note operative" in CLAUDE.md).
     */
    private function ensureBackupDirectory(string $backupDir): bool
    {
        if (is_dir($backupDir)) {
            return true;
        }

        if (!is_dir($this->backupPath) && !mkdir($this->backupPath, 0770, recursive: true)) {
            return false;
        }

        $mode = fileperms($this->backupPath) & 0o777;
        $current = $this->backupPath;
        foreach (explode('/', substr($backupDir, \strlen($this->backupPath) + 1)) as $segment) {
            $current .= '/' . $segment;
            if (is_dir($current)) {
                continue;
            }

            // chmod() as well: mkdir() is subject to the process umask.
            if ((!mkdir($current, $mode) && !is_dir($current)) || !chmod($current, $mode)) {
                return false;
            }
        }

        return true;
    }

    private function toBackupDump(string $file, string $id, ?int $sqlClientId, ?string $dbName): BackupDump
    {
        return new BackupDump(
            filename: basename($file),
            path: $file,
            size: (int) filesize($file),
            mtime: (int) filemtime($file),
            id: $id,
            sqlClientId: $sqlClientId,
            dbName: $dbName,
        );
    }

    /**
     * @return array<string, bool|string|int|list<string>>
     */
    public function restoreBackup(SqlClient $sqlClient, string $dbName, string $backupFilename, ?string $table = null): array
    {
        if (!preg_match('/^\w+$/', $dbName)) {
            throw new \InvalidArgumentException(\sprintf('Invalid database name: "%s". Only alphanumeric characters and underscores are allowed.', $dbName));
        }

        if (null !== $table && !preg_match('/^\w+$/', $table)) {
            throw new \InvalidArgumentException(\sprintf('Invalid table name: "%s". Only alphanumeric characters and underscores are allowed.', $table));
        }

        $realPath = realpath($backupFilename);
        if (false === $realPath || !str_starts_with($realPath, realpath($this->backupPath) . \DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('The backup file must be located in the configured backup directory.');
        }

        if (!is_readable($realPath)) {
            throw new \RuntimeException(\sprintf('The backup file is not readable: %s', $realPath));
        }

        $host = $sqlClient->getHost();
        $user = $sqlClient->getUsername();
        $pass = $sqlClient->getPassword();

        $cnfFile = tempnam(sys_get_temp_dir(), 'mysqlrestore_');
        if (false === $cnfFile) {
            throw new \RuntimeException('Unable to create the temporary file for mysql credentials.');
        }

        file_put_contents($cnfFile, \sprintf("[client]\npassword=%s\n", addslashes((string) $pass)));
        chmod($cnfFile, 0600);

        try {
            $command = \sprintf(
                'mysql --defaults-extra-file=%s -h %s -P %d -u %s %s %s < %s',
                escapeshellarg($cnfFile),
                escapeshellarg((string) $host),
                $sqlClient->getPort(),
                escapeshellarg((string) $user),
                null !== $table ? '--one-database' : '',
                escapeshellarg($dbName),
                escapeshellarg($realPath)
            );
            $output = [];
            $resultCode = 0;
            $execResult = exec($command, $output, $resultCode);
        } finally {
            unlink($cnfFile);
        }

        return [
            'is_valid' => false !== $execResult && 0 === $resultCode,
            'exec_result' => $execResult,
            'output' => $output,
            'result_code' => $resultCode,
            'backup_filename' => $backupFilename,
        ];
    }
}
