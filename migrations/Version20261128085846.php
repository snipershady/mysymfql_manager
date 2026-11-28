<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline: the whole application catalog (users, managed servers, database ownerships, backup queue with its
 * status, Messenger table) as mapped by the entities. Replaces Version20261126225626 and Version20261127175913.
 */
final class Version20261128085846 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline schema of the application catalog';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(180) NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_USERNAME (username), UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE backup_queue (id INT AUTO_INCREMENT NOT NULL, db_name VARCHAR(63) NOT NULL, table_name VARCHAR(63) DEFAULT NULL, status VARCHAR(15) DEFAULT \'pending\' NOT NULL, attempts INT DEFAULT 0 NOT NULL, started_date DATETIME DEFAULT NULL, error LONGTEXT DEFAULT NULL, request_date DATETIME NOT NULL, completed_date DATETIME DEFAULT NULL, sql_client_id INT NOT NULL, owner_id INT NOT NULL, INDEX IDX_86DFA0895A963FE6 (sql_client_id), INDEX IDX_86DFA0897E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE database_owner (id INT AUTO_INCREMENT NOT NULL, db_name VARCHAR(63) NOT NULL, owner_id INT NOT NULL, sql_client_id INT NOT NULL, UNIQUE INDEX uq_db_owner (db_name, owner_id, sql_client_id), INDEX IDX_B07896857E3C61F9 (owner_id), INDEX IDX_B07896855A963FE6 (sql_client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE sql_client (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(31) NOT NULL, host VARCHAR(31) NOT NULL, username VARCHAR(31) NOT NULL, password VARCHAR(255) NOT NULL, port INT NOT NULL, INDEX idx_sql_client_host (host), UNIQUE INDEX uq_sql_client_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE sql_client_app_user (sql_client_id INT NOT NULL, app_user_id INT NOT NULL, INDEX IDX_F66C9C935A963FE6 (sql_client_id), INDEX IDX_F66C9C934A3353D8 (app_user_id), PRIMARY KEY (sql_client_id, app_user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE backup_queue ADD CONSTRAINT FK_86DFA0895A963FE6 FOREIGN KEY (sql_client_id) REFERENCES sql_client (id)');
        $this->addSql('ALTER TABLE backup_queue ADD CONSTRAINT FK_86DFA0897E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE database_owner ADD CONSTRAINT FK_B07896857E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE database_owner ADD CONSTRAINT FK_B07896855A963FE6 FOREIGN KEY (sql_client_id) REFERENCES sql_client (id)');
        $this->addSql('ALTER TABLE sql_client_app_user ADD CONSTRAINT FK_F66C9C935A963FE6 FOREIGN KEY (sql_client_id) REFERENCES sql_client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sql_client_app_user ADD CONSTRAINT FK_F66C9C934A3353D8 FOREIGN KEY (app_user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE backup_queue DROP FOREIGN KEY FK_86DFA0895A963FE6');
        $this->addSql('ALTER TABLE backup_queue DROP FOREIGN KEY FK_86DFA0897E3C61F9');
        $this->addSql('ALTER TABLE database_owner DROP FOREIGN KEY FK_B07896857E3C61F9');
        $this->addSql('ALTER TABLE database_owner DROP FOREIGN KEY FK_B07896855A963FE6');
        $this->addSql('ALTER TABLE sql_client_app_user DROP FOREIGN KEY FK_F66C9C935A963FE6');
        $this->addSql('ALTER TABLE sql_client_app_user DROP FOREIGN KEY FK_F66C9C934A3353D8');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE backup_queue');
        $this->addSql('DROP TABLE database_owner');
        $this->addSql('DROP TABLE sql_client');
        $this->addSql('DROP TABLE sql_client_app_user');
        $this->addSql('DROP TABLE messenger_messages');
    }

    /**
     * MySQL commits implicitly on every DDL statement (see transactional: false in doctrine_migrations.yaml).
     */
    public function isTransactional(): bool
    {
        return false;
    }
}
