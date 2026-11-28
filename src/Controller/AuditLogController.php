<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Input\AuditLogFilter;
use App\Enum\AuditActionEnum;
use App\Enum\AuditOutcomeEnum;
use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Audit log page: entries most recent first, 50 per page, filtered by user, server, action, outcome and period.
 *
 * Panel role: ROLE_ADMIN (also enforced by access_control on /admin). No MySQL privilege: it only reads the
 * application catalog. Invalid filters → 422 error page.
 *
 * @see \App\Tests\Functional\AuditLogTest
 */
#[Route(path: '/admin/audit-log')]
#[IsGranted(attribute: 'ROLE_ADMIN')]
final class AuditLogController extends AbstractController
{
    #[Route(name: 'app_audit_log_index', methods: ['GET'])]
    public function index(
        AuditLogRepository $repository,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] AuditLogFilter $filter = new AuditLogFilter(),
    ): Response {
        $entries = $repository->search($filter);
        $total = \count($entries);

        return $this->render('audit_log/index.html.twig', [
            'entries' => $entries,
            'total' => $total,
            'filter' => $filter,
            'pages' => max(1, (int) ceil($total / AuditLogRepository::PAGE_SIZE)),
            'actions' => AuditActionEnum::cases(),
            'outcomes' => AuditOutcomeEnum::cases(),
            'filter_values' => $repository->filterValues(),
            'retention_days' => AuditLogRepository::MAX_RETENTION_DAYS,
        ]);
    }
}
