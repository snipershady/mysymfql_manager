<?php

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
 */
final readonly class MysqldumpManager
{
    private string $backupPath;
    private EffectivePrimitiveTypeIdentifierService $epti;

    public function __construct()
    {
        $disabledFunctions = array_map(trim(...), explode(',', ini_get('disable_functions')));
        if (in_array('exec', $disabledFunctions, true)) {
            throw new \RuntimeException('The exec() function is disabled in PHP. To enable it, remove "exec" from "disable_functions" in the php.ini file (e.g. /etc/php/8.x/fpm/php.ini) and restart PHP-FPM.');
        }

        if (!shell_exec('which mysqldump 2>/dev/null')) {
            throw new \RuntimeException('The mysqldump command was not found in the system PATH. Install it with: "apt install mysql-client" (Debian/Ubuntu) or "yum install mysql" (RHEL/CentOS), then verify it is accessible in the PATH of the user running PHP.');
        }

        $this->epti = new EffectivePrimitiveTypeIdentifierService();

        $this->backupPath = $this->epti->getTypedValueFromEnv(
            needle: 'BACKUP_PATH', trim: true, forceString: true, sanitizeHtml: false
        );
    }

    public function createBackup(SqlClient $sqlClient, string $dbName, ?string $table = null): array
    {
        if (!preg_match('/^\w+$/', $dbName)) {
            throw new \InvalidArgumentException(sprintf('Invalid database name: "%s". Only alphanumeric characters and underscores are allowed.', $dbName));
        }

        if (null !== $table && !preg_match('/^\w+$/', $table)) {
            throw new \InvalidArgumentException(sprintf('Invalid table name: "%s". Only alphanumeric characters and underscores are allowed.', $table));
        }

        $host = $sqlClient->getHost();
        $user = $sqlClient->getUsername();
        $pass = $sqlClient->getPassword();
        $now = new \DateTime();
        $dateString = $now->format('Y-m-d_H-i-s');

        if (!is_dir($this->backupPath) && !mkdir($this->backupPath, 0755, true)) {
            $msg = sprintf('Unable to create the backup directory: %s', $this->backupPath);

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

        file_put_contents($cnfFile, sprintf("[client]\npassword=%s\n", addslashes((string) $pass)));
        chmod($cnfFile, 0600);

        try {
            $suffix = null !== $table ? '_' . $table : '_full';
            $backupFilename = $this->backupPath . '/bkp_' . $dateString . '_' . $dbName . $suffix . '.sql';
            $dbLevelFlags = null === $table ? '--routines --events' : '';
            $command = sprintf(
                'mysqldump --defaults-extra-file=%s -h %s -u %s --single-transaction --set-gtid-purged=OFF --triggers %s %s %s > %s',
                escapeshellarg($cnfFile),
                escapeshellarg((string) $host),
                escapeshellarg((string) $user),
                $dbLevelFlags,
                escapeshellarg($dbName),
                null !== $table ? escapeshellarg($table) : '',
                escapeshellarg($backupFilename)
            );
            $output = [];
            $resultCode = 0;
            $execResult = exec($command, $output, $resultCode);

            if (0 !== $resultCode && file_exists($backupFilename)) {
                unlink($backupFilename);
            }
        } finally {
            unlink($cnfFile);
        }

        return [
            'is_valid' => false !== $execResult && 0 === $resultCode,
            'exec_result' => $execResult,
            'output' => $output,
            'result_code' => $resultCode,
            'backup_filename' => $backupFilename,
            'msg' => 'ok',
        ];
    }

    /**
     * @param DatabaseOwner[] $allOwnedDatabased
     *
     * @return BackupDump[]
     */
    public function listBackups(AppUser $user, array $allOwnedDatabased): array
    {
        if (!is_dir($this->backupPath)) {
            return [];
        }

        $files = glob($this->backupPath . '/bkp_*.sql');

        if (false === $files) {
            return [];
        }

        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);

        $allowedDbNames = $isAdmin ? [] : array_map(
            fn (DatabaseOwner $o): string => $o->getDbName(),
            $allOwnedDatabased
        );

        $filtered = $isAdmin ? $files : array_filter($files, function (string $file) use ($allowedDbNames): bool {
            // Format: bkp_YYYY-MM-DD_HH-II-SS_dbName_suffix.sql
            // Remove the fixed prefix to isolate dbName_suffix.sql
            $rest = (string) preg_replace('/^bkp_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_/', '', basename($file));

            return array_any($allowedDbNames, fn (string $dbName): bool => str_starts_with($rest, $dbName . '_'));
        });

        $backups = array_map(fn (string $file): BackupDump => BackupDump::fromArray([
            'filename' => basename($file),
            'path' => $file,
            'size' => (int) filesize($file),
            'mtime' => (int) filemtime($file),
        ]), $filtered);

        usort($backups, fn (BackupDump $a, BackupDump $b): int => $b->mtime <=> $a->mtime);

        return $backups;
    }

    public function listAllBackups(): array
    {
        if (!is_dir($this->backupPath)) {
            return [];
        }

        $files = glob($this->backupPath . '/bkp_*.sql');

        if (false === $files) {
            return [];
        }

        $backups = [];
        foreach ($files as $file) {
            $backups[] = BackupDump::fromArray([
                'filename' => basename($file),
                'path' => $file,
                'size' => (int) filesize($file),
                'mtime' => (int) filemtime($file),
            ]);
        }

        usort($backups, fn (BackupDump $a, BackupDump $b): int => $b->mtime <=> $a->mtime);

        return $backups;
    }

    public function restoreBackup(SqlClient $sqlClient, string $dbName, string $backupFilename, ?string $table = null): array
    {
        if (!preg_match('/^\w+$/', $dbName)) {
            throw new \InvalidArgumentException(sprintf('Invalid database name: "%s". Only alphanumeric characters and underscores are allowed.', $dbName));
        }

        if (null !== $table && !preg_match('/^\w+$/', $table)) {
            throw new \InvalidArgumentException(sprintf('Invalid table name: "%s". Only alphanumeric characters and underscores are allowed.', $table));
        }

        $realPath = realpath($backupFilename);
        if (false === $realPath || !str_starts_with($realPath, realpath($this->backupPath) . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('The backup file must be located in the configured backup directory.');
        }

        if (!is_readable($realPath)) {
            throw new \RuntimeException(sprintf('The backup file is not readable: %s', $realPath));
        }

        $host = $sqlClient->getHost();
        $user = $sqlClient->getUsername();
        $pass = $sqlClient->getPassword();

        $cnfFile = tempnam(sys_get_temp_dir(), 'mysqlrestore_');
        if (false === $cnfFile) {
            throw new \RuntimeException('Unable to create the temporary file for mysql credentials.');
        }

        file_put_contents($cnfFile, sprintf("[client]\npassword=%s\n", addslashes((string) $pass)));
        chmod($cnfFile, 0600);

        try {
            $command = sprintf(
                'mysql --defaults-extra-file=%s -h %s -u %s %s %s < %s',
                escapeshellarg($cnfFile),
                escapeshellarg((string) $host),
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
