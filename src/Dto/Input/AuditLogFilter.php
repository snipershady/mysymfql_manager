<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Enum\AuditActionEnum;
use App\Enum\AuditOutcomeEnum;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Filters of the audit log page (GET form: every field may be absent or empty = no filter).
 */
final readonly class AuditLogFilter
{
    #[Assert\Length(max: 180)]
    public ?string $actor;

    #[Assert\Length(max: 31)]
    public ?string $server;

    #[Assert\Choice(callback: [self::class, 'actionValues'], message: 'Azione non valida.')]
    public ?string $action;

    #[Assert\Choice(callback: [self::class, 'outcomeValues'], message: 'Esito non valido.')]
    public ?string $outcome;

    #[Assert\Date(message: 'Data non valida.')]
    public ?string $from;

    #[Assert\Date(message: 'Data non valida.')]
    public ?string $to;

    public function __construct(
        ?string $actor = null,
        ?string $server = null,
        ?string $action = null,
        ?string $outcome = null,
        ?string $from = null,
        ?string $to = null,
        #[Assert\Positive]
        public int $page = 1,
    ) {
        $this->actor = $this->nullIfEmpty($actor);
        $this->server = $this->nullIfEmpty($server);
        $this->action = $this->nullIfEmpty($action);
        $this->outcome = $this->nullIfEmpty($outcome);
        $this->from = $this->nullIfEmpty($from);
        $this->to = $this->nullIfEmpty($to);
    }

    public function actionEnum(): ?AuditActionEnum
    {
        return null === $this->action ? null : AuditActionEnum::tryFrom($this->action);
    }

    public function outcomeEnum(): ?AuditOutcomeEnum
    {
        return null === $this->outcome ? null : AuditOutcomeEnum::tryFrom($this->outcome);
    }

    /** Start of the "from" day (valid once the DTO passed validation). */
    public function fromDate(): ?\DateTimeImmutable
    {
        return null === $this->from ? null : new \DateTimeImmutable($this->from . ' 00:00:00');
    }

    /** Start of the day after "to": the filter includes the whole "to" day. */
    public function toDateExclusive(): ?\DateTimeImmutable
    {
        return null === $this->to ? null : new \DateTimeImmutable($this->to . ' 00:00:00')->modify('+1 day');
    }

    /**
     * Query string of the current filters, for the pagination links.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter([
            'actor' => $this->actor,
            'server' => $this->server,
            'action' => $this->action,
            'outcome' => $this->outcome,
            'from' => $this->from,
            'to' => $this->to,
        ], static fn (?string $value): bool => null !== $value);
    }

    /**
     * @return list<string>
     */
    public static function actionValues(): array
    {
        return array_map(static fn (AuditActionEnum $action): string => $action->value, AuditActionEnum::cases());
    }

    /**
     * @return list<string>
     */
    public static function outcomeValues(): array
    {
        return array_map(static fn (AuditOutcomeEnum $outcome): string => $outcome->value, AuditOutcomeEnum::cases());
    }

    private function nullIfEmpty(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
