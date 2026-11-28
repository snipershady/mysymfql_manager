<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Audited;
use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Enum\AuditActionEnum;
use App\Form\SqlClientType;
use App\Repository\SqlClientRepository;
use App\Security\Voter\SqlClientVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/sqlclient')]
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

    #[Route(path: '/new', name: 'app_sql_client_new', methods: ['GET', 'POST'])]
    #[IsGranted(attribute: 'ROLE_ADMIN')]
    #[Audited(action: AuditActionEnum::SQL_CLIENT_CREATE)]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $sqlClient = new SqlClient();
        $form = $this->createForm(SqlClientType::class, $sqlClient);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyTypedPassword($form, $sqlClient);
            $entityManager->persist($sqlClient);
            $entityManager->flush();

            return $this->redirectToRoute('app_sql_client_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('sql_client/new.html.twig', [
            'sql_client' => $sqlClient,
            'form' => $form,
        ]);
    }

    #[Route(path: '/{id}', name: 'app_sql_client_show', methods: ['GET'])]
    #[IsGranted(attribute: SqlClientVoter::VIEW, subject: 'sqlClient')]
    public function show(SqlClient $sqlClient): Response
    {
        return $this->render('sql_client/show.html.twig', [
            'sql_client' => $sqlClient,
        ]);
    }

    #[Route(path: '/{id}/edit', name: 'app_sql_client_edit', methods: ['GET', 'POST'])]
    #[IsGranted(attribute: SqlClientVoter::MANAGE, subject: 'sqlClient')]
    #[Audited(action: AuditActionEnum::SQL_CLIENT_UPDATE)]
    public function edit(Request $request, SqlClient $sqlClient, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(SqlClientType::class, $sqlClient, ['require_password' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyTypedPassword($form, $sqlClient);
            $entityManager->flush();

            return $this->redirectToRoute('app_sql_client_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('sql_client/edit.html.twig', [
            'sql_client' => $sqlClient,
            'form' => $form,
        ]);
    }

    #[Route(path: '/{id}', name: 'app_sql_client_delete', methods: ['POST'])]
    #[IsGranted(attribute: SqlClientVoter::MANAGE, subject: 'sqlClient')]
    #[IsCsrfTokenValid(id: new Expression('"delete" ~ args["sqlClient"].getId()'))]
    #[Audited(action: AuditActionEnum::SQL_CLIENT_DELETE)]
    public function delete(SqlClient $sqlClient, EntityManagerInterface $entityManager): RedirectResponse
    {
        $entityManager->remove($sqlClient);
        $entityManager->flush();

        return $this->redirectToRoute('app_sql_client_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * The password field is not mapped: set it only when something was typed (edit keeps the stored one).
     *
     * @param FormInterface<SqlClient> $form
     */
    private function applyTypedPassword(FormInterface $form, SqlClient $sqlClient): void
    {
        $password = $form->get('password')->getData();
        if (\is_string($password) && '' !== $password) {
            $sqlClient->setPassword($password);
        }
    }
}
