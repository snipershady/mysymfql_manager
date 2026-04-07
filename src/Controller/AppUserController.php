<?php

namespace App\Controller;

use App\Entity\AppUser;
use App\Form\AppUserAdminType;
use App\Form\AppUserType;
use App\Repository\AppUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/appuser')]
final class AppUserController extends AbstractController
{
    #[Route(name: 'app_user_index', methods: ['GET'])]
    public function index(AppUserRepository $appUserRepository): Response
    {
        return $this->render('app_user/index.html.twig', [
            'app_users' => $appUserRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $appUser = new AppUser();
        $form = $this->createForm(AppUserType::class, $appUser);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($appUser);
            $entityManager->flush();

            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('app_user/new.html.twig', [
            'app_user' => $appUser,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_user_show', methods: ['GET'])]
    public function show(AppUser $appUser): Response
    {
        return $this->render('app_user/show.html.twig', [
            'app_user' => $appUser,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_user_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        AppUser $appUser,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher): Response
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(AppUserAdminType::class, $appUser);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if (null !== $plainPassword && '' !== $plainPassword) {
                $appUser->setPassword($passwordHasher->hashPassword($appUser, $plainPassword));
            }

            $entityManager->flush();

            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('app_user/edit.html.twig', [
            'app_user' => $appUser,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_user_delete', methods: ['POST'])]
    public function delete(Request $request, AppUser $appUser, EntityManagerInterface $entityManager): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        if ($this->isCsrfTokenValid('delete'.$appUser->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($appUser);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
    }
}
