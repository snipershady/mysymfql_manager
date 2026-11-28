<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128131531 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_log (id INT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, actor_username VARCHAR(180) NOT NULL, action VARCHAR(40) NOT NULL, outcome VARCHAR(15) NOT NULL, actor_id INT DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, server_name VARCHAR(31) DEFAULT NULL, db_name VARCHAR(63) DEFAULT NULL, target VARCHAR(255) DEFAULT NULL, details JSON DEFAULT NULL, message LONGTEXT DEFAULT NULL, INDEX idx_audit_log_occurred_at (occurred_at), INDEX idx_audit_log_action (action, occurred_at), INDEX idx_audit_log_actor (actor_username, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE audit_log');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
