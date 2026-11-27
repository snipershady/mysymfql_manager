<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use App\Enum\CharsetEnum;
use App\Enum\CollationEnum;
use App\Exception\ProvisioningConflictException;
use App\Exception\ProvisioningException;
use App\Exception\RepositoryException;
use App\Repository\DatabaseOwnerRepository;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\RepositoryPDO\MysqlAccountRepository;
use App\Service\DatabaseProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DatabaseProvisioner::class)]
#[UsesClass(ProvisioningException::class)]
#[UsesClass(ProvisioningConflictException::class)]
#[UsesClass(RepositoryException::class)]
final class DatabaseProvisionerTest extends TestCase
{
    /** @var list<string> remote and catalog calls, in order */
    private array $calls = [];

    /** @var array<string, \Throwable|false> step name => failure to simulate */
    private array $failures = [];

    /** @var list<string> */
    private array $existingDatabases = [];

    /** @var list<string> */
    private array $existingHosts = [];

    public function testCreateDatabaseRunsEveryStepInOrder(): void
    {
        $this->provisioner()->createDatabaseWithOwner(...$this->createArgs());

        $this->assertSame(['createDatabase:strict', 'createUser', 'grantPrivileges', 'flushPrivileges', 'persist', 'flush'], $this->calls);
    }

    public function testExistingDatabaseIsAConflictAndNothingIsTouched(): void
    {
        $this->existingDatabases = ['shop'];

        $this->expectException(ProvisioningConflictException::class);
        try {
            $this->provisioner()->createDatabaseWithOwner(...$this->createArgs());
        } finally {
            $this->assertSame([], $this->calls);
        }
    }

    public function testExistingAccountIsAConflictAndNothingIsTouched(): void
    {
        $this->existingHosts = ['%'];

        $this->expectException(ProvisioningConflictException::class);
        try {
            $this->provisioner()->createDatabaseWithOwner(...$this->createArgs());
        } finally {
            $this->assertSame([], $this->calls);
        }
    }

    /**
     * @return iterable<string, array{string, \Throwable|false, list<string>}>
     */
    public static function createFailures(): iterable
    {
        yield 'create database throws' => ['createDatabase', new \RuntimeException('boom'), ['createDatabase:strict']];
        yield 'create user throws' => ['createUser', new \RuntimeException('boom'), ['createDatabase:strict', 'createUser', 'dropDatabase']];
        yield 'create user returns false' => ['createUser', false, ['createDatabase:strict', 'createUser', 'dropDatabase']];
        yield 'grant throws' => ['grantPrivileges', new \InvalidArgumentException('boom'), ['createDatabase:strict', 'createUser', 'grantPrivileges', 'dropUser', 'dropDatabase']];
        yield 'catalog flush throws' => ['flush', new \RuntimeException('boom'), ['createDatabase:strict', 'createUser', 'grantPrivileges', 'flushPrivileges', 'persist', 'flush', 'dropUser', 'dropDatabase']];
    }

    /**
     * @param list<string> $expectedCalls
     */
    #[DataProvider('createFailures')]
    public function testFailedCreationIsCompensatedInReverseOrder(string $failingStep, \Throwable|false $failure, array $expectedCalls): void
    {
        $this->failures[$failingStep] = $failure;

        try {
            $this->provisioner()->createDatabaseWithOwner(...$this->createArgs());
            $this->fail('A ProvisioningException was expected.');
        } catch (ProvisioningException $provisioningException) {
            $this->assertNotInstanceOf(ProvisioningConflictException::class, $provisioningException);
            $this->assertSame([], $provisioningException->compensationFailures);
            $this->assertStringContainsString('Tutte le modifiche parziali sono state annullate', $provisioningException->getMessage());
            $this->assertInstanceOf(\Throwable::class, $provisioningException->getPrevious());
        }

        $this->assertSame($expectedCalls, $this->calls);
    }

    public function testFailedCompensationIsReported(): void
    {
        $this->failures['grantPrivileges'] = new \RuntimeException('boom');
        $this->failures['dropUser'] = new \RuntimeException('cannot drop');

        try {
            $this->provisioner()->createDatabaseWithOwner(...$this->createArgs());
            $this->fail('A ProvisioningException was expected.');
        } catch (ProvisioningException $provisioningException) {
            $this->assertSame(['drop user app_rw@%'], $provisioningException->compensationFailures);
            $this->assertStringContainsString('Pulizia incompleta', $provisioningException->getMessage());
        }

        // The remaining undo step still runs after a failed one.
        $this->assertSame(['createDatabase:strict', 'createUser', 'grantPrivileges', 'dropUser', 'dropDatabase'], $this->calls);
    }

    public function testTheNativeMysqlErrorIsPartOfTheMessage(): void
    {
        $pdoException = new \PDOException('SQLSTATE[42000]: Syntax error or access violation: 1044 Access denied');
        $pdoException->errorInfo = ['42000', 1044, "Access denied for user 'panel'@'%' to database 'shop'"];
        $this->failures['createDatabase'] = new RepositoryException('createDatabase', 0, $pdoException);

        try {
            $this->provisioner()->createDatabaseWithOwner(...$this->createArgs());
            $this->fail('A ProvisioningException was expected.');
        } catch (ProvisioningException $provisioningException) {
            $this->assertSame(
                "Creazione del database non riuscita. ERROR 1044 (42000): Access denied for user 'panel'@'%' to database 'shop'. Tutte le modifiche parziali sono state annullate.",
                $provisioningException->getMessage(),
            );
        }
    }

    public function testFailedDropReportsTheNativeMysqlError(): void
    {
        $pdoException = new \PDOException('SQLSTATE[HY000]: General error: 1010');
        $pdoException->errorInfo = ['HY000', 1010, "Error dropping database (can't rmdir './shop')"];
        $this->failures['dropDatabase'] = new RepositoryException('dropDatabase', 0, $pdoException);

        $this->expectExceptionMessageIsOrContains("Eliminazione del database non riuscita: il catalogo non è stato modificato. ERROR 1010 (HY000): Error dropping database (can't rmdir './shop').");
        $this->provisioner()->dropDatabaseAndOwnerships($this->schema(), new SqlClient(), 'shop');
    }

    public function testCreateUserWithGrantDropsTheUserWhenTheGrantFails(): void
    {
        $this->failures['grantPrivileges'] = new \RuntimeException('boom');

        $this->expectException(ProvisioningException::class);
        try {
            $this->provisioner()->createUserWithGrant($this->accounts(), 'shop', 'app_rw', 'secret', '%', 'SELECT');
        } finally {
            $this->assertSame(['createUser', 'grantPrivileges', 'dropUser'], $this->calls);
        }
    }

    public function testCreateUserWithGrantRefusesAnExistingAccount(): void
    {
        $this->existingHosts = ['%'];

        $this->expectException(ProvisioningConflictException::class);
        $this->provisioner()->createUserWithGrant($this->accounts(), 'shop', 'app_rw', 'secret', '%', 'SELECT');
    }

    public function testCreateUserWithGrantHappyPath(): void
    {
        $this->provisioner()->createUserWithGrant($this->accounts(), 'shop', 'app_rw', 'secret', '%', 'SELECT');

        $this->assertSame(['createUser', 'grantPrivileges', 'flushPrivileges'], $this->calls);
    }

    public function testDropRemovesOwnershipsThenDropsInsideOneTransaction(): void
    {
        $this->provisioner()->dropDatabaseAndOwnerships($this->schema(), new SqlClient(), 'shop');

        $this->assertSame(['begin', 'removeAllFor', 'dropDatabase', 'commit'], $this->calls);
    }

    public function testFailedDropRollsBackTheCatalog(): void
    {
        $this->failures['dropDatabase'] = new \RuntimeException('boom');

        $this->expectException(ProvisioningException::class);
        try {
            $this->provisioner()->dropDatabaseAndOwnerships($this->schema(), new SqlClient(), 'shop');
        } finally {
            $this->assertSame(['begin', 'removeAllFor', 'dropDatabase', 'rollback'], $this->calls);
        }
    }

    /**
     * @return array{DatabaseSchemaRepository, MysqlAccountRepository, SqlClient, AppUser, string, CharsetEnum, CollationEnum, string, string, string, string}
     */
    private function createArgs(): array
    {
        return [$this->schema(), $this->accounts(), new SqlClient(), new AppUser(), 'shop', CharsetEnum::UTF8MB4, CollationEnum::UTF8MB4_0900_AI_CI, 'app_rw', 'secret', '%', 'SELECT'];
    }

    private function provisioner(): DatabaseProvisioner
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->assertInstanceOf(DatabaseOwner::class, $entity);
            $this->record('persist');
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->record('flush');
        });
        $entityManager->method('wrapInTransaction')->willReturnCallback(function (callable $func): mixed {
            $this->calls[] = 'begin';
            try {
                $result = $func();
            } catch (\Throwable $throwable) {
                $this->calls[] = 'rollback';

                throw $throwable;
            }
            $this->calls[] = 'commit';

            return $result;
        });

        $owners = $this->createStub(DatabaseOwnerRepository::class);
        $owners->method('removeAllFor')->willReturnCallback(fn (): int => $this->record('removeAllFor') ? 1 : 0);

        return new DatabaseProvisioner($entityManager, $owners, new NullLogger());
    }

    private function schema(): DatabaseSchemaRepository&Stub
    {
        $schema = $this->createStub(DatabaseSchemaRepository::class);
        $schema->method('showDatabases')->willReturnCallback(fn (): array => $this->existingDatabases);
        $schema->method('createDatabase')->willReturnCallback(
            fn (string $db, CharsetEnum $c, CollationEnum $col, bool $ifNotExists = true): bool => $this->record($ifNotExists ? 'createDatabase:if-not-exists' : 'createDatabase:strict', 'createDatabase')
        );
        $schema->method('dropDatabase')->willReturnCallback(fn (): bool => $this->record('dropDatabase'));

        return $schema;
    }

    private function accounts(): MysqlAccountRepository&Stub
    {
        $accounts = $this->createStub(MysqlAccountRepository::class);
        $accounts->method('getHostByUser')->willReturnCallback(fn (): array => $this->existingHosts);
        foreach (['createUser', 'grantPrivileges', 'flushPrivileges', 'dropUser'] as $method) {
            $accounts->method($method)->willReturnCallback(fn (): bool => $this->record($method));
        }

        return $accounts;
    }

    /**
     * Records a call and applies the simulated failure configured for it, if any.
     */
    private function record(string $call, ?string $step = null): bool
    {
        $this->calls[] = $call;
        $failure = $this->failures[$step ?? $call] ?? null;
        if ($failure instanceof \Throwable) {
            throw $failure;
        }

        return false !== $failure;
    }
}
