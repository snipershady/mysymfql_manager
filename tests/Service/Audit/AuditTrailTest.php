<?php

declare(strict_types=1);

namespace App\Tests\Service\Audit;

use App\Entity\AppUser;
use App\Entity\AuditLogEntry;
use App\Enum\AuditActionEnum;
use App\Enum\AuditOutcomeEnum;
use App\Repository\AuditLogRepository;
use App\Service\Audit\AuditContext;
use App\Service\Audit\AuditContextExtractor;
use App\Service\Audit\AuditTrail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(AuditTrail::class)]
#[UsesClass(AuditLogEntry::class)]
#[UsesClass(AuditContext::class)]
#[UsesClass(AuditContextExtractor::class)]
#[UsesClass(AuditOutcomeEnum::class)]
final class AuditTrailTest extends TestCase
{
    /** @var list<array{string, string}> */
    private array $logs = [];

    public function testRecordsWhoWhenWhatAndOutcome(): void
    {
        $recorded = [];
        $repository = $this->createMock(AuditLogRepository::class);
        $repository->expects($this->once())->method('record')->willReturnCallback(static function (AuditLogEntry $entry) use (&$recorded): void {
            $recorded[] = $entry;
        });

        $request = Request::create('/schema/table-drop', 'POST', server: ['REMOTE_ADDR' => '10.0.0.7']);
        $this->trail($repository, $this->user('mario'))->record(
            AuditActionEnum::TABLE_DROP,
            new AuditContext('srv', 'shop', 'orders'),
            $request,
            new JsonResponse(['is_valid' => false, 'message' => 'ERROR 1051 (42S02): Unknown table']),
        );

        $this->assertCount(1, $recorded);
        $entry = $recorded[0];
        $this->assertSame('2026-11-28 10:00:00', $entry->getOccurredAt()->format('Y-m-d H:i:s'));
        $this->assertSame('mario', $entry->getActorUsername());
        $this->assertSame('10.0.0.7', $entry->getIp());
        $this->assertSame(AuditActionEnum::TABLE_DROP, $entry->getAction());
        $this->assertSame(AuditOutcomeEnum::FAILURE, $entry->getOutcome());
        $this->assertSame(['srv', 'shop', 'orders'], [$entry->getServerName(), $entry->getDbName(), $entry->getTarget()]);
        $this->assertSame('ERROR 1051 (42S02): Unknown table', $entry->getMessage());
    }

    public function testSuccessKeepsNoMessageAndExceptionIsTheFallback(): void
    {
        $recorded = [];
        $repository = $this->createStub(AuditLogRepository::class);
        $repository->method('record')->willReturnCallback(static function (AuditLogEntry $entry) use (&$recorded): void {
            $recorded[] = $entry;
        });
        $trail = $this->trail($repository, user: null);

        $trail->record(AuditActionEnum::TABLE_EMPTY, new AuditContext(), Request::create('/'), new JsonResponse(['is_valid' => true, 'message' => 'Tabella svuotata.']));
        $trail->record(AuditActionEnum::TABLE_EMPTY, new AuditContext(), Request::create('/'), new Response('', Response::HTTP_INTERNAL_SERVER_ERROR), 'Boom');

        $this->assertNull($recorded[0]->getMessage());
        $this->assertSame('(anonimo)', $recorded[0]->getActorUsername());
        $this->assertNull($recorded[0]->getActorId());
        $this->assertSame('Boom', $recorded[1]->getMessage());
    }

    public function testAFailingWriteIsLoggedAndNeverThrown(): void
    {
        $repository = $this->createStub(AuditLogRepository::class);
        $repository->method('record')->willThrowException(new \RuntimeException('Table audit_log does not exist'));

        $this->trail($repository, $this->user('mario'))->record(AuditActionEnum::DATABASE_DROP, new AuditContext('srv', 'shop'), Request::create('/'), new JsonResponse(['is_valid' => true]));

        $this->assertCount(1, $this->logs);
        $this->assertSame('critical', $this->logs[0][0]);
        $this->assertStringContainsString('Table audit_log does not exist', $this->logs[0][1]);
    }

    private function trail(AuditLogRepository $repository, ?AppUser $user): AuditTrail
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $logs = &$this->logs;
        $logger = new class($logs) extends AbstractLogger {
            /** @param list<array{string, string}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $replace = [];
                foreach ($context as $key => $value) {
                    $replace['{' . $key . '}'] = \is_scalar($value) ? (string) $value : '';
                }

                $this->logs[] = [\is_string($level) ? $level : '', strtr((string) $message, $replace)];
            }
        };

        return new AuditTrail($repository, $security, new MockClock('2026-11-28 10:00:00'), $logger, new AuditContextExtractor());
    }

    private function user(string $username): AppUser
    {
        return new AppUser()->setUsername($username);
    }
}
