<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\BackupDump;
use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
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

    /**
     * No system checks here: listing, rotation and relocation work without the MySQL client binaries,
     * and a missing binary must not break the container (every command and page depending on this class).
     * createBackup()/restoreBackup() report it instead.
     */
    public function __construct(?EffectivePrimitiveTypeIdentifierService $epti = null)
    {
        $epti ??= new EffectivePrimitiveTypeIdentifierService();

        $this->backupPath = $epti->getStringValueFromEnv(
            needle: 'BACKUP_PATH', trim: true, forceString: true, sanitizeHtml: false
        );
    }

    /**
     * Dumps a database (or one table) to BACKUP_PATH/{sqlClientId}/{dbName}/bkp_<date>_<db>_<table|full>.sql.
     *
     * The dump is written to "<name>.part" and renamed only when mysqldump succeeds, so a failed or
     * interrupted run never leaves a truncated file that the listing would offer for restore.
     * On failure "msg" holds mysqldump's error output (it may contain host and user names: log it, do not
     * show it to panel users).
     *
     * @return array{is_valid: bool, backup_filename: string, msg: string, output: list<string>}
     *
     * @throws \InvalidArgumentException when a name is invalid or the SqlClient is not persisted
     */
    public function createBackup(SqlClient $sqlClient, string $dbName, ?string $table = null): array
    {
        if (!preg_match('/^\w+$/', $dbName)) {
            throw new \InvalidArgumentException(\sprintf('Invalid database name: "%s". Only alphanumeric characters and underscores are allowed.', $dbName));
        }

        if (null !== $table && !preg_match('/^\w+$/', $table)) {
            throw new \InvalidArgumentException(\sprintf('Invalid table name: "%s". Only alphanumeric characters and underscores are allowed.', $table));
        }

        $sqlClientId = $sqlClient->getId()
            ?? throw new \InvalidArgumentException('Only a persisted SqlClient can be backed up: its id is part of the backup path.');
        $backupDir = \sprintf('%s/%d/%s', $this->backupPath, $sqlClientId, $dbName);

        if (!$this->ensureBackupDirectory($backupDir)) {
            return $this->backupFailure($this->backupPath, \sprintf('Unable to create the backup directory: %s', $backupDir));
        }

        $suffix = null !== $table ? '_' . $table : '_full';
        $backupFilename = $backupDir . '/bkp_' . new \DateTime()->format('Y-m-d_H-i-s') . '_' . $dbName . $suffix . '.sql';
        $partialFilename = $backupFilename . '.part';

        $arguments = [
            '--single-transaction', '--set-gtid-purged=OFF', '--triggers',
            ...(null === $table ? ['--routines', '--events'] : []),
            '--result-file=' . $partialFilename,
            $dbName,
            ...(null !== $table ? [$table] : []),
        ];

        $completed = false;
        try {
            $run = $this->runClient('mysqldump', $sqlClient, $arguments);
            $completed = $run['ok'] && rename($partialFilename, $backupFilename);
        } finally {
            if (!$completed && file_exists($partialFilename)) {
                unlink($partialFilename);
            }
        }

        if (!$completed) {
            return $this->backupFailure($backupFilename, $run['error'] ?: 'Unable to rename the completed dump.', $run['output']);
        }

        return ['is_valid' => true, 'backup_filename' => $backupFilename, 'msg' => 'Backup completed.', 'output' => $run['output']];
    }

    /**
     * @param list<string> $output
     *
     * @return array{is_valid: false, backup_filename: string, msg: string, output: list<string>}
     */
    private function backupFailure(string $backupFilename, string $msg, array $output = []): array
    {
        return ['is_valid' => false, 'backup_filename' => $backupFilename, 'msg' => $msg, 'output' => [] === $output ? [$msg] : $output];
    }

    /**
     * Runs a MySQL client binary (mysqldump, mysql) against $sqlClient, without a shell: arguments are passed
     * as an array, the password through a temporary option file (0600) instead of the command line.
     * No timeout: a dump or restore of a large database can take hours.
     *
     * @param list<string>  $arguments
     * @param resource|null $input     stream piped to the process standard input
     *
     * @return array{ok: bool, error: string, output: list<string>}
     */
    private function runClient(string $binary, SqlClient $sqlClient, array $arguments, mixed $input = null): array
    {
        $path = new ExecutableFinder()->find($binary);
        if (null === $path) {
            $error = \sprintf('The %s command was not found in the PATH of the user running PHP. Install the MySQL client (e.g. "apt install mysql-client").', $binary);

            return ['ok' => false, 'error' => $error, 'output' => [$error]];
        }

        $cnfFile = tempnam(sys_get_temp_dir(), $binary . '_');
        if (false === $cnfFile) {
            $error = \sprintf('Unable to create the temporary credentials file for %s.', $binary);

            return ['ok' => false, 'error' => $error, 'output' => [$error]];
        }

        try {
            chmod($cnfFile, 0600);
            file_put_contents($cnfFile, \sprintf("[client]\npassword=%s\n", addslashes((string) $sqlClient->getPassword())));

            // --defaults-extra-file must be the first option.
            $process = new Process([
                $path,
                '--defaults-extra-file=' . $cnfFile,
                '-h', (string) $sqlClient->getHost(),
                '-P', (string) $sqlClient->getPort(),
                '-u', (string) $sqlClient->getUsername(),
                ...$arguments,
            ]);
            $process->setTimeout(timeout: null);
            if (null !== $input) {
                $process->setInput($input);
            }

            $process->run();
        } finally {
            unlink($cnfFile);
        }

        $errorOutput = trim($process->getErrorOutput());
        $output = '' === $errorOutput ? [] : (preg_split('/\R/', $errorOutput) ?: []);
        if ($process->isSuccessful()) {
            return ['ok' => true, 'error' => '', 'output' => $output];
        }

        $error = '' !== $errorOutput ? $errorOutput : \sprintf('%s exited with code %d.', $binary, $process->getExitCode() ?? -1);

        return ['ok' => false, 'error' => $error, 'output' => [] === $output ? [$error] : $output];
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
     * Incomplete dumps ("bkp_*.sql.part", in the root or in a server/database folder). createBackup() removes
     * its own on failure, so the ones left behind come from a killed process (OOM, reboot): never restorable.
     *
     * @return list<BackupDump>
     */
    public function listPartialDumps(): array
    {
        if (!is_dir($this->backupPath)) {
            return [];
        }

        $partials = [];
        foreach ([...glob($this->backupPath . '/bkp_*.sql.part') ?: [], ...glob($this->backupPath . '/*/*/bkp_*.sql.part') ?: []] as $file) {
            $partials[] = $this->toBackupDump($file, substr($file, \strlen($this->backupPath) + 1), sqlClientId: null, dbName: null);
        }

        return $partials;
    }

    /**
     * Deletes a dump returned by this class (listing paths are always under BACKUP_PATH).
     * A file already gone (e.g. two rotations running at the same time) counts as deleted.
     */
    public function deleteBackup(BackupDump $dump): bool
    {
        if (!str_starts_with($dump->path, $this->backupPath . '/')) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not under BACKUP_PATH.', $dump->path));
        }

        clearstatcache(clear_realpath_cache: true, filename: $dump->path);

        return !file_exists($dump->path) || unlink($dump->path) || !file_exists($dump->path);
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
     * Loads a dump of BACKUP_PATH into $dbName with the mysql client (the file is streamed to its stdin).
     * On failure "msg" holds mysql's error output (log it, do not show it to panel users).
     *
     * @return array{is_valid: bool, backup_filename: string, msg: string, output: list<string>}
     *
     * @throws \InvalidArgumentException when a name is invalid or the file is outside BACKUP_PATH
     * @throws \RuntimeException         when the file cannot be read
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

        $dump = is_readable($realPath) ? fopen($realPath, 'r') : false;
        if (false === $dump) {
            throw new \RuntimeException(\sprintf('The backup file is not readable: %s', $realPath));
        }

        try {
            $run = $this->runClient('mysql', $sqlClient, [...(null !== $table ? ['--one-database'] : []), $dbName], $dump);
        } finally {
            fclose($dump);
        }

        return [
            'is_valid' => $run['ok'],
            'backup_filename' => $backupFilename,
            'msg' => $run['ok'] ? 'Restore completed.' : $run['error'],
            'output' => $run['output'],
        ];
    }
}
