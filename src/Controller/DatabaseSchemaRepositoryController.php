<?php

namespace App\Controller;

use App\Dto\MysqlUser;
use App\Entity\AppUser;
use App\Entity\BackupQueue;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use App\Enum\CharsetEnum;
use App\Enum\CollationEnum;
use App\Exception\RepositoryException;
use App\Repository\DatabaseOwnerRepository;
use App\Repository\SqlClientRepository;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Service\MysqldumpManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

#[Route(path: '/schema')]
final class DatabaseSchemaRepositoryController extends AbstractController
{
    #[Route(path: '/dashboard-stats', name: 'app_schema_dashboard_stats', methods: ['GET'])]
    public function dashboardStats(
        #[CurrentUser] AppUser $user,
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository,
        DatabaseOwnerRepository $databaseOwnerRepository,
    ): JsonResponse {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);

        $ownedClients = $sqlClientRepository->findAllOwned($user);
        $sqlClient = array_find($ownedClients, static fn ($ownedClient): bool => $ownedClient->getName() === $name);

        if (null === $sqlClient) {
            return $this->json(['error' => 'Server not found or access denied'], Response::HTTP_NOT_FOUND);
        }
        $repo = new DatabaseSchemaRepository($sqlClient);

        $databases = $this->showDatabaseWithStatsByOwner($user, $sqlClient, $databaseOwnerRepository, $name);

        return $this->json([
            'db_count' => \count($databases),
            'active_connections' => $repo->getActiveConnections(),
            'running_processes' => $repo->getRunningProcesses(),
            'blocked_processes' => $repo->getBlockedProcesses(),
            'databases' => $databases,
        ]);
    }

    #[Route(path: '/databases', name: 'app_schema_databases', methods: ['GET'])]
    public function databases(
        #[CurrentUser] AppUser $user,
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): Response
    {
        $selectedName = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);

        return $this->render('schema/databases.html.twig', [
            'sql_clients' => $sqlClientRepository->findAllOwned($user),
            'charsets' => CharsetEnum::cases(),
            'collations' => CollationEnum::cases(),
            'selected_name' => $selectedName,
        ]);
    }

    #[Route(path: '/create-database', name: 'app_schema_create_database', methods: ['POST'])]
    public function createDatabase(
        #[CurrentUser] AppUser $user,
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository,
        EntityManagerInterface $entityManagerInterface): JsonResponse
    {
        $name = $epti->getStringValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $charsetVal = $epti->getStringValueFromPost(needle: 'charset', trim: true, forceString: true, sanitizeHtml: true);
        $collateVal = $epti->getStringValueFromPost(needle: 'collation', trim: true, forceString: true, sanitizeHtml: true);
        $username = $epti->getStringValueFromPost(needle: 'username', trim: true, forceString: true, sanitizeHtml: false);
        $password = $epti->getStringValueFromPost(needle: 'password', trim: true, forceString: true, sanitizeHtml: false);
        $userHost = $epti->getStringValueFromPost(needle: 'user_host', trim: true, forceString: true, sanitizeHtml: false);
        $privileges = $epti->getStringValueFromPost(needle: 'privileges', trim: true, forceString: true, sanitizeHtml: false);

        if ('' === $username) {
            return $this->json(['is_valid' => false, 'message' => 'Parameter username cannot be empty'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === $dbName) {
            return $this->json(['is_valid' => false, 'message' => 'Parameter db_name cannot be empty'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === $name) {
            return $this->json(['is_valid' => false, 'message' => 'Parameter name cannot be empty'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === $password) {
            return $this->json(['is_valid' => false, 'message' => 'Parameter password cannot be empty'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === $userHost) {
            return $this->json(['is_valid' => false, 'message' => 'Parameter user_host cannot be empty'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === $privileges) {
            return $this->json(['is_valid' => false, 'message' => 'Parameter privileges cannot be empty'], Response::HTTP_BAD_REQUEST);
        }

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server not found.'], Response::HTTP_NOT_FOUND);
        }

        $charset = CharsetEnum::tryFrom($charsetVal) ?? CharsetEnum::UTF8MB4;
        $collation = CollationEnum::tryFrom($collateVal) ?? CollationEnum::UTF8MB4_0900_AI_CI;

        $repo = new DatabaseSchemaRepository($sqlClient);
        $repo->createDatabase($dbName, $charset, $collation);

        try {
            $repo->createUser($username, $password, $userHost);
            $repo->grantPrivileges($dbName, $username, $privileges, $userHost);
            $repo->flushPrivileges();
        } catch (\Exception $exception) {
            return $this->json(['is_valid' => false, 'message' => 'Exception: ' . $exception->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Assign the newly created database to the user as "owner"
        $dbowner = new DatabaseOwner()->setDbName($dbName)->setOwner($user)->setSqlClient($sqlClient);
        $entityManagerInterface->persist($dbowner);
        $entityManagerInterface->flush();

        return $this->json(['is_valid' => true, 'message' => 'Database created successfully.']);
    }

    #[Route(path: '/tables', name: 'app_schema_tables', methods: ['GET'])]
    public function tables(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): Response
    {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->render('schema/tables.html.twig', [
                'name' => '',
                'db_name' => '',
                'db_version' => '',
            ]);
        }

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $versionRow = $databaseRepositoryPdo->getVersion();
        $dbVersion = $versionRow['@@version'] ?? null;

        return $this->render('schema/tables.html.twig', [
            'name' => $name,
            'db_name' => $dbName,
            'db_version' => $dbVersion,
        ]);
    }

    #[Route(path: '/query', name: 'app_schema_query', methods: ['GET'])]
    public function query(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): Response
    {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            throw $this->createNotFoundException('Server not found.');
        }

        return $this->render('schema/query.html.twig', [
            'name' => $name,
            'db_name' => $dbName,
        ]);
    }

    #[Route(path: '/columns-get-data', name: 'app_schema_columns_get_data', methods: ['GET'])]
    public function columnsGetData(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);

        if ('' === $name || '' === $dbName) {
            return $this->json(['error' => 'Missing name or db_name parameter'], Response::HTTP_BAD_REQUEST);
        }

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server not found or access denied'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);

        return $this->json(['tables' => $repo->getColumnsBySchema($dbName)]);
    }

    #[Route(path: '/query-execute', name: 'app_schema_query_execute', methods: ['POST'])]
    public function queryExecute(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $sql = $epti->getStringValueFromPost(needle: 'sql', trim: true, forceString: true, sanitizeHtml: false);

        if ('' === $sql) {
            return $this->json(['is_valid' => false, 'message' => 'La query non può essere vuota.'], Response::HTTP_BAD_REQUEST);
        }

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server not found.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $repo->useDbName($dbName);

        try {
            $result = $repo->runQuery($sql);
        } catch (RepositoryException $repositoryException) {
            return $this->json(['is_valid' => false, 'message' => $repositoryException->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'is_valid' => true,
            'columns' => $result['columns'],
            'rows' => $result['rows'],
            'affected_rows' => $result['affected_rows'],
            'is_select' => $result['is_select'],
        ]);
    }

    #[Route(path: '/show-databases-get-data', name: 'app_show_databases_get_data', methods: ['GET'])]
    public function showDatabasesGetData(
        #[CurrentUser] AppUser $user,
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository,
        DatabaseOwnerRepository $databaseOwnerRepository,
    ): JsonResponse {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server not found or access denied'], Response::HTTP_NOT_FOUND);
        }

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $versionRow = $databaseRepositoryPdo->getVersion();

        return $this->json([
            'data' => $this->showDatabaseWithStatsByOwner($user, $sqlClient, $databaseOwnerRepository, $name),
            'version' => $versionRow['@@version'] ?? null,
        ]);
    }

    #[Route(path: '/show-tables-get-data', name: 'app_show_tables_get_data', methods: ['GET'])]
    public function showTablesGetData(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server not found or access denied'], Response::HTTP_NOT_FOUND);
        }

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $databaseRepositoryPdo->useDbName($dbName);

        return $this->json(['data' => $databaseRepositoryPdo->showTablesWithStats()]);
    }

    #[Route(path: '/show-engine-status-get-data', name: 'app_show_engine_status_get_data', methods: ['GET'])]
    public function showEngineInnodbStatusGetData(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server not found or access denied'], Response::HTTP_NOT_FOUND);
        }

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);

        return $this->json(['data' => $databaseRepositoryPdo->showEngineInnodbStatus()]);
    }

    #[Route(path: '/show-processlist-get-data', name: 'app_show_processlist_get_data', methods: ['GET'])]
    public function showProcessListGetData(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server not found or access denied'], Response::HTTP_NOT_FOUND);
        }

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);

        return $this->json(['data' => $databaseRepositoryPdo->showProcessList()]);
    }

    #[Route(path: '/engine-status', name: 'app_schema_engine_status', methods: ['GET'])]
    public function engineStatus(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): Response
    {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            throw $this->createNotFoundException('Server not found.');
        }
        $repo = new DatabaseSchemaRepository($sqlClient);

        return $this->render('schema/engine_status.html.twig', [
            'name' => $name,
            'status' => $repo->showEngineInnodbStatus(),
        ]);
    }

    #[Route(path: '/process-list', name: 'app_schema_process_list', methods: ['GET'])]
    public function processListPage(EffectivePrimitiveTypeIdentifierService $epti): Response
    {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);

        return $this->render('schema/process_list.html.twig', [
            'name' => $name,
        ]);
    }

    #[Route(path: '/kill-process', name: 'app_schema_kill_process', methods: ['POST'])]
    public function killProcess(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $pid = $epti->getIntValueFromPost(needle: 'pid', trim: true);
        $sqlClient = $sqlClientRepository->findOneByName($name);

        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server not found.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $ok = $repo->killProcessById($pid);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? \sprintf('Process %d terminated.', $pid) : \sprintf('Unable to terminate process %d.', $pid),
        ]);
    }

    #[Route(path: '/db-users', name: 'app_schema_db_users', methods: ['GET'])]
    public function dbUsers(EffectivePrimitiveTypeIdentifierService $epti): Response
    {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);

        return $this->render('schema/db_users.html.twig', [
            'name' => $name,
            'db_name' => $dbName,
        ]);
    }

    #[Route(path: '/db-users-get-data', name: 'app_schema_db_users_get_data', methods: ['GET'])]
    public function dbUsersGetData(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['data' => []]);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $users = $repo->listUsers($dbName);

        $data = array_map(static fn (MysqlUser $u): array => [
            'user' => $u->user,
            'host' => $u->host,
            'account_locked' => $u->accountLocked,
            'has_db_grant' => $u->hasDbGrant,
        ], $users);

        return $this->json(['data' => $data]);
    }

    #[Route(path: '/db-user-drop', name: 'app_schema_db_user_drop', methods: ['POST'])]
    public function dbUserDrop(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $username = $epti->getStringValueFromPost(needle: 'username', trim: true, forceString: true, sanitizeHtml: false);
        $userHost = $epti->getStringValueFromPost(needle: 'user_host', trim: true, forceString: true, sanitizeHtml: false);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server not found.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $ok = $repo->dropUser($username, $userHost);
        if ($ok) {
            $repo->flushPrivileges();
        }

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'User deleted successfully.' : 'Error during deletion.',
        ]);
    }

    #[Route(path: '/db-user-change-password', name: 'app_schema_db_user_change_password', methods: ['POST'])]
    public function dbUserChangePassword(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $username = $epti->getStringValueFromPost(needle: 'username', trim: true, forceString: true, sanitizeHtml: false);
        $userHost = $epti->getStringValueFromPost(needle: 'user_host', trim: true, forceString: true, sanitizeHtml: false);
        $newPassword = $epti->getStringValueFromPost(needle: 'password', trim: true, forceString: true, sanitizeHtml: false);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server not found.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $ok = $repo->changeUserPassword($username, $newPassword, $userHost);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Password updated successfully.' : 'Error during password update.',
        ]);
    }

    #[Route(path: '/db-user-create', name: 'app_schema_db_user_create', methods: ['POST'])]
    public function dbUserCreate(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $username = $epti->getStringValueFromPost(needle: 'username', trim: true, forceString: true, sanitizeHtml: false);
        $password = $epti->getStringValueFromPost(needle: 'password', trim: true, forceString: true, sanitizeHtml: false);
        $userHost = $epti->getStringValueFromPost(needle: 'user_host', trim: true, forceString: true, sanitizeHtml: false);
        $privileges = $epti->getStringValueFromPost(needle: 'privileges', trim: true, forceString: true, sanitizeHtml: false);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server not found.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $repo->createUser($username, $password, $userHost);
        $repo->grantPrivileges($dbName, $username, $privileges, $userHost);
        $repo->flushPrivileges();

        return $this->json(['is_valid' => true, 'message' => 'User created successfully.']);
    }

    #[Route(path: '/db-user-grants-data', name: 'app_schema_db_user_grants_data', methods: ['GET'])]
    public function dbUserGrantsData(
        #[CurrentUser] AppUser $user,
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository,
        DatabaseOwnerRepository $databaseOwnerRepository,
    ): JsonResponse {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $username = $epti->getStringValueFromGet(needle: 'username', trim: true, forceString: true, sanitizeHtml: false);
        $userHost = $epti->getStringValueFromGet(needle: 'user_host', trim: true, forceString: true, sanitizeHtml: false);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server not found.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $databases = $this->showDatabaseWithStatsByOwner($user, $sqlClient, $databaseOwnerRepository, $name);

        return $this->json([
            'databases' => $databases,
            'grants' => $repo->getUserGrantsByDb($username, $userHost),
        ]);
    }

    #[Route(path: '/db-user-grant-save', name: 'app_schema_db_user_grant_save', methods: ['POST'])]
    public function dbUserGrantSave(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $username = $epti->getStringValueFromPost(needle: 'username', trim: true, forceString: true, sanitizeHtml: false);
        $userHost = $epti->getStringValueFromPost(needle: 'user_host', trim: true, forceString: true, sanitizeHtml: false);
        $grantsRaw = $epti->getStringValueFromPost(needle: 'grants', trim: true, forceString: true, sanitizeHtml: false);
        $revokedRaw = $epti->getStringValueFromPost(needle: 'revoked_dbs', trim: true, forceString: true, sanitizeHtml: false);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var list<array{db: string, privileges: string}> $grants */
        $grants = json_decode($grantsRaw, associative: true) ?? [];
        /** @var list<string> $revokedDbs */
        $revokedDbs = json_decode($revokedRaw, associative: true) ?? [];

        $repo = new DatabaseSchemaRepository($sqlClient);

        foreach ($revokedDbs as $dbName) {
            $repo->revokeAllPrivilegesOnDb((string) $dbName, $username, $userHost);
        }

        foreach ($grants as $grant) {
            $repo->grantPrivileges(
                dbName: ((string) $grant['db']),
                username: $username,
                privileges: ((string) $grant['privileges']),
                host: $userHost);
        }

        $repo->flushPrivileges();

        return $this->json(['is_valid' => true, 'message' => 'Permissions updated successfully.']);
    }

    #[Route(path: '/drop-database', name: 'app_schema_drop_database', methods: ['POST'])]
    public function dropDatabase(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server not found.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $ok = $repo->dropDatabase($dbName);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Database deleted successfully.' : 'Error during deletion.',
        ]);
    }

    #[Route(path: '/table-empty', name: 'app_schema_table_empty', methods: ['POST'])]
    public function tableEmpty(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $table = $epti->getStringValueFromPost(needle: 'table', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server not found.'], Response::HTTP_NOT_FOUND);
        }

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $databaseRepositoryPdo->useDbName($dbName);

        $ok = $databaseRepositoryPdo->emptyTable($table);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Table emptied successfully.' : 'Error during table truncation.',
        ]);
    }

    #[Route(path: '/table-drop', name: 'app_schema_table_drop', methods: ['POST'])]
    public function tableDrop(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $table = $epti->getStringValueFromPost(needle: 'table', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server not found.'], Response::HTTP_NOT_FOUND);
        }

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $databaseRepositoryPdo->useDbName($dbName);

        $ok = $databaseRepositoryPdo->dropTable($table);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Table deleted successfully.' : 'Error during deletion.',
        ]);
    }

    #[Route(path: '/table-backup', name: 'app_schema_table_backup', methods: ['POST'])]
    public function tableBackup(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository,
        MysqldumpManager $mysqldumpManager): JsonResponse
    {
        $name = $epti->getStringValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $table = $epti->getStringValueFromPost(needle: 'table', trim: true, forceString: true, sanitizeHtml: true) ?: null;

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server not found.'], Response::HTTP_NOT_FOUND);
        }

        $result = $mysqldumpManager->createBackup($sqlClient, $dbName, $table);

        return $this->json([
            'is_valid' => $result['is_valid'],
            'message' => $result['is_valid'] ? 'Backup completed successfully.' : 'Error during backup.',
            'backup_filename' => basename($result['backup_filename']),
            'msg' => $result['msg'],
        ]);
    }

    #[Route(path: '/enqueue-backup', name: 'app_schema_enqueue_backup', methods: ['POST'])]
    public function enqueueBackup(
        #[CurrentUser] AppUser $user,
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository,
        MysqldumpManager $mysqldumpManager,
        EntityManagerInterface $entityManagerInterface): JsonResponse
    {
        $name = $epti->getStringValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $table = $epti->getStringValueFromPost(needle: 'table', trim: true, forceString: true, sanitizeHtml: true) ?: null;

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server not found.'], Response::HTTP_NOT_FOUND);
        }

        $databaseQueue = new BackupQueue();
        $databaseQueue
                ->setDbName($dbName)
                ->setOwner($user)
                ->setSqlClient($sqlClient)
                ->setTable($table);
        try {
            $entityManagerInterface->persist($databaseQueue);
            $entityManagerInterface->flush();
        } catch (\Exception $exception) {
            return $this->json([
                'is_valid' => false,
                'message' => 'Eccezione:' . $exception->getMessage(),
                'database' => $dbName,
            ]);
        }

        return $this->json([
            'is_valid' => true,
            'message' => 'Backup enqueued successfully',
            'database' => $dbName,
        ]);
    }

    #[Route(path: '/table-restore', name: 'app_schema_table_restore', methods: ['GET'])]
    public function tableRestorePage(
        #[CurrentUser] AppUser $user,
        EffectivePrimitiveTypeIdentifierService $epti,
        MysqldumpManager $mysqldumpManager,
        DatabaseOwnerRepository $databaseOwnerRepository,
    ): Response {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $table = $epti->getStringValueFromGet(needle: 'table', trim: true, forceString: true, sanitizeHtml: true);

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);
        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);

        return $this->render('schema/table_restore.html.twig', [
            'name' => $name,
            'db_name' => $dbName,
            'table' => $table,
            'backups' => $backups,
        ]);
    }

    #[Route(path: '/table-restore-exec', name: 'app_schema_table_restore_exec', methods: ['POST'])]
    public function tableRestoreExec(
        #[CurrentUser] AppUser $user,
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository,
        MysqldumpManager $mysqldumpManager,
        DatabaseOwnerRepository $databaseOwnerRepository): JsonResponse
    {
        $name = $epti->getStringValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $backupFilename = $epti->getStringValueFromPost(needle: 'backup_filename', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server not found.'], Response::HTTP_NOT_FOUND);
        }

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);
        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);
        $selectedBackup = array_find($backups, static fn ($backup): bool => $backup->filename === $backupFilename);

        if (!$selectedBackup) {
            return $this->json(['is_valid' => false, 'message' => 'Backup file not found.'], Response::HTTP_NOT_FOUND);
        }

        $result = $mysqldumpManager->restoreBackup($sqlClient, $dbName, $selectedBackup->path);

        return $this->json([
            'is_valid' => $result['is_valid'],
            'message' => $result['is_valid'] ? 'Restore completed successfully.' : 'Error during restore.',
        ]);
    }

    #[Route(path: '/table-optimize', name: 'app_table_optimize', methods: ['GET'])]
    public function tableOptimize(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $table = $epti->getStringValueFromGet(needle: 'table', trim: true, forceString: true, sanitizeHtml: true);
        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server not found or access denied'], Response::HTTP_NOT_FOUND);
        }

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $databaseRepositoryPdo->useDbName($dbName);

        $optimize = $databaseRepositoryPdo->optimizeTable($table);
        $optimizeAlter = $databaseRepositoryPdo->optimizeWithAlterTable($table);
        $analyze = $databaseRepositoryPdo->analyzeTable($table);

        return $this->json(['is_valid' => $optimize && $optimizeAlter && $analyze]);
    }

    #[Route(path: '/table-show-create', name: 'app_table_show_create', methods: ['GET'])]
    public function tableShowCreate(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getStringValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getStringValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $table = $epti->getStringValueFromGet(needle: 'table', trim: true, forceString: true, sanitizeHtml: true);
        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server not found or access denied'], Response::HTTP_NOT_FOUND);
        }

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $databaseRepositoryPdo->useDbName($dbName);

        $createTable = $databaseRepositoryPdo->showCreateTable($table);

        return $this->json(['is_valid' => true, 'data' => $createTable]);
    }

    #[Route(path: '/backup-list', name: 'app_schema_backup_list', methods: ['GET'])]
    public function backupList(#[CurrentUser] AppUser $user, MysqldumpManager $mysqldumpManager, DatabaseOwnerRepository $databaseOwnerRepository): Response
    {
        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);

        return $this->render('schema/backup_list.html.twig', [
            'backups' => $mysqldumpManager->listBackups($user, $allOwnedDatabased),
        ]);
    }

    #[Route(path: '/backup-view', name: 'app_schema_backup_view', methods: ['GET'])]
    public function backupView(#[CurrentUser] AppUser $user, MysqldumpManager $mysqldumpManager, DatabaseOwnerRepository $databaseOwnerRepository, EffectivePrimitiveTypeIdentifierService $epti): Response
    {
        $filename = $epti->getStringValueFromGet(needle: 'filename', trim: true, forceString: true, sanitizeHtml: true);

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);

        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);
        $backup = array_find($backups, static fn ($b): bool => $b->filename === $filename);

        if (!$backup) {
            throw $this->createNotFoundException('Backup file not found.');
        }

        $content = file_get_contents($backup->path);
        if (false === $content) {
            throw new \RuntimeException('Unable to read the backup file.');
        }

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route(path: '/backup-download', name: 'app_schema_backup_download', methods: ['GET'])]
    public function backupDownload(
        #[CurrentUser] AppUser $user,
        MysqldumpManager $mysqldumpManager,
        DatabaseOwnerRepository $allOwnedDatabased,
        DatabaseOwnerRepository $databaseOwnerRepository,
        EffectivePrimitiveTypeIdentifierService $epti): BinaryFileResponse
    {
        $filename = $epti->getStringValueFromGet(needle: 'filename', trim: true, forceString: true, sanitizeHtml: true);

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);
        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);
        $backup = array_find($backups, static fn ($b): bool => $b->filename === $filename);

        if (!$backup) {
            throw $this->createNotFoundException('Backup file not found.');
        }

        $response = new BinaryFileResponse($backup->path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $backup->filename);

        return $response;
    }

    #[Route(path: '/backup-delete', name: 'app_schema_backup_delete', methods: ['POST'])]
    public function backupDelete(#[CurrentUser] AppUser $user, MysqldumpManager $mysqldumpManager, DatabaseOwnerRepository $databaseOwnerRepository, EffectivePrimitiveTypeIdentifierService $epti): JsonResponse
    {
        $filename = $epti->getStringValueFromPost(needle: 'filename', trim: true, forceString: true, sanitizeHtml: true);

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);
        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);
        $backup = array_find($backups, static fn ($b): bool => $b->filename === $filename);

        if (!$backup) {
            return $this->json(['is_valid' => false, 'message' => 'Backup file not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!unlink($backup->path)) {
            return $this->json(['is_valid' => false, 'message' => 'Unable to delete the file.'], 500);
        }

        return $this->json(['is_valid' => true, 'message' => 'Backup deleted successfully.']);
    }

    /**
     * @return list<array{db_name: string, table_count: int, size_bytes: int}>
     */
    private function showDatabaseWithStatsByOwner(
        AppUser $user,
        SqlClient $sqlClient,
        DatabaseOwnerRepository $databaseOwnerRepository,
        string $name): array
    {
        $repo = new DatabaseSchemaRepository($sqlClient);
        $databases = $repo->getDatabasesWithStats();
        if (\in_array('ROLE_ADMIN', $user->getRoles())) {
            return $databases;
        }

        // Retrieve all databases owned by the user
        $ownedDatabase = $databaseOwnerRepository->findAllByOwner($user);

        // Remove from the full database list those not owned by the user
        $allowedDbNames = array_map(
            static fn (DatabaseOwner $o): string => $o->getDbName() ?? '',
            array_filter($ownedDatabase, static fn (DatabaseOwner $o): bool => $o->getSqlClient()?->getName() === $name)
        );

        // Return only the databases owned by the user
        return array_values(
            array_filter($databases, static fn (array $db): bool => \in_array($db['db_name'], $allowedDbNames, strict: true))
        );
    }
}
