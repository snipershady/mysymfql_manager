<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Enum\CharsetEnum;
use App\Enum\CollationEnum;
use App\Exception\ProvisioningConflictException;
use App\Exception\ProvisioningException;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Service\DatabaseProvisioner;
use App\Tests\MyKernelTestCase;

/**
 * Compensation against a real MySQL server (TEST_SERVER_NAME). The failure is triggered before the
 * catalog step, so nothing is written to the (shared) application database.
 */
final class DatabaseProvisionerIntegrationTest extends MyKernelTestCase
{
    private DatabaseSchemaRepository $repo;

    private string $dbName;

    private string $username;

    protected function setUp(): void
    {
        parent::setUp();
        $sqlClient = $this->entityManager->getRepository(SqlClient::class)->findOneByName($this->serverName);
        $this->assertInstanceOf(SqlClient::class, $sqlClient);
        $this->repo = new DatabaseSchemaRepository($sqlClient);
        $suffix = bin2hex(random_bytes(4));
        $this->dbName = 'unit_testing_prov_' . $suffix;
        $this->username = 'ut_prov_' . $suffix;
    }

    protected function tearDown(): void
    {
        $this->repo->dropUser($this->username);
        $this->repo->dropDatabase($this->dbName);
        parent::tearDown();
    }

    public function testFailedGrantLeavesNeitherDatabaseNorUserOnTheServer(): void
    {
        $sqlClient = $this->entityManager->getRepository(SqlClient::class)->findOneByName($this->serverName);
        $this->assertInstanceOf(SqlClient::class, $sqlClient);

        try {
            // A privilege outside the whitelist makes grantPrivileges() throw after the database and the user exist.
            $this->provisioner()->createDatabaseWithOwner(
                $this->repo, $sqlClient, new AppUser(), $this->dbName,
                CharsetEnum::UTF8MB4, CollationEnum::UTF8MB4_0900_AI_CI,
                $this->username, 'Str0ng-' . bin2hex(random_bytes(6)), '%', 'NOT_A_PRIVILEGE',
            );
            $this->fail('A ProvisioningException was expected.');
        } catch (ProvisioningException $provisioningException) {
            $this->assertSame([], $provisioningException->compensationFailures);
        }

        $this->assertNotContains($this->dbName, $this->repo->showDatabases());
        $this->assertSame([], $this->repo->getHostByUser($this->username));
    }

    public function testExistingDatabaseIsNeverDroppedByACompensation(): void
    {
        $this->repo->createDatabase($this->dbName);
        $sqlClient = $this->entityManager->getRepository(SqlClient::class)->findOneByName($this->serverName);
        $this->assertInstanceOf(SqlClient::class, $sqlClient);

        try {
            $this->provisioner()->createDatabaseWithOwner(
                $this->repo, $sqlClient, new AppUser(), $this->dbName,
                CharsetEnum::UTF8MB4, CollationEnum::UTF8MB4_0900_AI_CI,
                $this->username, 'Str0ng-' . bin2hex(random_bytes(6)), '%', 'SELECT',
            );
            $this->fail('A ProvisioningConflictException was expected.');
        } catch (ProvisioningConflictException) {
        }

        $this->assertContains($this->dbName, $this->repo->showDatabases());
        $this->assertSame([], $this->repo->getHostByUser($this->username));
    }

    private function provisioner(): DatabaseProvisioner
    {
        $provisioner = self::getContainer()->get(DatabaseProvisioner::class);
        $this->assertInstanceOf(DatabaseProvisioner::class, $provisioner);

        return $provisioner;
    }
}
