<?php

namespace App\Controller;

use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Form\SqlClientType;
use App\Repository\SqlClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sqlclient')]
final class SqlClientController extends AbstractController
{
    #[Route(name: 'app_sql_client_index', methods: ['GET'])]
    public function index(SqlClientRepository $sqlClientRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('sql_client/index.html.twig', [
            'all_sql_client' => $sqlClientRepository->findAllOwned($user),
        ]);
    }

    #[Route('/new', name: 'app_sql_client_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $sqlClient = new SqlClient();
        $form = $this->createForm(SqlClientType::class, $sqlClient);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($sqlClient);
            $entityManager->flush();

            return $this->redirectToRoute('app_sql_client_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('sql_client/new.html.twig', [
            'sql_client' => $sqlClient,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_sql_client_show', methods: ['GET'])]
    public function show(SqlClient $sqlClient): Response
    {
        return $this->render('sql_client/show.html.twig', [
            'sql_client' => $sqlClient,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_sql_client_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, SqlClient $sqlClient, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(SqlClientType::class, $sqlClient);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_sql_client_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('sql_client/edit.html.twig', [
            'sql_client' => $sqlClient,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_sql_client_delete', methods: ['POST'])]
    public function delete(Request $request, SqlClient $sqlClient, EntityManagerInterface $entityManager): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        if ($this->isCsrfTokenValid('delete' . $sqlClient->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($sqlClient);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_sql_client_index', [], Response::HTTP_SEE_OTHER);
    }
}
