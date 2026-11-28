<?php

declare(strict_types=1);

namespace App\Controller\Schema;

use App\Attribute\Audited;
use App\Dto\Input\DatabaseTarget;
use App\Dto\Input\NewDatabase;
use App\Dto\Input\OptionalServer;
use App\Dto\Input\ServerTarget;
use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Enum\AuditActionEnum;
use App\Enum\CharsetEnum;
use App\Enum\CollationEnum;
use App\Exception\ProvisioningConflictException;
use App\Exception\ProvisioningException;
use App\Repository\SqlClientRepository;
use App\RepositoryPDO\ManagedServerRepositoryFactory;
use App\Security\SchemaVisibility;
use App\Security\SqlClientAccessResolver;
use App\Service\DatabaseProvisioner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Databases of a server: list (page + JSON), creation with the MySQL account, drop.
 *
 * Panel access: server/database ownership through SqlClientAccessResolver (admins see everything);
 * input mapped to the DTOs of App\Dto\Input; routes under /schema (names unchanged).
 */
#[Route(path: '/schema')]
final class DatabaseController extends AbstractController
{
    public function __construct(
        private readonly SqlClientAccessResolver $access,
        private readonly SchemaVisibility $visibility,
        private readonly ManagedServerRepositoryFactory $repositories,
    ) {
    }

    #[Route(path: '/databases', name: 'app_schema_databases', methods: ['GET'])]
    public function databases(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] OptionalServer $input,
        #[CurrentUser] AppUser $user,
        SqlClientRepository $sqlClientRepository): Response
    {
        $selectedName = $input->name ?? '';

        return $this->render('schema/databases.html.twig', [
            'sql_clients' => $sqlClientRepository->findAllOwned($user),
            'charsets' => CharsetEnum::cases(),
            'collations' => CollationEnum::cases(),
            'selected_name' => $selectedName,
        ]);
    }

    #[Route(path: '/show-databases-get-data', name: 'app_show_databases_get_data', methods: ['GET'])]
    public function showDatabasesGetData(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ServerTarget $input,
        #[CurrentUser] AppUser $user
    ): JsonResponse {
        $name = $input->name;
        $sqlClient = $this->access->server($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server non trovato o accesso negato.'], Response::HTTP_NOT_FOUND);
        }

        $schema = $this->repositories->schema($sqlClient);
        $versionRow = $schema->getVersion();

        return $this->json([
            'data' => $this->visibility->databasesWithStats($user, $sqlClient, $schema),
            'version' => $versionRow['@@version'] ?? null,
        ]);
    }

    #[Route(path: '/create-database', name: 'app_schema_create_database', methods: ['POST'])]
    #[Audited(action: AuditActionEnum::DATABASE_CREATE)]
    public function createDatabase(
        #[MapRequestPayload] NewDatabase $input,
        #[CurrentUser] AppUser $user,
        DatabaseProvisioner $provisioner): JsonResponse
    {
        // Required fields, identifier syntax, charset/collation enums and the privilege whitelist are
        // validated by NewDatabase (#[MapRequestPayload]): invalid input never reaches this point.
        $sqlClient = $this->access->server($input->name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $provisioner->createDatabaseWithOwner(
                $this->repositories->schema($sqlClient), $this->repositories->accounts($sqlClient), $sqlClient, $user,
                $input->dbName, $input->charset, $input->collation, $input->username, $input->password, $input->userHost, $input->grantClause(),
            );
        } catch (ProvisioningConflictException $conflict) {
            return $this->json(['is_valid' => false, 'message' => $conflict->getMessage()], Response::HTTP_CONFLICT);
        } catch (ProvisioningException $provisioningException) {
            return $this->json(['is_valid' => false, 'message' => $provisioningException->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['is_valid' => true, 'message' => 'Database creato.']);
    }

    #[Route(path: '/drop-database', name: 'app_schema_drop_database', methods: ['POST'])]
    #[Audited(action: AuditActionEnum::DATABASE_DROP)]
    public function dropDatabase(
        #[MapRequestPayload] DatabaseTarget $input,
        DatabaseProvisioner $provisioner): JsonResponse
    {
        $name = $input->name;
        $dbName = $input->dbName;

        $sqlClient = $this->access->database($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $provisioner->dropDatabaseAndOwnerships($this->repositories->schema($sqlClient), $sqlClient, $dbName);
        } catch (ProvisioningException $provisioningException) {
            return $this->json(['is_valid' => false, 'message' => $provisioningException->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['is_valid' => true, 'message' => 'Database eliminato.']);
    }
}
