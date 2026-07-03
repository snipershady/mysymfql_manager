<?php

namespace App\Controller;

use App\Entity\DatabaseOwner;
use App\Form\DatabaseOwnerType;
use App\Repository\DatabaseOwnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/database-owner')]
#[IsGranted('ROLE_ADMIN')]
final class DatabaseOwnerController extends AbstractController
{
    #[Route(name: 'app_database_owner_index', methods: ['GET'])]
    public function index(DatabaseOwnerRepository $databaseOwnerRepository): Response
    {
        return $this->render('database_owner/index.html.twig', [
            'database_owners' => $databaseOwnerRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_database_owner_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $databaseOwner = new DatabaseOwner();
        $form = $this->createForm(DatabaseOwnerType::class, $databaseOwner);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($databaseOwner);
            $entityManager->flush();

            $this->addFlash('success', 'Assignment created successfully.');

            return $this->redirectToRoute('app_database_owner_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('database_owner/new.html.twig', [
            'database_owner' => $databaseOwner,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_database_owner_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, DatabaseOwner $databaseOwner, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(DatabaseOwnerType::class, $databaseOwner);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Assignment updated successfully.');

            return $this->redirectToRoute('app_database_owner_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('database_owner/edit.html.twig', [
            'database_owner' => $databaseOwner,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_database_owner_delete', methods: ['POST'])]
    public function delete(Request $request, DatabaseOwner $databaseOwner, EntityManagerInterface $entityManager): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        if ($this->isCsrfTokenValid('delete' . $databaseOwner->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($databaseOwner);
            $entityManager->flush();

            $this->addFlash('success', 'Assignment deleted.');
        }

        return $this->redirectToRoute('app_database_owner_index', [], Response::HTTP_SEE_OTHER);
    }
}
