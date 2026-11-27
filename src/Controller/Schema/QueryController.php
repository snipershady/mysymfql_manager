<?php

declare(strict_types=1);

namespace App\Controller\Schema;

use App\Dto\Input\DatabaseTarget;
use App\Dto\Input\SqlStatement;
use App\Entity\SqlClient;
use App\Exception\RepositoryException;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Security\SqlClientAccessResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SQL console of a database: page, schema for the autocompletion, execution.
 *
 * Panel access: server/database ownership through SqlClientAccessResolver (admins see everything);
 * input mapped to the DTOs of App\Dto\Input; routes under /schema (names unchanged).
 */
#[Route(path: '/schema')]
final class QueryController extends AbstractController
{
    public function __construct(
        private readonly SqlClientAccessResolver $access,
    ) {
    }

    #[Route(path: '/query', name: 'app_schema_query', methods: ['GET'])]
    public function query(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] DatabaseTarget $input): Response
    {
        $name = $input->name;
        $dbName = $input->dbName;

        $sqlClient = $this->access->database($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            throw $this->createNotFoundException('Server non trovato.');
        }

        return $this->render('schema/query.html.twig', [
            'name' => $name,
            'db_name' => $dbName,
        ]);
    }

    #[Route(path: '/columns-get-data', name: 'app_schema_columns_get_data', methods: ['GET'])]
    public function columnsGetData(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] DatabaseTarget $input): JsonResponse
    {
        $name = $input->name;
        $dbName = $input->dbName;

        if ('' === $name || '' === $dbName) {
            return $this->json(['error' => 'Missing name or db_name parameter'], Response::HTTP_BAD_REQUEST);
        }

        $sqlClient = $this->access->database($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server non trovato o accesso negato.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);

        return $this->json(['tables' => $repo->getColumnsBySchema($dbName)]);
    }

    #[Route(path: '/query-execute', name: 'app_schema_query_execute', methods: ['POST'])]
    public function queryExecute(
        #[MapRequestPayload] SqlStatement $input): JsonResponse
    {
        $name = $input->name;
        $dbName = $input->dbName;
        $sql = $input->sql; // not blank: validated by SqlStatement

        $sqlClient = $this->access->database($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
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
}
