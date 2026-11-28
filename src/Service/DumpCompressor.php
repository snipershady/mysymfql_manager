<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\BackupCompressionEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Compresses new dumps with the format chosen in BACKUP_COMPRESSION (zstd by default, gzip or none) and reads
 * dumps back whatever their format, through the system binaries (no shell, arguments as an array, no timeout).
 *
 * Compression never costs a backup: when the tool is missing, BACKUP_COMPRESSION is not valid or compression
 * fails, the dump stays uncompressed and the reason is logged (warning) and returned.
 * The configuration is read lazily, so a wrong value never breaks the container (and the pages using it).
 *
 * @see \App\Tests\Service\DumpCompressorTest
 */
final readonly class DumpCompressor
{
    public function __construct(
        #[Autowire(env: 'BACKUP_COMPRESSION')]
        private string $configured = 'zstd',
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Format of the new dumps; null when BACKUP_COMPRESSION is not one of none, gzip, zstd.
     */
    public function configured(): ?BackupCompressionEnum
    {
        return BackupCompressionEnum::tryFrom(strtolower(trim($this->configured)));
    }

    /**
     * Compresses $file in place with the configured format.
     *
     * @return array{path: string, compression: BackupCompressionEnum, warning: ?string} the file to keep: the
     *                                                                                   compressed one, or $file itself (uncompressed, with the reason as warning)
     */
    public function compress(string $file): array
    {
        $compression = $this->configured();
        if (!$compression instanceof BackupCompressionEnum) {
            return $this->uncompressed($file, \sprintf('BACKUP_COMPRESSION="%s" is not valid (none, gzip, zstd): the dump was kept uncompressed.', $this->configured));
        }

        $binary = $compression->binary();
        if (null === $binary) {
            return $this->uncompressed($file);
        }

        $path = new ExecutableFinder()->find($binary);
        if (null === $path) {
            return $this->uncompressed($file, \sprintf('The %s command was not found in the PATH of the user running PHP (install it, e.g. "apt install %s"): the dump was kept uncompressed.', $binary, $binary));
        }

        $compressed = $file . $compression->extension();
        $process = new Process([$path, ...$compression->compressArguments($file)]);
        $process->setTimeout(timeout: null);
        $process->run();

        clearstatcache();
        if (!$process->isSuccessful() || !is_file($compressed)) {
            if (is_file($compressed)) {
                unlink($compressed);
            }

            return $this->uncompressed($file, \sprintf('%s failed (%s): the dump was kept uncompressed.', $binary, trim($process->getErrorOutput()) ?: 'exit code ' . ($process->getExitCode() ?? -1)));
        }

        // gzip and zstd --rm remove the source once the compressed file is complete.
        if (is_file($file)) {
            unlink($file);
        }

        return ['path' => $compressed, 'compression' => $compression, 'warning' => null];
    }

    /**
     * Writes the decompressed content of $file to $target.
     *
     * @return array{ok: bool, error: string}
     */
    public function decompressTo(string $file, string $target): array
    {
        $directory = \dirname($target);
        $output = is_dir($directory) && is_writable($directory) && !file_exists($target) ? fopen($target, 'x') : false;
        if (false === $output) {
            return ['ok' => false, 'error' => \sprintf('Unable to create the temporary file %s.', $target)];
        }

        try {
            chmod($target, 0600);
            $error = $this->stream($file, static function (string $chunk) use ($output): void {
                fwrite($output, $chunk);
            });
        } finally {
            fclose($output);
        }

        return ['ok' => null === $error, 'error' => $error ?? ''];
    }

    /**
     * Passes the decompressed content of $file to $write, chunk by chunk (constant memory, whatever the size).
     *
     * @param callable(string): void $write
     *
     * @return string|null null on success, otherwise the error
     */
    public function stream(string $file, callable $write): ?string
    {
        $compression = BackupCompressionEnum::fromFilename($file);
        $binary = $compression->binary();
        if (null === $binary) {
            return $this->streamFile($file, $write);
        }

        $path = new ExecutableFinder()->find($binary);
        if (null === $path) {
            return \sprintf('The %s command was not found in the PATH of the user running PHP: it is needed to read %s dumps (e.g. "apt install %s").', $binary, $compression->extension(), $binary);
        }

        $process = new Process([$path, ...$compression->decompressArguments($file)]);
        $process->setTimeout(timeout: null);
        $process->start();
        foreach ($process->getIterator(Process::ITER_SKIP_ERR) as $chunk) {
            $write($chunk);
        }

        $process->wait();

        return $process->isSuccessful()
            ? null
            : (trim($process->getErrorOutput()) ?: \sprintf('%s exited with code %d.', $binary, $process->getExitCode() ?? -1));
    }

    /**
     * @param callable(string): void $write
     */
    private function streamFile(string $file, callable $write): ?string
    {
        $input = is_readable($file) ? fopen($file, 'r') : false;
        if (false === $input) {
            return \sprintf('The backup file is not readable: %s', $file);
        }

        try {
            while (!feof($input)) {
                $chunk = fread($input, 1 << 20);
                if (false === $chunk) {
                    return \sprintf('Error while reading %s', $file);
                }

                $write($chunk);
            }
        } finally {
            fclose($input);
        }

        return null;
    }

    /**
     * @return array{path: string, compression: BackupCompressionEnum, warning: ?string}
     */
    private function uncompressed(string $file, ?string $warning = null): array
    {
        if (null !== $warning) {
            $this->logger->warning('Backup compression: {warning}', ['warning' => $warning, 'file' => $file]);
        }

        return ['path' => $file, 'compression' => BackupCompressionEnum::NONE, 'warning' => $warning];
    }
}
