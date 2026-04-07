<?php

namespace App\Entity;

use App\Repository\DatabaseOwnerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DatabaseOwnerRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_db_owner', columns: ['db_name', 'owner_id', 'sql_client_id'])]
class DatabaseOwner
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 63)]
    private ?string $dbName = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?AppUser $owner = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?SqlClient $sqlClient = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getOwner(): ?AppUser
    {
        return $this->owner;
    }

    public function setOwner(?AppUser $owner): static
    {
        $this->owner = $owner;

        return $this;
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
}
