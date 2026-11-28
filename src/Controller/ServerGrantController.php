<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ServerGrantAudit;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Grant check of the registered servers (see ServerGrantAudit): one card per server with the account, its grants,
 * the users and databases assigned, and the findings.
 *
 * Panel role: ROLE_ADMIN (also enforced by access_control on /admin). MySQL privileges: none (SHOW GRANTS of the
 * server account). Read only; an unreachable server shows its error, the others are still checked.
 *
 * @see \App\Tests\Functional\ServerGrantAuditTest
 */
#[Route(path: '/admin/server-grants')]
#[IsGranted(attribute: 'ROLE_ADMIN')]
final class ServerGrantController extends AbstractController
{
    #[Route(name: 'app_server_grants', methods: ['GET'])]
    public function index(ServerGrantAudit $audit): Response
    {
        return $this->render('server_grants/index.html.twig', [
            'reports' => $audit->checkAll(),
        ]);
    }
}
