<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\AuditPruneCommand;
use App\Entity\AuditLogEntry;
use App\Enum\AuditActionEnum;
use App\Enum\AuditOutcomeEnum;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:audit-prune on the real test catalog (rolled back after each test): at most 90 days of history.
 */
#[CoversClass(AuditPruneCommand::class)]
#[CoversClass(AuditLogRepository::class)]
final class AuditPruneCommandTest extends KernelTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        $repository = self::getContainer()->get(AuditLogRepository::class);
        $this->assertInstanceOf(AuditLogRepository::class, $repository);

        foreach (['-120 days', '-91 days', '-40 days', '-1 hour'] as $age) {
            $repository->record(new AuditLogEntry(new \DateTimeImmutable($age), 'test_admin', AuditActionEnum::QUERY_EXECUTE, AuditOutcomeEnum::SUCCESS));
        }
    }

    public function testWithoutForceNothingIsDeleted(): void
    {
        $tester = $this->prune([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('2 entries before', $tester->getDisplay());
        $this->assertSame(4, $this->entryCount());
    }

    public function testDefaultRetentionIsNinetyDays(): void
    {
        $tester = $this->prune(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('2 audit log entries deleted', $tester->getDisplay());
        $this->assertSame(2, $this->entryCount());
    }

    public function testShorterRetention(): void
    {
        $this->prune(['--days' => '30', '--force' => true]);

        $this->assertSame(1, $this->entryCount());
        $this->assertStringContainsString('No audit log entries older than 30 day(s)', $this->prune(['--days' => '30'])->getDisplay());
    }

    public function testRetentionCannotExceedNinetyDays(): void
    {
        foreach (['91', '0', 'abc'] as $days) {
            $this->assertSame(Command::INVALID, $this->prune(['--days' => $days, '--force' => true])->getStatusCode());
        }

        $this->assertSame(4, $this->entryCount());
    }

    /**
     * @param array<string, bool|string> $options
     */
    private function prune(array $options): CommandTester
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('app:audit-prune'));
        $tester->execute($options);

        return $tester;
    }

    private function entryCount(): int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getRepository(AuditLogEntry::class)->count();
    }
}
