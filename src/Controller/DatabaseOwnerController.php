<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Audited;
use App\Entity\DatabaseOwner;
use App\Enum\AuditActionEnum;
use App\Form\DatabaseOwnerType;
use App\Repository\DatabaseOwnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/database-owner')]
#[IsGranted(attribute: 'ROLE_ADMIN')]
final class DatabaseOwnerController extends AbstractController
{
    #[Route(name: 'app_database_owner_index', methods: ['GET'])]
    public function index(DatabaseOwnerRepository $databaseOwnerRepository): Response
    {
        return $this->render('database_owner/index.html.twig', [
            'database_owners' => $databaseOwnerRepository->findAll(),
        ]);
    }

    #[Route(path: '/new', name: 'app_database_owner_new', methods: ['GET', 'POST'])]
    #[Audited(action: AuditActionEnum::DATABASE_OWNER_CREATE)]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $databaseOwner = new DatabaseOwner();
        $form = $this->createForm(DatabaseOwnerType::class, $databaseOwner);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($databaseOwner);
            $entityManager->flush();

            $this->addFlash('success', 'Assegnazione creata.');

            return $this->redirectToRoute('app_database_owner_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('database_owner/new.html.twig', [
            'database_owner' => $databaseOwner,
            'form' => $form,
        ]);
    }

    #[Route(path: '/{id}/edit', name: 'app_database_owner_edit', methods: ['GET', 'POST'])]
    #[Audited(action: AuditActionEnum::DATABASE_OWNER_UPDATE)]
    public function edit(Request $request, DatabaseOwner $databaseOwner, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(DatabaseOwnerType::class, $databaseOwner);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Assegnazione aggiornata.');

            return $this->redirectToRoute('app_database_owner_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('database_owner/edit.html.twig', [
            'database_owner' => $databaseOwner,
            'form' => $form,
        ]);
    }

    #[Route(path: '/{id}', name: 'app_database_owner_delete', methods: ['POST'])]
    #[IsCsrfTokenValid(id: new Expression('"delete" ~ args["databaseOwner"].getId()'))]
    #[Audited(action: AuditActionEnum::DATABASE_OWNER_DELETE)]
    public function delete(DatabaseOwner $databaseOwner, EntityManagerInterface $entityManager): RedirectResponse
    {
        $entityManager->remove($databaseOwner);
        $entityManager->flush();

        $this->addFlash('success', 'Assegnazione eliminata.');

        return $this->redirectToRoute('app_database_owner_index', [], Response::HTTP_SEE_OTHER);
    }
}
