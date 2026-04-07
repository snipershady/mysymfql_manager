<?php

namespace App\Controller;

use App\Entity\AppUser;
use App\Repository\SqlClientRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DefaultController extends AbstractController
{
    #[Route('/', name: 'app_default')]
    public function index(SqlClientRepository $sqlClientRepository): Response
    {
        $user = $this->getUser();
        $sqlClients = $user instanceof AppUser ? $sqlClientRepository->findAllOwned($user) : [];

        return $this->render('default/index.html.twig', [
            'sql_clients' => $sqlClients,
        ]);
    }
}
