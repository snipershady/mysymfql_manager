<?php

declare(strict_types=1);

namespace App\Controller\Schema;

use App\Dto\Input\DatabaseTarget;
use App\Dto\Input\TableTarget;
use App\Entity\SqlClient;
use App\RepositoryPDO\ManagedServerRepositoryFactory;
use App\Security\SqlClientAccessResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tables of a database: list (page + JSON), empty, drop, optimize, SHOW CREATE TABLE.
 *
 * Panel access: server/database ownership through SqlClientAccessResolver (admins see everything);
 * input mapped to the DTOs of App\Dto\Input; routes under /schema (names unchanged).
 */
#[Route(path: '/schema')]
final class TableController extends AbstractController
{
    public function __construct(
        private readonly SqlClientAccessResolver $access,
        private readonly ManagedServerRepositoryFactory $repositories,
    ) {
    }

    #[Route(path: '/tables', name: 'app_schema_tables', methods: ['GET'])]
    public function tables(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] DatabaseTarget $input): Response
    {
        $name = $input->name;
        $dbName = $input->dbName;

        $sqlClient = $this->access->database($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->render('schema/tables.html.twig', [
                'name' => '',
                'db_name' => '',
                'db_version' => '',
            ]);
        }

        $schema = $this->repositories->schema($sqlClient);
        $versionRow = $schema->getVersion();
        $dbVersion = $versionRow['@@version'] ?? null;

        return $this->render('schema/tables.html.twig', [
            'name' => $name,
            'db_name' => $dbName,
            'db_version' => $dbVersion,
        ]);
    }

    #[Route(path: '/show-tables-get-data', name: 'app_show_tables_get_data', methods: ['GET'])]
    public function showTablesGetData(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] DatabaseTarget $input): JsonResponse
    {
        $name = $input->name;
        $dbName = $input->dbName;
        $sqlClient = $this->access->database($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server non trovato o accesso negato.'], Response::HTTP_NOT_FOUND);
        }

        $schema = $this->repositories->schema($sqlClient);
        $schema->useDbName($dbName);

        return $this->json(['data' => $schema->showTablesWithStats()]);
    }

    #[Route(path: '/table-empty', name: 'app_schema_table_empty', methods: ['POST'])]
    public function tableEmpty(
        #[MapRequestPayload] TableTarget $input): JsonResponse
    {
        $name = $input->name;
        $dbName = $input->dbName;
        $table = $input->table;

        $sqlClient = $this->access->database($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $schema = $this->repositories->schema($sqlClient);
        $schema->useDbName($dbName);

        $ok = $schema->emptyTable($table);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Tabella svuotata.' : 'Errore durante lo svuotamento della tabella.',
        ]);
    }

    #[Route(path: '/table-drop', name: 'app_schema_table_drop', methods: ['POST'])]
    public function tableDrop(
        #[MapRequestPayload] TableTarget $input): JsonResponse
    {
        $name = $input->name;
        $dbName = $input->dbName;
        $table = $input->table;

        $sqlClient = $this->access->database($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $schema = $this->repositories->schema($sqlClient);
        $schema->useDbName($dbName);

        $ok = $schema->dropTable($table);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Tabella eliminata.' : "Errore durante l'eliminazione.",
        ]);
    }

    #[Route(path: '/table-optimize', name: 'app_table_optimize', methods: ['POST'])]
    public function tableOptimize(
        #[MapRequestPayload] TableTarget $input): JsonResponse
    {
        $name = $input->name;
        $dbName = $input->dbName;
        $table = $input->table;
        $sqlClient = $this->access->database($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server non trovato o accesso negato.'], Response::HTTP_NOT_FOUND);
        }

        $schema = $this->repositories->schema($sqlClient);
        $schema->useDbName($dbName);

        $optimize = $schema->optimizeTable($table);
        $optimizeAlter = $schema->optimizeWithAlterTable($table);
        $analyze = $schema->analyzeTable($table);

        return $this->json(['is_valid' => $optimize && $optimizeAlter && $analyze]);
    }

    #[Route(path: '/table-show-create', name: 'app_table_show_create', methods: ['GET'])]
    public function tableShowCreate(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] TableTarget $input): JsonResponse
    {
        $name = $input->name;
        $dbName = $input->dbName;
        $table = $input->table;
        $sqlClient = $this->access->database($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server non trovato o accesso negato.'], Response::HTTP_NOT_FOUND);
        }

        $schema = $this->repositories->schema($sqlClient);
        $schema->useDbName($dbName);

        $createTable = $schema->showCreateTable($table);

        return $this->json(['is_valid' => true, 'data' => $createTable]);
    }
}
