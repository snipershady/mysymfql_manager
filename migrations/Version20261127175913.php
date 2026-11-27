<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backup queue status: is_dequeued replaced by status (pending/running/done/failed), plus attempts,
 * started_date and the error of the last failed run. Existing rows keep their state:
 * not dequeued → pending; dequeued with completed_date → done; dequeued without completed_date → failed.
 */
final class Version20261127175913 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'backup_queue: status, attempts, started_date, error instead of is_dequeued';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE backup_queue ADD status VARCHAR(15) DEFAULT \'pending\' NOT NULL, ADD attempts INT DEFAULT 0 NOT NULL, ADD started_date DATETIME DEFAULT NULL, ADD error LONGTEXT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE backup_queue SET
                status = CASE
                    WHEN is_dequeued = 0 THEN 'pending'
                    WHEN completed_date IS NOT NULL THEN 'done'
                    ELSE 'failed'
                END,
                attempts = CASE WHEN is_dequeued = 0 THEN 0 ELSE 1 END,
                error = CASE
                    WHEN is_dequeued = 1 AND completed_date IS NULL THEN 'Esecuzione precedente all''introduzione dello stato: dettagli nel log.'
                    ELSE NULL
                END
            SQL);
        $this->addSql('ALTER TABLE backup_queue DROP is_dequeued');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE backup_queue ADD is_dequeued TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE backup_queue SET is_dequeued = CASE WHEN status = \'pending\' THEN 0 ELSE 1 END');
        $this->addSql('ALTER TABLE backup_queue DROP status, DROP attempts, DROP started_date, DROP error');
    }

    /**
     * MySQL commits implicitly on every DDL statement (see transactional: false in doctrine_migrations.yaml).
     */
    public function isTransactional(): bool
    {
        return false;
    }
}
