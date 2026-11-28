<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuditActionEnum;
use App\Enum\AuditOutcomeEnum;
use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One audited operation: who (panel user, IP), when, what (action, server, database, object, parameters) and how
 * it ended. Immutable: entries are written by AuditLogRepository::record() with a plain DBAL insert (so they are
 * saved even when the action rolled back its Doctrine transaction) and only deleted by app:audit-prune.
 *
 * User and server are stored by value, not as relations: the entry must survive their deletion or renaming.
 *
 * @see \App\EventListener\AuditListener
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class, readOnly: true)]
#[ORM\Table(name: AuditLogRepository::TABLE)]
#[ORM\Index(name: 'idx_audit_log_occurred_at', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_action', columns: ['action', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_actor', columns: ['actor_username', 'occurred_at'])]
class AuditLogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<array-key, mixed>|null $details
     */
    public function __construct(
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(length: 180)]
        private string $actorUsername,
        #[ORM\Column(length: 40, enumType: AuditActionEnum::class)]
        private AuditActionEnum $action,
        #[ORM\Column(length: 15, enumType: AuditOutcomeEnum::class)]
        private AuditOutcomeEnum $outcome,
        #[ORM\Column(nullable: true)]
        private ?int $actorId = null,
        #[ORM\Column(length: 45, nullable: true)]
        private ?string $ip = null,
        #[ORM\Column(length: 31, nullable: true)]
        private ?string $serverName = null,
        #[ORM\Column(length: 63, nullable: true)]
        private ?string $dbName = null,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $target = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $details = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $message = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActorId(): ?int
    {
        return $this->actorId;
    }

    public function getActorUsername(): string
    {
        return $this->actorUsername;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getAction(): AuditActionEnum
    {
        return $this->action;
    }

    public function getOutcome(): AuditOutcomeEnum
    {
        return $this->outcome;
    }

    public function getServerName(): ?string
    {
        return $this->serverName;
    }

    public function getDbName(): ?string
    {
        return $this->dbName;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function getDetails(): ?array
    {
        return $this->details;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }
}
