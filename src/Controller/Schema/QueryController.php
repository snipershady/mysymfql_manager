<?php

declare(strict_types=1);

namespace App\Controller\Schema;

use App\Attribute\Audited;
use App\Dto\Input\DatabaseTarget;
use App\Dto\Input\SqlStatement;
use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Enum\AuditActionEnum;
use App\Enum\AuditOutcomeEnum;
use App\Exception\RepositoryException;
use App\Repository\AuditLogRepository;
use App\RepositoryPDO\ManagedServerRepositoryFactory;
use App\Security\SqlClientAccessResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * SQL console of a database: page, schema for the autocompletion, execution, history.
 *
 * History: the current user's own statements on this database, read from the audit log (same 90-day retention,
 * passwords shown as '***'). Same access rule as the console; no MySQL privilege (catalog only).
 *
 * Panel access: server/database ownership through SqlClientAccessResolver (admins see everything);
 * input mapped to the DTOs of App\Dto\Input; routes under /schema (names unchanged).
 */
#[Route(path: '/schema')]
final class QueryController extends AbstractController
{
    public function __construct(
        private readonly SqlClientAccessResolver $access,
        private readonly ManagedServerRepositoryFactory $repositories,
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

    #[Route(path: '/query-history', name: 'app_schema_query_history', methods: ['GET'])]
    public function queryHistory(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] DatabaseTarget $input,
        #[CurrentUser] AppUser $user,
        AuditLogRepository $auditLog,
    ): JsonResponse {
        if (!$this->access->database($input->name, $input->dbName) instanceof SqlClient) {
            return $this->json(['error' => 'Server non trovato o accesso negato.'], Response::HTTP_NOT_FOUND);
        }

        $history = $auditLog->recentQueries((int) $user->getId(), $input->name, $input->dbName);

        return $this->json(['history' => array_map(static fn (array $item): array => [
            'sql' => $item['sql'],
            'occurred_at' => $item['occurred_at']->format(\DATE_ATOM),
            'ok' => AuditOutcomeEnum::SUCCESS === $item['outcome'],
        ], $history)]);
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

        $schema = $this->repositories->schema($sqlClient);

        return $this->json(['tables' => $schema->getColumnsBySchema($dbName)]);
    }

    #[Route(path: '/query-execute', name: 'app_schema_query_execute', methods: ['POST'])]
    #[Audited(action: AuditActionEnum::QUERY_EXECUTE)]
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

        $schema = $this->repositories->schema($sqlClient);
        $schema->useDbName($dbName);

        try {
            $result = $schema->runQuery($sql);
        } catch (RepositoryException $repositoryException) {
            // The statement typed by the user failed: MySQL's own message, as the mysql client shows it.
            return $this->json(['is_valid' => false, 'message' => $repositoryException->mysqlError()], Response::HTTP_BAD_REQUEST);
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
