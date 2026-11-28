<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\AppUser;
use App\Entity\AuditLogEntry;
use App\Enum\AuditActionEnum;
use App\Enum\AuditOutcomeEnum;
use App\Repository\AuditLogRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes the audit log entry of a request: who (current panel user, client IP), when (clock), what (action and
 * context) and the outcome read from the response. The message is kept only when the action did not succeed:
 * MySQL's native error shown to the user, or the exception of an unexpected failure.
 *
 * Recording never breaks the request: if the entry cannot be written the failure is logged as critical and the
 * response is sent unchanged.
 *
 * @see \App\Tests\Service\Audit\AuditTrailTest
 */
final readonly class AuditTrail
{
    public function __construct(
        private AuditLogRepository $repository,
        private Security $security,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private AuditContextExtractor $extractor,
    ) {
    }

    public function record(AuditActionEnum $action, AuditContext $context, Request $request, Response $response, ?string $exceptionMessage = null): void
    {
        $outcome = AuditOutcomeEnum::fromResponse($response);
        $message = AuditOutcomeEnum::SUCCESS === $outcome ? null : (AuditOutcomeEnum::messageOf($response) ?? $exceptionMessage);

        $user = $this->security->getUser();

        $entry = new AuditLogEntry(
            occurredAt: $this->clock->now(),
            actorUsername: mb_substr($user?->getUserIdentifier() ?? '(anonimo)', 0, 180),
            action: $action,
            outcome: $outcome,
            actorId: $user instanceof AppUser ? $user->getId() : null,
            ip: $request->getClientIp(),
            serverName: $context->serverName,
            dbName: $context->dbName,
            target: $context->target,
            details: $context->details,
            message: null === $message ? null : $this->extractor->truncate($message),
        );

        try {
            $this->repository->record($entry);
        } catch (\Throwable $throwable) {
            $this->logger->critical('Audit log: cannot record {action} by {actor}: {error}', [
                'action' => $action->value,
                'actor' => $entry->getActorUsername(),
                'outcome' => $outcome->value,
                'server' => $context->serverName,
                'db' => $context->dbName,
                'target' => $context->target,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
