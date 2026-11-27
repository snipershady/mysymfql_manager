<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppUser;
use App\Repository\SqlClientRepository;
use App\Service\BackupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DefaultController extends AbstractController
{
    #[Route(path: '/', name: 'app_default')]
    public function index(SqlClientRepository $sqlClientRepository, BackupService $backups): Response
    {
        $user = $this->getUser();
        $sqlClients = $user instanceof AppUser ? $sqlClientRepository->findAllOwned($user) : [];

        return $this->render('default/index.html.twig', [
            'sql_clients' => $sqlClients,
            'backup_queue' => $user instanceof AppUser ? $backups->recentQueue($user) : [],
        ]);
    }
}
