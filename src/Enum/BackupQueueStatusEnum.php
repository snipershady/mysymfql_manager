<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle of a queued backup (BackupQueue::$status): pending → running (claimed by
 * app:process-backup-queue) → done | failed. A failed entry can be put back to pending from the dashboard.
 * An entry left "running" by an interrupted worker stays so (the started date shows how long ago).
 */
enum BackupQueueStatusEnum: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case DONE = 'done';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'In attesa',
            self::RUNNING => 'In corso',
            self::DONE => 'Completato',
            self::FAILED => 'Fallito',
        };
    }

    /**
     * Bootstrap contextual colour of the status badge.
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'secondary',
            self::RUNNING => 'info',
            self::DONE => 'success',
            self::FAILED => 'danger',
        };
    }
}
