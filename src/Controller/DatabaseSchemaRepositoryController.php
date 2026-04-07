<?php

namespace App\Controller;

use App\Dto\MysqlUser;
use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use App\Enum\CharsetEnum;
use App\Enum\CollationEnum;
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
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

#[Route('/schema')]
final class DatabaseSchemaRepositoryController extends AbstractController
{
    #[Route('/dashboard-stats', name: 'app_schema_dashboard_stats', methods: ['GET'])]
    public function dashboardStats(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository,
        DatabaseOwnerRepository $databaseOwnerRepository,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['error' => 'Non autorizzato'], 401);
        }

        $name = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);

        $ownedClients = $sqlClientRepository->findAllOwned($user);
        $sqlClient = \array_find($ownedClients, fn ($ownedClient): bool => $ownedClient->getName() === $name);

        if (null === $sqlClient) {
            return $this->json(['error' => 'Server non trovato o accesso negato'], Response::HTTP_NOT_FOUND);
        }
        $repo = new DatabaseSchemaRepository($sqlClient);

        $databases = $this->showDatabaseWithStatsByOwner($user, $sqlClient, $databaseOwnerRepository, $name);

        return $this->json([
            'db_count' => count($databases),
            'active_connections' => $repo->getActiveConnections(),
            'running_processes' => $repo->getRunningProcesses(),
            'blocked_processes' => $repo->getBlockedProcesses(),
            'databases' => $databases,
        ]);
    }

    #[Route('/databases', name: 'app_schema_databases', methods: ['GET'])]
    public function databases(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw $this->createAccessDeniedException();
        }

        $selectedName = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);

        return $this->render('schema/databases.html.twig', [
            'sql_clients' => $sqlClientRepository->findAllOwned($user),
            'charsets' => CharsetEnum::cases(),
            'collations' => CollationEnum::cases(),
            'selected_name' => $selectedName,
        ]);
    }

    #[Route('/create-database', name: 'app_schema_create_database', methods: ['POST'])]
    public function createDatabase(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository,
        EntityManagerInterface $entityManagerInterface): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['is_valid' => false, 'message' => 'Non Autorizzato.'], Response::HTTP_UNAUTHORIZED);
        }

        $name = $epti->getTypedValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getTypedValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $charsetVal = $epti->getTypedValueFromPost(needle: 'charset', trim: true, forceString: true, sanitizeHtml: true);
        $collateVal = $epti->getTypedValueFromPost(needle: 'collation', trim: true, forceString: true, sanitizeHtml: true);
        $username = $epti->getTypedValueFromPost(needle: 'username', trim: true, forceString: true, sanitizeHtml: false);
        $password = $epti->getTypedValueFromPost(needle: 'password', trim: true, forceString: true, sanitizeHtml: false);
        $userHost = $epti->getTypedValueFromPost(needle: 'user_host', trim: true, forceString: true, sanitizeHtml: false);
        $privileges = $epti->getTypedValueFromPost(needle: 'privileges', trim: true, forceString: true, sanitizeHtml: false);

        if ('' === $username) {
            return $this->json(['is_valid' => false, 'message' => 'Parametro username non può essere vuoto'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === $dbName) {
            return $this->json(['is_valid' => false, 'message' => 'Parametro db_name non può essere vuoto'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === $name) {
            return $this->json(['is_valid' => false, 'message' => 'Parametro name non può essere vuoto'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === $password) {
            return $this->json(['is_valid' => false, 'message' => 'Parametro password non può essere vuoto'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === $userHost) {
            return $this->json(['is_valid' => false, 'message' => 'Parametro user_host non può essere vuoto'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === $privileges) {
            return $this->json(['is_valid' => false, 'message' => 'Parametro privileges non può essere vuoto'], Response::HTTP_BAD_REQUEST);
        }

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (null === $sqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
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
            return $this->json(['is_valid' => false, 'message' => 'Eccezione: '.$exception->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Qui assegno il database appena creato all'user come "owner"
        $dbowner = new DatabaseOwner()->setDbName($dbName)->setOwner($user)->setSqlClient($sqlClient);
        $entityManagerInterface->persist($dbowner);
        $entityManagerInterface->flush();

        return $this->json(['is_valid' => true, 'message' => 'Database creato con successo.']);
    }

    #[Route('/tables', name: 'app_schema_tables', methods: ['GET'])]
    public function tables(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): Response
    {
        $name = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getTypedValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);

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

    #[Route('/show-databases-get-data', name: 'app_show_databases_get_data', methods: ['GET'])]
    public function showDatabasesGetData(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository,
        DatabaseOwnerRepository $databaseOwnerRepository,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['is_valid' => false, 'message' => 'Non Autorizzato.'], Response::HTTP_UNAUTHORIZED);
        }

        $name = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $sqlClient = $sqlClientRepository->findOneByName($name);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $versionRow = $databaseRepositoryPdo->getVersion();

        return $this->json([
            'data' => $this->showDatabaseWithStatsByOwner($user, $sqlClient, $databaseOwnerRepository, $name),
            'version' => $versionRow['@@version'] ?? null,
        ]);
    }

    #[Route('/show-tables-get-data', name: 'app_show_tables_get_data', methods: ['GET'])]
    public function showTablesGetData(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getTypedValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $sqlClient = $sqlClientRepository->findOneByName($name);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $databaseRepositoryPdo->useDbName($dbName);

        return $this->json(['data' => $databaseRepositoryPdo->showTablesWithStats()]);
    }

    #[Route('/show-engine-status-get-data', name: 'app_show_engine_status_get_data', methods: ['GET'])]
    public function showEngineInnodbStatusGetData(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $sqlClient = $sqlClientRepository->findOneByName($name);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);

        return $this->json(['data' => $databaseRepositoryPdo->showEngineInnodbStatus()]);
    }

    #[Route('/show-processlist-get-data', name: 'app_show_processlist_get_data', methods: ['GET'])]
    public function showProcessListGetData(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);

        return $this->json(['data' => $databaseRepositoryPdo->showProcessList()]);
    }

    #[Route('/engine-status', name: 'app_schema_engine_status', methods: ['GET'])]
    public function engineStatus(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): Response
    {
        $name = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $sqlClient = $sqlClientRepository->findOneByName($name);
        $repo = new DatabaseSchemaRepository($sqlClient);

        return $this->render('schema/engine_status.html.twig', [
            'name' => $name,
            'status' => $repo->showEngineInnodbStatus(),
        ]);
    }

    #[Route('/process-list', name: 'app_schema_process_list', methods: ['GET'])]
    public function processListPage(EffectivePrimitiveTypeIdentifierService $epti): Response
    {
        $name = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);

        return $this->render('schema/process_list.html.twig', [
            'name' => $name,
        ]);
    }

    #[Route('/kill-process', name: 'app_schema_kill_process', methods: ['POST'])]
    public function killProcess(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getTypedValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $pid = (int) $epti->getTypedValueFromPost(needle: 'pid', trim: true, forceString: true, sanitizeHtml: true);
        $sqlClient = $sqlClientRepository->findOneByName($name);

        if (null === $sqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $ok = $repo->killProcessById($pid);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? sprintf('Processo %d terminato.', $pid) : sprintf('Impossibile terminare il processo %d.', $pid),
        ]);
    }

    #[Route('/db-users', name: 'app_schema_db_users', methods: ['GET'])]
    public function dbUsers(EffectivePrimitiveTypeIdentifierService $epti): Response
    {
        $name = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getTypedValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);

        return $this->render('schema/db_users.html.twig', [
            'name' => $name,
            'db_name' => $dbName,
        ]);
    }

    #[Route('/db-users-get-data', name: 'app_schema_db_users_get_data', methods: ['GET'])]
    public function dbUsersGetData(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getTypedValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (null === $sqlClient) {
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

    #[Route('/db-user-drop', name: 'app_schema_db_user_drop', methods: ['POST'])]
    public function dbUserDrop(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getTypedValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $username = $epti->getTypedValueFromPost(needle: 'username', trim: true, forceString: true, sanitizeHtml: false);
        $userHost = $epti->getTypedValueFromPost(needle: 'user_host', trim: true, forceString: true, sanitizeHtml: false);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (null === $sqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $ok = $repo->dropUser($username, $userHost);
        if ($ok) {
            $repo->flushPrivileges();
        }

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Utente eliminato con successo.' : "Errore durante l'eliminazione.",
        ]);
    }

    #[Route('/db-user-change-password', name: 'app_schema_db_user_change_password', methods: ['POST'])]
    public function dbUserChangePassword(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getTypedValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $username = $epti->getTypedValueFromPost(needle: 'username', trim: true, forceString: true, sanitizeHtml: false);
        $userHost = $epti->getTypedValueFromPost(needle: 'user_host', trim: true, forceString: true, sanitizeHtml: false);
        $newPassword = $epti->getTypedValueFromPost(needle: 'password', trim: true, forceString: true, sanitizeHtml: false);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (null === $sqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $ok = $repo->changeUserPassword($username, $newPassword, $userHost);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Password aggiornata con successo.' : "Errore durante l'aggiornamento della password.",
        ]);
    }

    #[Route('/db-user-create', name: 'app_schema_db_user_create', methods: ['POST'])]
    public function dbUserCreate(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getTypedValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getTypedValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $username = $epti->getTypedValueFromPost(needle: 'username', trim: true, forceString: true, sanitizeHtml: false);
        $password = $epti->getTypedValueFromPost(needle: 'password', trim: true, forceString: true, sanitizeHtml: false);
        $userHost = $epti->getTypedValueFromPost(needle: 'user_host', trim: true, forceString: true, sanitizeHtml: false);
        $privileges = $epti->getTypedValueFromPost(needle: 'privileges', trim: true, forceString: true, sanitizeHtml: false);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (null === $sqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $repo->createUser($username, $password, $userHost);
        $repo->grantPrivileges($dbName, $username, $privileges, $userHost);
        $repo->flushPrivileges();

        return $this->json(['is_valid' => true, 'message' => 'Utente creato con successo.']);
    }

    #[Route('/db-user-grants-data', name: 'app_schema_db_user_grants_data', methods: ['GET'])]
    public function dbUserGrantsData(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository,
        DatabaseOwnerRepository $databaseOwnerRepository,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['error' => 'Non autorizzato'], 401);
        }

        $name = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $username = $epti->getTypedValueFromGet(needle: 'username', trim: true, forceString: true, sanitizeHtml: false);
        $userHost = $epti->getTypedValueFromGet(needle: 'user_host', trim: true, forceString: true, sanitizeHtml: false);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (null === $sqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $databases = $this->showDatabaseWithStatsByOwner($user, $sqlClient, $databaseOwnerRepository, $name);

        return $this->json([
            'databases' => $databases,
            'grants' => $repo->getUserGrantsByDb($username, $userHost),
        ]);
    }

    #[Route('/db-user-grant-save', name: 'app_schema_db_user_grant_save', methods: ['POST'])]
    public function dbUserGrantSave(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['is_valid' => false, 'message' => 'Non autorizzato'], Response::HTTP_UNAUTHORIZED);
        }

        $name = $epti->getTypedValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $username = $epti->getTypedValueFromPost(needle: 'username', trim: true, forceString: true, sanitizeHtml: false);
        $userHost = $epti->getTypedValueFromPost(needle: 'user_host', trim: true, forceString: true, sanitizeHtml: false);
        $grantsRaw = $epti->getTypedValueFromPost(needle: 'grants', trim: true, forceString: true, sanitizeHtml: false);
        $revokedRaw = $epti->getTypedValueFromPost(needle: 'revoked_dbs', trim: true, forceString: true, sanitizeHtml: false);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (null === $sqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        /** @var list<array{db: string, privileges: string}> $grants */
        $grants = json_decode($grantsRaw, true) ?? [];
        /** @var list<string> $revokedDbs */
        $revokedDbs = json_decode($revokedRaw, true) ?? [];

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

        return $this->json(['is_valid' => true, 'message' => 'Permessi aggiornati con successo.']);
    }

    #[Route('/drop-database', name: 'app_schema_drop_database', methods: ['POST'])]
    public function dropDatabase(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['is_valid' => false, 'message' => 'Non autorizzato'], Response::HTTP_UNAUTHORIZED);
        }

        $name = $epti->getTypedValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getTypedValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (null === $sqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $ok = $repo->dropDatabase($dbName);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Database eliminato con successo.' : "Errore durante l'eliminazione.",
        ]);
    }

    #[Route('/table-empty', name: 'app_schema_table_empty', methods: ['POST'])]
    public function tableEmpty(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['is_valid' => false, 'message' => 'Non autorizzato'], Response::HTTP_UNAUTHORIZED);
        }

        $name = $epti->getTypedValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getTypedValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $table = $epti->getTypedValueFromPost(needle: 'table', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (null === $sqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $databaseRepositoryPdo->useDbName($dbName);

        $ok = $databaseRepositoryPdo->emptyTable($table);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Tabella svuotata con successo.' : 'Errore durante lo svuotamento.',
        ]);
    }

    #[Route('/table-drop', name: 'app_schema_table_drop', methods: ['POST'])]
    public function tableDrop(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['is_valid' => false, 'message' => 'Non autorizzato'], Response::HTTP_UNAUTHORIZED);
        }

        $name = $epti->getTypedValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getTypedValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $table = $epti->getTypedValueFromPost(needle: 'table', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (null === $sqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $databaseRepositoryPdo->useDbName($dbName);

        $ok = $databaseRepositoryPdo->dropTable($table);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Tabella eliminata con successo.' : "Errore durante l'eliminazione.",
        ]);
    }

    #[Route('/table-backup', name: 'app_schema_table_backup', methods: ['POST'])]
    public function tableBackup(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository,
        MysqldumpManager $mysqldumpManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['is_valid' => false, 'message' => 'Non autorizzato'], Response::HTTP_UNAUTHORIZED);
        }

        $name = $epti->getTypedValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getTypedValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $table = $epti->getTypedValueFromPost(needle: 'table', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (null === $sqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $result = $mysqldumpManager->createBackup($sqlClient, $dbName, $table);

        return $this->json([
            'is_valid' => $result['is_valid'],
            'message' => $result['is_valid'] ? 'Backup completato con successo.' : 'Errore durante il backup.',
            'backup_filename' => basename((string) $result['backup_filename']),
            'msg' => $result['msg'],
        ]);
    }

    #[Route('/table-restore', name: 'app_schema_table_restore', methods: ['GET'])]
    public function tableRestorePage(
        EffectivePrimitiveTypeIdentifierService $epti,
        MysqldumpManager $mysqldumpManager,
        DatabaseOwnerRepository $databaseOwnerRepository,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['is_valid' => false, 'message' => 'Non autorizzato'], Response::HTTP_UNAUTHORIZED);
        }

        $name = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getTypedValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $table = $epti->getTypedValueFromGet(needle: 'table', trim: true, forceString: true, sanitizeHtml: true);

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);
        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);

        return $this->render('schema/table_restore.html.twig', [
            'name' => $name,
            'db_name' => $dbName,
            'table' => $table,
            'backups' => $backups,
        ]);
    }

    #[Route('/table-restore-exec', name: 'app_schema_table_restore_exec', methods: ['POST'])]
    public function tableRestoreExec(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository,
        MysqldumpManager $mysqldumpManager,
        DatabaseOwnerRepository $databaseOwnerRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['is_valid' => false, 'message' => 'Non autorizzato'], Response::HTTP_UNAUTHORIZED);
        }
        $name = $epti->getTypedValueFromPost(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getTypedValueFromPost(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $backupFilename = $epti->getTypedValueFromPost(needle: 'backup_filename', trim: true, forceString: true, sanitizeHtml: true);

        $sqlClient = $sqlClientRepository->findOneByName($name);
        if (null === $sqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);
        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);
        $selectedBackup = \array_find($backups, fn ($backup): bool => $backup->filename === $backupFilename);

        if (!$selectedBackup) {
            return $this->json(['is_valid' => false, 'message' => 'File di backup non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $result = $mysqldumpManager->restoreBackup($sqlClient, $dbName, $selectedBackup->path);

        return $this->json([
            'is_valid' => $result['is_valid'],
            'message' => $result['is_valid'] ? 'Ripristino completato con successo.' : 'Errore durante il ripristino.',
        ]);
    }

    #[Route('/table-optimize', name: 'app_table_optimize', methods: ['GET'])]
    public function tableOptimize(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getTypedValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $table = $epti->getTypedValueFromGet(needle: 'table', trim: true, forceString: true, sanitizeHtml: true);
        $sqlClient = $sqlClientRepository->findOneByName($name);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $databaseRepositoryPdo->useDbName($dbName);

        $optimize = $databaseRepositoryPdo->optimizeTable($table);
        $optimizeAlter = $databaseRepositoryPdo->optimizeWithAlterTable($table);
        $analyze = $databaseRepositoryPdo->analyzeTable($table);

        return $this->json(['is_valid' => $optimize && $optimizeAlter && $analyze]);
    }
    
    #[Route('/table-show-create', name: 'app_table_show_create', methods: ['GET'])]
    public function tableShowCreate(
        EffectivePrimitiveTypeIdentifierService $epti,
        SqlClientRepository $sqlClientRepository): JsonResponse
    {
        $name = $epti->getTypedValueFromGet(needle: 'name', trim: true, forceString: true, sanitizeHtml: true);
        $dbName = $epti->getTypedValueFromGet(needle: 'db_name', trim: true, forceString: true, sanitizeHtml: true);
        $table = $epti->getTypedValueFromGet(needle: 'table', trim: true, forceString: true, sanitizeHtml: true);
        $sqlClient = $sqlClientRepository->findOneByName($name);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $databaseRepositoryPdo->useDbName($dbName);

        $createTable = $databaseRepositoryPdo->showCreateTable($table);

        return $this->json(['is_valid' => true, 'data'=> $createTable]);
    }

    #[Route('/backup-list', name: 'app_schema_backup_list', methods: ['GET'])]
    public function backupList(MysqldumpManager $mysqldumpManager, DatabaseOwnerRepository $databaseOwnerRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['is_valid' => false, 'message' => 'Non autorizzato'], Response::HTTP_UNAUTHORIZED);
        }
        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);

        return $this->render('schema/backup_list.html.twig', [
            'backups' => $mysqldumpManager->listBackups($user, $allOwnedDatabased),
        ]);
    }

    #[Route('/backup-view', name: 'app_schema_backup_view', methods: ['GET'])]
    public function backupView(MysqldumpManager $mysqldumpManager, DatabaseOwnerRepository $databaseOwnerRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['is_valid' => false, 'message' => 'Non autorizzato'], Response::HTTP_UNAUTHORIZED);
        }

        $epti = new EffectivePrimitiveTypeIdentifierService();
        $filename = $epti->getTypedValueFromGet(needle: 'filename', trim: true, forceString: true, sanitizeHtml: true);

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);

        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);
        $backup = \array_find($backups, fn ($b): bool => $b->filename === $filename);

        if (!$backup) {
            throw $this->createNotFoundException('File di backup non trovato.');
        }

        $content = file_get_contents($backup->path);
        if (false === $content) {
            throw new \RuntimeException('Impossibile leggere il file di backup.');
        }

        return new Response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route('/backup-download', name: 'app_schema_backup_download', methods: ['GET'])]
    public function backupDownload(
            MysqldumpManager $mysqldumpManager,
            DatabaseOwnerRepository $allOwnedDatabased,
            DatabaseOwnerRepository $databaseOwnerRepository): BinaryFileResponse
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['is_valid' => false, 'message' => 'Non autorizzato'], Response::HTTP_UNAUTHORIZED);
        }

        $epti = new EffectivePrimitiveTypeIdentifierService();
        $filename = $epti->getTypedValueFromGet(needle: 'filename', trim: true, forceString: true, sanitizeHtml: true);

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);
        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);
        $backup = \array_find($backups, fn ($b): bool => $b->filename === $filename);

        if (!$backup) {
            throw $this->createNotFoundException('File di backup non trovato.');
        }

        $response = new BinaryFileResponse($backup->path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $backup->filename);

        return $response;
    }

    #[Route('/backup-delete', name: 'app_schema_backup_delete', methods: ['POST'])]
    public function backupDelete(MysqldumpManager $mysqldumpManager, DatabaseOwnerRepository $allOwnedDatabased): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            return $this->json(['is_valid' => false, 'message' => 'Non autorizzato'], Response::HTTP_UNAUTHORIZED);
        }
        $epti = new EffectivePrimitiveTypeIdentifierService();
        $filename = $epti->getTypedValueFromPost(needle: 'filename', trim: true, forceString: true, sanitizeHtml: true);

        $allOwnedDatabased = $databaseOwnerRepository->findAllByOwner($user);
        $backups = $mysqldumpManager->listBackups($user, $allOwnedDatabased);
        $backup = \array_find($backups, fn ($b): bool => $b->filename === $filename);

        if (!$backup) {
            return $this->json(['is_valid' => false, 'message' => 'File di backup non trovato.'], Response::HTTP_NOT_FOUND);
        }

        if (!unlink($backup->path)) {
            return $this->json(['is_valid' => false, 'message' => 'Impossibile eliminare il file.'], 500);
        }

        return $this->json(['is_valid' => true, 'message' => 'Backup eliminato con successo.']);
    }

    private function showDatabaseWithStatsByOwner(
        AppUser $user,
        SqlClient $sqlClient,
        DatabaseOwnerRepository $databaseOwnerRepository,
        string $name): array
    {
        $repo = new DatabaseSchemaRepository($sqlClient);
        $databases = $repo->getDatabasesWithStats();
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return $databases;
        }

        // Retrieve di tutti i database che l'utente possiede
        $ownedDatabase = $databaseOwnerRepository->findAllByOwner($user);

        // Rimuovo dalla lista completa dei database quelli che l'utente non possiede
        $allowedDbNames = array_map(
            fn (DatabaseOwner $o): string => $o->getDbName(),
            array_filter($ownedDatabase, fn (DatabaseOwner $o): bool => $o->getSqlClient()?->getName() === $name)
        );

        // Restituisco solo i database che l'utente possiede
        return array_values(
            array_filter($databases, fn (array $db): bool => in_array($db['db_name'], $allowedDbNames, true))
        );
    }
}
