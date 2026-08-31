<?php

namespace App\Controller;

use AltchaOrg\Altcha\Altcha;
use App\Component\DateTimeIt;
use App\Entity\AppUser;
use App\Enum\RoleEnum;
use App\Form\ChangePasswordType;
use App\Form\PasswordRecoveryType;
use App\Form\RegistrationFormType;
use App\Repository\AppUserRepository;
use App\Service\EmailHubService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

class RegistrationController extends AbstractController
{
    #[Route(path: '/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, EffectivePrimitiveTypeIdentifierService $epti): Response
    {
        $user = new AppUser();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $altchaPayload = $epti->getStringValueFromPost(needle: 'altcha', trim: true);

            if ('' === $altchaPayload) {
                $this->addFlash('error', 'Please complete the CAPTCHA verification.');

                return $this->redirectToRoute('app_register');
            }

            $hmacKey = $epti->getStringValueFromServer(needle: 'ALTCHAKEY', trim: true, forceString: true, sanitizeHtml: true);
            $altcha = new Altcha($hmacKey);

            if (!$altcha->verifySolution($altchaPayload, checkExpires: true)) {
                $this->addFlash('error', 'CAPTCHA verification invalid or expired. Please try again.');

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
                $this->addFlash('error', 'The entered password does not match the current one');

                return $this->redirectToRoute('app_change_password');
            }

            // Hash and set the new password
            $hashedPassword = $userPasswordHasher->hashPassword($user, $newPlainPassword);
            $user->setPassword($hashedPassword);

            // Save the user
            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'Password updated successfully');

            return $this->redirectToRoute('app_default');
        }

        return $this->render('registration/change_password.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route(path: '/reset-password', name: 'app_reset_password')]
    public function resetPassword(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        AppUserRepository $userRepo,
        EmailHubService $emailHubService,
        EffectivePrimitiveTypeIdentifierService $epti,
    ): Response {
        $form = $this->createForm(PasswordRecoveryType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $epti->getStringValueFromArray(needle: 'email', array: $request->request->all('password_recovery'), trim: true, forceString: true, sanitizeHtml: true);
            $user = $userRepo->findOneBy(['email' => $email]);
            $prefix = $epti->getStringValueFromEnv(needle: 'SW_NAME', trim: true, forceString: true, sanitizeHtml: true);
            $secret = $epti->getStringValueFromEnv(needle: 'APP_SECRET', trim: true, forceString: true, sanitizeHtml: true);

            if (!$user instanceof AppUser) {
                $this->addFlash('success', 'A new password has been sent to the provided email address');
            } else {
                // Hash and set the new password
                $newPlainPassword = md5(microtime(as_float: true) . $secret);
                $hashedPassword = $userPasswordHasher->hashPassword($user, $newPlainPassword);
                $user->setPassword($hashedPassword);

                // Save the user
                $entityManager->persist($user);
                $entityManager->flush();

                // Send email to the user with the new password
                $now = new DateTimeIt();
                $nowString = $now->format('Y-m-d H:i:s');
                $msg = '<p>' . $prefix . ' - Your new password is ' . $newPlainPassword . '</p><p>Date: ' . $nowString . '</p>';
                $subject = $prefix . ' - Password reset request ' . $nowString;

                $addressTo = new Address($email, $user->getUserIdentifier());
                $response = $emailHubService->emailResetPassword($addressTo, $msg, $subject);

                $this->addFlash('success', 'A new password has been sent to the provided email address');
            }

            return $this->redirectToRoute('app_default');
        }

        return $this->render('registration/reset_password.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
