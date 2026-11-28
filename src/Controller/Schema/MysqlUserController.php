<?php

declare(strict_types=1);

namespace App\Controller\Schema;

use App\Attribute\Audited;
use App\Dto\Input\AccountPassword;
use App\Dto\Input\AccountTarget;
use App\Dto\Input\GrantChanges;
use App\Dto\Input\GrantInput;
use App\Dto\Input\NewAccount;
use App\Dto\Input\OptionalDatabaseTarget;
use App\Dto\MysqlUser;
use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Enum\AuditActionEnum;
use App\Exception\ProvisioningConflictException;
use App\Exception\ProvisioningException;
use App\RepositoryPDO\ManagedServerRepositoryFactory;
use App\Security\SchemaVisibility;
use App\Security\SqlClientAccessResolver;
use App\Service\DatabaseProvisioner;
use App\Service\MysqlAccountManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * MySQL accounts of a server: list, creation, password, grants, drop.
 *
 * Panel access: server/database ownership through SqlClientAccessResolver (admins see everything);
 * input mapped to the DTOs of App\Dto\Input; routes under /schema (names unchanged).
 */
#[Route(path: '/schema')]
final class MysqlUserController extends AbstractController
{
    public function __construct(
        private readonly SqlClientAccessResolver $access,
        private readonly SchemaVisibility $visibility,
        private readonly ManagedServerRepositoryFactory $repositories,
        private readonly MysqlAccountManager $accountManager,
    ) {
    }

    #[Route(path: '/db-users', name: 'app_schema_db_users', methods: ['GET'])]
    public function dbUsers(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] OptionalDatabaseTarget $input): Response
    {
        $name = $input->name;
        $dbName = $input->dbName ?? '';
        if (!$this->access->serverOrDatabase($name, $dbName) instanceof SqlClient) {
            throw $this->createNotFoundException('Server o database non trovato.');
        }

        return $this->render('schema/db_users.html.twig', [
            'name' => $name,
            'db_name' => $dbName,
        ]);
    }

    #[Route(path: '/db-users-get-data', name: 'app_schema_db_users_get_data', methods: ['GET'])]
    public function dbUsersGetData(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] OptionalDatabaseTarget $input,
        #[CurrentUser] AppUser $user): JsonResponse
    {
        $name = $input->name;
        $dbName = $input->dbName ?? '';

        $sqlClient = $this->access->serverOrDatabase($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['data' => []]);
        }

        $users = $this->visibility->accounts($user, $sqlClient, $this->repositories->accounts($sqlClient), $dbName);

        $data = array_map(static fn (MysqlUser $u): array => [
            'user' => $u->user,
            'host' => $u->host,
            'account_locked' => $u->accountLocked,
            'has_db_grant' => $u->hasDbGrant,
        ], $users);

        return $this->json(['data' => $data]);
    }

    #[Route(path: '/db-user-drop', name: 'app_schema_db_user_drop', methods: ['POST'])]
    #[Audited(action: AuditActionEnum::MYSQL_USER_DROP)]
    public function dbUserDrop(
        #[MapRequestPayload] AccountTarget $input,
        #[CurrentUser] AppUser $user): JsonResponse
    {
        $name = $input->name;
        $username = $input->username;
        $userHost = $input->userHost;

        $sqlClient = $this->access->server($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $accounts = $this->repositories->accounts($sqlClient);
        if (!$this->visibility->canManageAccount($user, $sqlClient, $accounts, $username, $userHost)) {
            return $this->json(['is_valid' => false, 'message' => 'Utente MySQL non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $ok = $this->accountManager->drop($accounts, $username, $userHost);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Utente eliminato.' : "Errore durante l'eliminazione.",
        ]);
    }

    #[Route(path: '/db-user-change-password', name: 'app_schema_db_user_change_password', methods: ['POST'])]
    #[Audited(action: AuditActionEnum::MYSQL_USER_PASSWORD)]
    public function dbUserChangePassword(
        #[MapRequestPayload] AccountPassword $input,
        #[CurrentUser] AppUser $user): JsonResponse
    {
        $name = $input->name;
        $username = $input->username;
        $userHost = $input->userHost;
        $newPassword = $input->password;

        $sqlClient = $this->access->server($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $accounts = $this->repositories->accounts($sqlClient);
        if (!$this->visibility->canManageAccount($user, $sqlClient, $accounts, $username, $userHost)) {
            return $this->json(['is_valid' => false, 'message' => 'Utente MySQL non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $ok = $accounts->changeUserPassword($username, $newPassword, $userHost);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Password aggiornata.' : "Errore durante l'aggiornamento della password.",
        ]);
    }

    #[Route(path: '/db-user-create', name: 'app_schema_db_user_create', methods: ['POST'])]
    #[Audited(action: AuditActionEnum::MYSQL_USER_CREATE)]
    public function dbUserCreate(
        #[MapRequestPayload] NewAccount $input,
        DatabaseProvisioner $provisioner): JsonResponse
    {
        $name = $input->name;
        $dbName = $input->dbName;
        $username = $input->username;
        $password = $input->password;
        $userHost = $input->userHost;
        // Privileges already validated against the whitelist by NewAccount.
        $privileges = $input->grantClause();

        $sqlClient = $this->access->database($name, $dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $provisioner->createUserWithGrant($this->repositories->accounts($sqlClient), $dbName, $username, $password, $userHost, $privileges);
        } catch (ProvisioningConflictException $conflict) {
            return $this->json(['is_valid' => false, 'message' => $conflict->getMessage()], Response::HTTP_CONFLICT);
        } catch (ProvisioningException $provisioningException) {
            return $this->json(['is_valid' => false, 'message' => $provisioningException->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['is_valid' => true, 'message' => 'Utente creato.']);
    }

    #[Route(path: '/db-user-grants-data', name: 'app_schema_db_user_grants_data', methods: ['GET'])]
    public function dbUserGrantsData(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] AccountTarget $input,
        #[CurrentUser] AppUser $user,
    ): JsonResponse {
        $name = $input->name;
        $username = $input->username;
        $userHost = $input->userHost;

        $sqlClient = $this->access->server($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'databases' => $this->visibility->databasesWithStats($user, $sqlClient, $this->repositories->schema($sqlClient)),
            'grants' => $this->visibility->grants($user, $sqlClient, $this->repositories->accounts($sqlClient), $username, $userHost),
        ]);
    }

    #[Route(path: '/db-user-grant-save', name: 'app_schema_db_user_grant_save', methods: ['POST'])]
    #[Audited(action: AuditActionEnum::MYSQL_USER_GRANTS)]
    public function dbUserGrantSave(
        #[MapRequestPayload] GrantChanges $input,
        #[CurrentUser] AppUser $user): JsonResponse
    {
        $name = $input->name;
        $username = $input->username;
        $userHost = $input->userHost;
        // JSON body mapped to typed objects: list<GrantInput> and list<string>, every privilege list already
        // checked against the whitelist and every database name validated (nothing runs if one entry is invalid).
        $grants = $input->grants;
        $revokedDbs = $input->revokedDbs;

        $sqlClient = $this->access->server($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $accounts = $this->repositories->accounts($sqlClient);
        $touchedDbs = [...array_map(static fn (GrantInput $g): string => $g->db, $grants), ...$revokedDbs];
        if (!$this->visibility->canEditGrants($user, $sqlClient, $accounts, $username, $userHost, $touchedDbs)) {
            return $this->json(['is_valid' => false, 'message' => 'Utente MySQL o database non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $this->accountManager->replaceGrants(
            $accounts, $username, $userHost,
            array_map(static fn (GrantInput $g): array => [$g->db, $g->privileges], $grants),
            $revokedDbs,
        );

        return $this->json(['is_valid' => true, 'message' => 'Permessi aggiornati.']);
    }
}
