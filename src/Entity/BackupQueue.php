<?php

namespace App\Entity;

use App\Repository\BackupQueueRepository;
use Doctrine\ORM\Mapping as ORM;

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

    #[ORM\Column]
    private bool $isDequeued = false;

    #[ORM\Column]
    private ?\DateTime $requestDate = null;

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

    public function isDequeued(): ?bool
    {
        return $this->isDequeued;
    }

    public function setIsDequeued(bool $isDequeued): static
    {
        $this->isDequeued = $isDequeued;

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
