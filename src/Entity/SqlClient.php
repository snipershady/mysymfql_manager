<?php

namespace App\Entity;

use App\Repository\SqlClientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SqlClientRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_sql_client_name', columns: ['name'])]
#[ORM\Index(name: 'idx_sql_client_host', columns: ['host'])]
class SqlClient implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 31, unique: true)]
    private ?string $name = null;

    #[ORM\Column(length: 31)]
    private ?string $host = null;

    #[ORM\Column(length: 31)]
    private ?string $username = null;

    #[ORM\Column(length: 255)]
    private ?string $password = null;

    #[ORM\Column]
    private int $port = 3306;

    /**
     * @var Collection<int, AppUser>
     */
    #[ORM\ManyToMany(targetEntity: AppUser::class)]
    private Collection $owner;

    public function __construct()
    {
        $this->owner = new ArrayCollection();
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->name.'@'.$this->host;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function setHost(string $host): static
    {
        $this->host = $host;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(?int $port): static
    {
        $this->port = $port ?? 3306;

        return $this;
    }

    /**
     * @return Collection<int, AppUser>
     */
    public function getOwner(): Collection
    {
        return $this->owner;
    }

    public function addOwner(AppUser $owner): static
    {
        if (!$this->owner->contains($owner)) {
            $this->owner->add($owner);
        }

        return $this;
    }

    public function removeOwner(AppUser $owner): static
    {
        $this->owner->removeElement($owner);

        return $this;
    }
}
