<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\RepositoryPDO\MysqlAccountRepository;
use App\Service\MysqlAccountManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MysqlAccountManager::class)]
final class MysqlAccountManagerTest extends TestCase
{
    /** @var list<string> statements sent to the server, in order */
    private array $calls = [];

    public function testDropFlushesThePrivileges(): void
    {
        $this->assertTrue(new MysqlAccountManager()->drop($this->accounts(), 'app_rw', '%'));
        $this->assertSame(['drop app_rw@%', 'flush'], $this->calls);
    }

    public function testFailedDropDoesNotFlush(): void
    {
        $this->assertFalse(new MysqlAccountManager()->drop($this->accounts(dropSucceeds: false), 'app_rw', '%'));
        $this->assertSame(['drop app_rw@%'], $this->calls);
    }

    public function testGrantsAreReplacedNotAddedTo(): void
    {
        new MysqlAccountManager()->replaceGrants(
            $this->accounts(granted: ['shop', 'old']),
            'app_rw', '%',
            grants: [['shop', 'SELECT'], ['2024', 'SELECT, INSERT']],
            revokedDbs: ['old'],
        );

        $this->assertSame([
            'revoke old',                 // database removed from the account
            'revoke shop',                // existing grants replaced...
            'grant shop SELECT',
            'grant 2024 SELECT, INSERT',  // ...new database granted without a REVOKE (it would fail); an all-digit name stays a string
            'flush',
        ], $this->calls);
    }

    /**
     * @param list<string> $granted databases where the account already has grants
     */
    private function accounts(bool $dropSucceeds = true, array $granted = []): MysqlAccountRepository
    {
        $accounts = $this->createStub(MysqlAccountRepository::class);
        $accounts->method('dropUser')->willReturnCallback(function (string $user, string $host) use ($dropSucceeds): bool {
            $this->calls[] = \sprintf('drop %s@%s', $user, $host);

            return $dropSucceeds;
        });
        $accounts->method('flushPrivileges')->willReturnCallback(function (): bool {
            $this->calls[] = 'flush';

            return true;
        });
        $accounts->method('revokeAllPrivilegesOnDb')->willReturnCallback(function (string $db): bool {
            $this->calls[] = 'revoke ' . $db;

            return true;
        });
        $accounts->method('grantPrivileges')->willReturnCallback(function (string $dbName, string $username, string $privileges): bool {
            $this->calls[] = \sprintf('grant %s %s', $dbName, $privileges);

            return true;
        });
        // After the revocations of $revokedDbs the account no longer has grants on them.
        $accounts->method('getUserGrantsByDb')->willReturnCallback(fn (): array => array_values(array_map(
            static fn (string $db): array => ['db' => $db, 'privileges' => ['SELECT'], 'all_privileges' => false],
            array_filter($granted, fn (string $db): bool => !\in_array('revoke ' . $db, $this->calls, strict: true)),
        )));

        return $accounts;
    }
}
