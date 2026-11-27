<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppUser;
use App\Enum\RoleEnum;
use App\Form\ChangePasswordType;
use App\Form\RegistrationFormType;
use App\Security\AltchaCaptcha;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

class RegistrationController extends AbstractController
{
    #[Route(path: '/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, AltchaCaptcha $captcha): Response
    {
        $user = new AppUser();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $altchaPayload = $request->request->all()['altcha'] ?? '';

            if ('' === $altchaPayload) {
                $this->addFlash('error', 'Completa la verifica CAPTCHA.');

                return $this->redirectToRoute('app_register');
            }

            if (!\is_string($altchaPayload) || !$captcha->verify($altchaPayload)) {
                $this->addFlash('error', 'Verifica CAPTCHA non valida o scaduta. Riprova.');

                return $this->redirectToRoute('app_register');
            }

            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            // encode the plain password
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
            $user->setRoles([RoleEnum::ROLE_DISABLED->value]);

            $entityManager->persist($user);
            $entityManager->flush();

            // do anything else you need here, like send an email

            return $this->redirectToRoute('app_default');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route(path: '/change-password', name: 'app_change_password')]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        EffectivePrimitiveTypeIdentifierService $epti,
        #[CurrentUser] AppUser $user,
    ): Response {
        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $oldPlainPassword = $epti->getStringValue($form->get('oldPlainPassword')->getData(), trim: true, forceString: true);
            $newPlainPassword = $epti->getStringValue($form->get('newPlainPassword')->getData(), trim: true, forceString: true);

            // Verify whether the old password is correct
            if (!$userPasswordHasher->isPasswordValid($user, $oldPlainPassword)) {
                $this->addFlash('error', 'La password attuale non è corretta.');

                return $this->redirectToRoute('app_change_password');
            }

            // Hash and set the new password
            $hashedPassword = $userPasswordHasher->hashPassword($user, $newPlainPassword);
            $user->setPassword($hashedPassword);

            // Save the user
            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'Password aggiornata.');

            return $this->redirectToRoute('app_default');
        }

        // The form itself (not createView()): Symfony then answers 422 when it is invalid, as Turbo requires.
        return $this->render('registration/change_password.html.twig', [
            'form' => $form,
        ]);
    }
}
