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
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager): Response
    {
        $user = new AppUser();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $epti = new EffectivePrimitiveTypeIdentifierService();
            $altchaPayload = $request->request->get('altcha', '');

            if ('' === $altchaPayload) {
                $this->addFlash('error', 'Completa la verifica CAPTCHA.');

                return $this->redirectToRoute('app_register');
            }

            $hmacKey = $epti->getTypedValueFromServer(needle: 'ALTCHAKEY', trim: true, forceString: true, sanitizeHtml: true);
            $altcha = new Altcha($hmacKey);

            if (!$altcha->verifySolution($altchaPayload, checkExpires: true)) {
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

    #[Route('/change-password', name: 'app_change_password')]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        #[CurrentUser] ?UserInterface $user,
    ): Response {
        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $epti = new EffectivePrimitiveTypeIdentifierService();
            $oldPlainPassword = $epti->getTypedValue($form->get('oldPlainPassword')->getData(), true, true);
            $newPlainPassword = $epti->getTypedValue($form->get('newPlainPassword')->getData(), true, true);

            // Verifica se la vecchia password è corretta
            if (!$userPasswordHasher->isPasswordValid($user, $oldPlainPassword)) {
                $this->addFlash('error', 'La password inserita non corrisponde a quella in uso');

                return $this->redirectToRoute('app_change_password');
            }

            // Hash e imposta la nuova password
            $hashedPassword = $userPasswordHasher->hashPassword($user, $newPlainPassword);
            $user->setPassword($hashedPassword);

            // Salva l'utente
            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'Password aggiornata con successo');

            return $this->redirectToRoute('app_default');
        }

        return $this->render('registration/change_password.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/reset-password', name: 'app_reset_password')]
    public function resetPassword(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        AppUserRepository $userRepo,
        EmailHubService $emailHubService,
    ): Response {
        $form = $this->createForm(PasswordRecoveryType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $epti = new EffectivePrimitiveTypeIdentifierService();
            $email = $epti->getTypedValue(data: $request->request->all()['password_recovery']['email'], trim: true, forceString: true, sanitizeHtml: true);
            $user = $userRepo->findOneBy(['email' => $email]);
            $prefix = $epti->getTypedValueFromEnv(needle: 'SW_NAME', trim: true, forceString: true, sanitizeHtml: true);
            $secret = $epti->getTypedValueFromEnv(needle: 'APP_SECRET', trim: true, forceString: true, sanitizeHtml: true);

            if (!$user instanceof AppUser) {
                $this->addFlash('success', "E' stata inviata la nuova password alla mail inserita");
            } else {
                // Hash e imposta la nuova password
                $newPlainPassword = md5(microtime(true).$secret);
                $hashedPassword = $userPasswordHasher->hashPassword($user, $newPlainPassword);
                $user->setPassword($hashedPassword);

                // Salva l'utente
                $entityManager->persist($user);
                $entityManager->flush();

                // Invia email all'utente con la nuova password
                $now = new DateTimeIt();
                $nowString = $now->format('Y-m-d H:i:s');
                $msg = '<p>'.$prefix.' - La tua nuova password è '.$newPlainPassword.'</p><p>Data: '.$nowString.'</p>';
                $subject = $prefix.' - Richiesta reset password '.$nowString;

                $addressTo = new Address($email, $user->getUserIdentifier());
                $response = $emailHubService->emailResetPassword($addressTo, $msg, $subject);

                $this->addFlash('success', "E' stata inviata la nuova password alla mail inserita");
            }

            return $this->redirectToRoute('app_default');
        }

        return $this->render('registration/reset_password.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
