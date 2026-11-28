<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Audited;
use App\Entity\AppUser;
use App\Enum\AuditActionEnum;
use App\Form\AppUserAdminType;
use App\Form\AppUserType;
use App\Repository\AppUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

#[Route(path: '/admin/appuser')]
final class AppUserController extends AbstractController
{
    #[Route(name: 'app_user_index', methods: ['GET'])]
    public function index(AppUserRepository $appUserRepository): Response
    {
        return $this->render('app_user/index.html.twig', [
            'app_users' => $appUserRepository->findAll(),
        ]);
    }

    #[Route(path: '/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    #[Audited(action: AuditActionEnum::APP_USER_CREATE)]
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

    #[Route(path: '/{id}', name: 'app_user_show', methods: ['GET'])]
    public function show(AppUser $appUser): Response
    {
        return $this->render('app_user/show.html.twig', [
            'app_user' => $appUser,
        ]);
    }

    #[Route(path: '/{id}/edit', name: 'app_user_edit', methods: ['GET', 'POST'])]
    #[Audited(action: AuditActionEnum::APP_USER_UPDATE)]
    public function edit(
        Request $request,
        AppUser $appUser,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        EffectivePrimitiveTypeIdentifierService $epti): Response
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(AppUserAdminType::class, $appUser);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $epti->getStringValue($form->get('plainPassword')->getData(), trim: true, forceString: true);
            if ('' !== $plainPassword) {
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

    #[Route(path: '/{id}', name: 'app_user_delete', methods: ['POST'])]
    #[IsCsrfTokenValid(id: new Expression('"delete" ~ args["appUser"].getId()'))]
    #[Audited(action: AuditActionEnum::APP_USER_DELETE)]
    public function delete(AppUser $appUser, EntityManagerInterface $entityManager): RedirectResponse
    {
        $entityManager->remove($appUser);
        $entityManager->flush();

        return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
    }
}
