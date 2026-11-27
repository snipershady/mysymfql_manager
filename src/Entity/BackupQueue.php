<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BackupQueueStatusEnum;
use App\Repository\BackupQueueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A backup requested from the panel and run by app:process-backup-queue (cron, FIFO).
 *
 * @see BackupQueueStatusEnum for the lifecycle
 * @see \App\Tests\Entity\BackupQueueTest
 */
#[ORM\Entity(repositoryClass: BackupQueueRepository::class)]
class BackupQueue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?SqlClient $sqlClient = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?AppUser $owner = null;

    #[ORM\Column(length: 63)]
    private ?string $dbName = null;

    #[ORM\Column(name: 'table_name', length: 63, nullable: true)]
    private ?string $tableName = null;

    #[ORM\Column(length: 15, enumType: BackupQueueStatusEnum::class, options: ['default' => 'pending'])]
    private BackupQueueStatusEnum $status = BackupQueueStatusEnum::PENDING;

    /** Number of times a worker has taken the entry (a retry from the dashboard runs it again). */
    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $startedDate = null;

    /** Native error output of the last failed run (mysqldump stderr or exception message). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private ?\DateTime $requestDate;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $completedDate = null;

    public function __construct()
    {
        $this->requestDate = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSqlClient(): ?SqlClient
    {
        return $this->sqlClient;
    }

    public function setSqlClient(?SqlClient $sqlClient): static
    {
        $this->sqlClient = $sqlClient;

        return $this;
    }

    public function getOwner(): ?AppUser
    {
        return $this->owner;
    }

    public function setOwner(?AppUser $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getDbName(): ?string
    {
        return $this->dbName;
    }

    public function setDbName(string $dbName): static
    {
        $this->dbName = $dbName;

        return $this;
    }

    public function getStatus(): BackupQueueStatusEnum
    {
        return $this->status;
    }

    public function setStatus(BackupQueueStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): static
    {
        $this->attempts = $attempts;

        return $this;
    }

    public function getStartedDate(): ?\DateTime
    {
        return $this->startedDate;
    }

    public function setStartedDate(?\DateTime $startedDate): static
    {
        $this->startedDate = $startedDate;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;

        return $this;
    }

    /**
     * The worker finished the dump.
     */
    public function markDone(\DateTime $completedDate): static
    {
        $this->status = BackupQueueStatusEnum::DONE;
        $this->completedDate = $completedDate;
        $this->error = null;

        return $this;
    }

    /**
     * The worker could not complete the dump: $error is shown in the dashboard.
     */
    public function markFailed(string $error): static
    {
        $this->status = BackupQueueStatusEnum::FAILED;
        $this->error = '' === trim($error) ? 'Errore sconosciuto.' : trim($error);

        return $this;
    }

    public function getRequestDate(): ?\DateTime
    {
        return $this->requestDate;
    }

    public function setRequestDate(\DateTime $requestDate): static
    {
        $this->requestDate = $requestDate;

        return $this;
    }

    public function getCompletedDate(): ?\DateTime
    {
        return $this->completedDate;
    }

    public function setCompletedDate(?\DateTime $completedDate): static
    {
        $this->completedDate = $completedDate;

        return $this;
    }

    public function getTable(): ?string
    {
        return $this->tableName;
    }

    public function setTable(?string $tableName): static
    {
        $this->tableName = $tableName;

        return $this;
    }
}
