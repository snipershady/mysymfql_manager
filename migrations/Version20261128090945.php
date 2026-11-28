<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit log: one row per audited operation (managed servers and panel management), kept at most 90 days
 * (app:audit-prune). User and server are stored by value, without foreign keys, so entries survive their deletion.
 */
final class Version20261128090945 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'audit_log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_log (id INT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, actor_username VARCHAR(180) NOT NULL, action VARCHAR(40) NOT NULL, outcome VARCHAR(15) NOT NULL, actor_id INT DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, server_name VARCHAR(31) DEFAULT NULL, db_name VARCHAR(63) DEFAULT NULL, target VARCHAR(255) DEFAULT NULL, details JSON DEFAULT NULL, message LONGTEXT DEFAULT NULL, INDEX idx_audit_log_occurred_at (occurred_at), INDEX idx_audit_log_action (action, occurred_at), INDEX idx_audit_log_actor (actor_username, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_log');
    }

    /**
     * MySQL commits implicitly on every DDL statement (see transactional: false in doctrine_migrations.yaml).
     */
    public function isTransactional(): bool
    {
        return false;
    }
}
