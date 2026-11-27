<?php

declare(strict_types=1);

namespace App\Controller\Schema;

use App\Dto\Input\AccountPassword;
use App\Dto\Input\AccountTarget;
use App\Dto\Input\GrantChanges;
use App\Dto\Input\GrantInput;
use App\Dto\Input\NewAccount;
use App\Dto\Input\OptionalDatabaseTarget;
use App\Dto\MysqlUser;
use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Exception\ProvisioningConflictException;
use App\Exception\ProvisioningException;
use App\Repository\DatabaseOwnerRepository;
use App\RepositoryPDO\DatabaseSchemaRepository;
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

        $repo = new DatabaseSchemaRepository($sqlClient);
        $users = $repo->listUsers($dbName);
        if (!$this->access->isAdmin()) {
            // Database view: accounts granted on that database; server view: accounts the user may manage.
            $users = array_values(array_filter(
                $users,
                fn (MysqlUser $u): bool => '' !== $dbName
                    ? $u->hasDbGrant
                    : $this->visibility->canManageAccount($user, $sqlClient, $repo, $u->user, $u->host)
            ));
        }

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

        $repo = new DatabaseSchemaRepository($sqlClient);
        if (!$this->visibility->canManageAccount($user, $sqlClient, $repo, $username, $userHost)) {
            return $this->json(['is_valid' => false, 'message' => 'Utente MySQL non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $ok = $repo->dropUser($username, $userHost);
        if ($ok) {
            $repo->flushPrivileges();
        }

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Utente eliminato.' : "Errore durante l'eliminazione.",
        ]);
    }

    #[Route(path: '/db-user-change-password', name: 'app_schema_db_user_change_password', methods: ['POST'])]
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

        $repo = new DatabaseSchemaRepository($sqlClient);
        if (!$this->visibility->canManageAccount($user, $sqlClient, $repo, $username, $userHost)) {
            return $this->json(['is_valid' => false, 'message' => 'Utente MySQL non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $ok = $repo->changeUserPassword($username, $newPassword, $userHost);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? 'Password aggiornata.' : "Errore durante l'aggiornamento della password.",
        ]);
    }

    #[Route(path: '/db-user-create', name: 'app_schema_db_user_create', methods: ['POST'])]
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
            $provisioner->createUserWithGrant(new DatabaseSchemaRepository($sqlClient), $dbName, $username, $password, $userHost, $privileges);
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
        DatabaseOwnerRepository $databaseOwnerRepository
    ): JsonResponse {
        $name = $input->name;
        $username = $input->username;
        $userHost = $input->userHost;

        $sqlClient = $this->access->server($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $databases = $this->visibility->databasesWithStats($user, $sqlClient);

        $grants = $repo->getUserGrantsByDb($username, $userHost);
        if (!$this->access->isAdmin()) {
            $ownedDbs = $databaseOwnerRepository->findDbNamesOwnedOn($user, $sqlClient);
            $grants = array_values(array_filter($grants, static fn (array $g): bool => \in_array($g['db'], $ownedDbs, strict: true)));
        }

        return $this->json([
            'databases' => $databases,
            'grants' => $grants,
        ]);
    }

    #[Route(path: '/db-user-grant-save', name: 'app_schema_db_user_grant_save', methods: ['POST'])]
    public function dbUserGrantSave(
        #[MapRequestPayload] GrantChanges $input,
        #[CurrentUser] AppUser $user,
        DatabaseOwnerRepository $databaseOwnerRepository): JsonResponse
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

        $repo = new DatabaseSchemaRepository($sqlClient);

        if (!$this->access->isAdmin()) {
            $ownedDbs = $databaseOwnerRepository->findDbNamesOwnedOn($user, $sqlClient);
            $touchedDbs = [...array_map(static fn (GrantInput $g): string => $g->db, $grants), ...$revokedDbs];
            if (!array_all($touchedDbs, static fn (string $db): bool => \in_array($db, $ownedDbs, strict: true))
                || !$this->visibility->canManageAccount($user, $sqlClient, $repo, $username, $userHost)) {
                return $this->json(['is_valid' => false, 'message' => 'Utente MySQL o database non trovato.'], Response::HTTP_NOT_FOUND);
            }
        }

        foreach ($revokedDbs as $dbName) {
            $repo->revokeAllPrivilegesOnDb($dbName, $username, $userHost);
        }

        // The grants of a selected database are replaced, not added to (as the modal states): revoke the
        // existing ones first. Only where some exist: REVOKE fails on a database without grants.
        $alreadyGranted = array_column($repo->getUserGrantsByDb($username, $userHost), 'db');
        foreach ($grants as $grant) {
            if (\in_array($grant->db, $alreadyGranted, strict: true)) {
                $repo->revokeAllPrivilegesOnDb($grant->db, $username, $userHost);
            }
            $repo->grantPrivileges(dbName: $grant->db, username: $username, privileges: $grant->privileges, host: $userHost);
        }

        $repo->flushPrivileges();

        return $this->json(['is_valid' => true, 'message' => 'Permessi aggiornati.']);
    }
}
