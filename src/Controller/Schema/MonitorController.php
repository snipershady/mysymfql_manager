<?php

declare(strict_types=1);

namespace App\Controller\Schema;

use App\Dto\Input\KillProcess;
use App\Dto\Input\ServerTarget;
use App\Dto\ProcessList;
use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Repository\SqlClientRepository;
use App\RepositoryPDO\ManagedServerRepositoryFactory;
use App\Security\SchemaVisibility;
use App\Security\SqlClientAccessResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Server monitoring: dashboard statistics, engine status, process list, InnoDB transactions, kill.
 *
 * Panel access: server/database ownership through SqlClientAccessResolver (admins see everything);
 * input mapped to the DTOs of App\Dto\Input; routes under /schema (names unchanged).
 */
#[Route(path: '/schema')]
final class MonitorController extends AbstractController
{
    public function __construct(
        private readonly SqlClientAccessResolver $access,
        private readonly SchemaVisibility $visibility,
        private readonly ManagedServerRepositoryFactory $repositories,
    ) {
    }

    #[Route(path: '/dashboard-stats', name: 'app_schema_dashboard_stats', methods: ['GET'])]
    public function dashboardStats(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ServerTarget $input,
        #[CurrentUser] AppUser $user,
        SqlClientRepository $sqlClientRepository
    ): JsonResponse {
        $name = $input->name;

        $ownedClients = $sqlClientRepository->findAllOwned($user);
        $sqlClient = array_find($ownedClients, static fn ($ownedClient): bool => $ownedClient->getName() === $name);

        if (null === $sqlClient) {
            return $this->json(['error' => 'Server non trovato o accesso negato.'], Response::HTTP_NOT_FOUND);
        }
        $monitor = $this->repositories->monitor($sqlClient);

        $databases = $this->visibility->databasesWithStats($user, $sqlClient, $this->repositories->schema($sqlClient));

        return $this->json([
            'db_count' => \count($databases),
            'active_connections' => $monitor->getActiveConnections(),
            'running_processes' => $monitor->getRunningProcesses(),
            'blocked_processes' => $monitor->getBlockedProcesses(),
            'databases' => $databases,
        ]);
    }

    #[Route(path: '/show-engine-status-get-data', name: 'app_show_engine_status_get_data', methods: ['GET'])]
    #[IsGranted(attribute: 'ROLE_ADMIN')]
    public function showEngineInnodbStatusGetData(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ServerTarget $input): JsonResponse
    {
        $name = $input->name;
        $sqlClient = $this->access->server($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server non trovato o accesso negato.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $this->repositories->monitor($sqlClient)->showEngineInnodbStatus()]);
    }

    #[Route(path: '/engine-status', name: 'app_schema_engine_status', methods: ['GET'])]
    #[IsGranted(attribute: 'ROLE_ADMIN')]
    public function engineStatus(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ServerTarget $input): Response
    {
        $name = $input->name;
        $sqlClient = $this->access->server($name);
        if (!$sqlClient instanceof SqlClient) {
            throw $this->createNotFoundException('Server non trovato.');
        }

        return $this->render('schema/engine_status.html.twig', [
            'name' => $name,
            'status' => $this->repositories->monitor($sqlClient)->showEngineInnodbStatus(),
        ]);
    }

    #[Route(path: '/show-processlist-get-data', name: 'app_show_processlist_get_data', methods: ['GET'])]
    public function showProcessListGetData(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ServerTarget $input,
        #[CurrentUser] AppUser $user): JsonResponse
    {
        $name = $input->name;

        $sqlClient = $this->access->server($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server non trovato o accesso negato.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $this->visibility->processes($user, $sqlClient, $this->repositories->monitor($sqlClient))]);
    }

    #[Route(path: '/process-list', name: 'app_schema_process_list', methods: ['GET'])]
    public function processListPage(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ServerTarget $input): Response
    {
        $name = $input->name;
        if (!$this->access->server($name) instanceof SqlClient) {
            throw $this->createNotFoundException('Server non trovato.');
        }

        return $this->render('schema/process_list.html.twig', [
            'name' => $name,
        ]);
    }

    #[Route(path: '/kill-process', name: 'app_schema_kill_process', methods: ['POST'])]
    public function killProcess(
        #[MapRequestPayload] KillProcess $input,
        #[CurrentUser] AppUser $user): JsonResponse
    {
        $name = $input->name;
        $pid = $input->pid;
        $sqlClient = $this->access->server($name);

        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $monitor = $this->repositories->monitor($sqlClient);
        $visible = $this->visibility->processes($user, $sqlClient, $monitor);
        if (!array_any($visible, static fn (ProcessList $process): bool => $process->id === $pid)) {
            return $this->json(['is_valid' => false, 'message' => 'Processo non trovato.'], Response::HTTP_NOT_FOUND);
        }

        $ok = $monitor->killProcessById($pid);

        return $this->json([
            'is_valid' => $ok,
            'message' => $ok ? \sprintf('Processo %d terminato.', $pid) : \sprintf('Impossibile terminare il processo %d.', $pid),
        ]);
    }

    /**
     * Open InnoDB transactions (information_schema.INNODB_TRX).
     *
     * Panel: admin or server owner; non-admins only see transactions whose thread works on one of
     * their databases (same rule as the process list). MySQL privilege: PROCESS. Read-only.
     */
    #[Route(path: '/transactions-get-data', name: 'app_schema_transactions_get_data', methods: ['GET'])]
    public function transactionsGetData(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ServerTarget $input,
        #[CurrentUser] AppUser $user): JsonResponse
    {
        $name = $input->name;

        $sqlClient = $this->access->server($name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['error' => 'Server non trovato o accesso negato.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => $this->visibility->transactions($user, $sqlClient, $this->repositories->monitor($sqlClient))]);
    }

    #[Route(path: '/transactions', name: 'app_schema_transactions', methods: ['GET'])]
    public function transactionsPage(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ServerTarget $input): Response
    {
        $name = $input->name;
        if (!$this->access->server($name) instanceof SqlClient) {
            throw $this->createNotFoundException('Server non trovato.');
        }

        return $this->render('schema/transactions.html.twig', [
            'name' => $name,
        ]);
    }
}
