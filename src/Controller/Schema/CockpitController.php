<?php

declare(strict_types=1);

namespace App\Controller\Schema;

use App\Dto\Input\DatabaseTarget;
use App\Dto\Input\ServerTarget;
use App\Entity\SqlClient;
use App\Security\SqlClientAccessResolver;
use App\Service\Cockpit\CockpitService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Live cockpits (see CockpitService): page + JSON data refreshed by the page.
 *
 * - Server: ROLE_ADMIN (server-wide data shows the activity of every user and database) + the server.
 * - Schema: admin or owner of the database (SqlClientAccessResolver::database()).
 * Unknown or denied server/database → 404, no query on the server. MySQL privileges and degraded sections: see
 * CockpitService. Read only.
 *
 * @see \App\Tests\Functional\CockpitTest
 */
#[Route(path: '/schema')]
final class CockpitController extends AbstractController
{
    public function __construct(
        private readonly SqlClientAccessResolver $access,
        private readonly CockpitService $cockpit,
    ) {
    }

    #[Route(path: '/server-cockpit', name: 'app_schema_server_cockpit', methods: ['GET'])]
    #[IsGranted(attribute: 'ROLE_ADMIN')]
    public function serverCockpit(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ServerTarget $input): Response
    {
        if (!$this->access->server($input->name) instanceof SqlClient) {
            throw $this->createNotFoundException('Server non trovato.');
        }

        return $this->render('schema/server_cockpit.html.twig', ['name' => $input->name]);
    }

    #[Route(path: '/server-cockpit-data', name: 'app_schema_server_cockpit_data', methods: ['GET'])]
    #[IsGranted(attribute: 'ROLE_ADMIN')]
    public function serverCockpitData(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ServerTarget $input): JsonResponse
    {
        $sqlClient = $this->access->server($input->name);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Server non trovato.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['is_valid' => true, ...$this->cockpit->server($sqlClient)]);
    }

    #[Route(path: '/schema-cockpit', name: 'app_schema_schema_cockpit', methods: ['GET'])]
    public function schemaCockpit(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] DatabaseTarget $input): Response
    {
        if (!$this->access->database($input->name, $input->dbName) instanceof SqlClient) {
            throw $this->createNotFoundException('Database non trovato.');
        }

        return $this->render('schema/schema_cockpit.html.twig', ['name' => $input->name, 'db_name' => $input->dbName]);
    }

    #[Route(path: '/schema-cockpit-data', name: 'app_schema_schema_cockpit_data', methods: ['GET'])]
    public function schemaCockpitData(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] DatabaseTarget $input): JsonResponse
    {
        $sqlClient = $this->access->database($input->name, $input->dbName);
        if (!$sqlClient instanceof SqlClient) {
            return $this->json(['is_valid' => false, 'message' => 'Database non trovato.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['is_valid' => true, ...$this->cockpit->schema($sqlClient, $input->dbName)]);
    }
}
