<?php

declare(strict_types=1);

namespace App\RepositoryPDO;

use App\Enum\CharsetEnum;
use App\Enum\CollationEnum;
use App\Exception\RepositoryException;

/**
 * Demo tables used by the development fixtures (dummy-fixtures command) and by the test suites.
 * They are created in the schema currently selected with {@see DatabaseSchemaRepository::useDbName()}
 * (the connection, and so the USE, is shared by the repositories of the same server).
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
readonly class DemoDataRepository extends AbstractManagerRepositoryPDO
{
    public function createDummyTable(): bool
    {
        $query = \sprintf("CREATE TABLE `jujutsu_kaisen_cast` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nome` VARCHAR(100) NOT NULL,
                `tecnica` VARCHAR(255) NOT NULL,
                PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=%s COLLATE=%s 
                  COMMENT='Tabella dedicata agli stregoni e maledizioni di JJK';", CharsetEnum::UTF8MB4->value,
            CollationEnum::UTF8MB4_0900_AI_CI->value);
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function populateDummiTable(): bool
    {
        $query = "INSERT INTO `jujutsu_kaisen_cast` (`nome`, `tecnica`) VALUES 
                ('Yuji Itadori', 'Pugno Divergente / Lampo Nero'),
                ('Satoru Gojo', 'Infinito / Sei Occhi'),
                ('Megumi Fushiguro', 'Tecnica delle Dieci Ombre'),
                ('Ryomen Sukuna', 'Reliquiario Demoniaco (Taglio e Squarcio)');";
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function createDummyTableTwo(): bool
    {
        $query = \sprintf("CREATE TABLE `jojo_cast` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nome` VARCHAR(100) NOT NULL COMMENT 'Nome del personaggio (es. Jotaro Kujo)',
                `stand` VARCHAR(100) DEFAULT NULL COMMENT 'Nome dello Stand (es. Star Platinum)',
                PRIMARY KEY (`id`),
                INDEX `idx_stand` (`stand`) 
                ) ENGINE=InnoDB DEFAULT CHARSET=%s COLLATE=%s 
                COMMENT='Tabella personaggi e stand JoJo';", CharsetEnum::UTF8MB4->value,
            CollationEnum::UTF8MB4_0900_AI_CI->value);
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function populateDummiTableTwo(): bool
    {
        $query = "INSERT INTO `jojo_cast` (`nome`, `stand`) VALUES 
                ('Jotaro Kujo', 'Star Platinum'),
                ('Dio Brando', 'The World'),
                ('Josuke Higashikata', 'Crazy Diamond'),
                ('Giorno Giovanna', 'Gold Experience'),
                ('Jolyne Cujoh', 'Stone Free'),
                ('Bruno Bucciarati', 'Sticky Fingers');";
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }
}
