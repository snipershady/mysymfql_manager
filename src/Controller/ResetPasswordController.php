<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppUser;
use App\Form\PasswordRecoveryType;
use App\Form\ResetPasswordConfirmType;
use App\Repository\AppUserRepository;
use App\Security\PasswordReset\PasswordResetThrottle;
use App\Security\PasswordReset\PasswordResetTokenManager;
use App\Service\EmailHubService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

/**
 * Password reset by e-mailed, signed, expiring, single-use link.
 *
 * Panel role: public (anonymous). MySQL privileges: none (application catalog only).
 * Errors: the request step always answers with the same message (no account enumeration),
 * also when the account is unknown, the request is throttled or the e-mail cannot be sent
 * (logged); the password is changed only in the confirm step, after the token is verified.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
final class ResetPasswordController extends AbstractController
{
    private const string GENERIC_MESSAGE = "Se l'indirizzo appartiene a un account, abbiamo inviato un link per reimpostare la password. Scade tra 30 minuti.";

    #[Route(path: '/reset-password', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        AppUserRepository $appUserRepository,
        PasswordResetTokenManager $tokenManager,
        PasswordResetThrottle $throttle,
        EmailHubService $emailHubService,
        EffectivePrimitiveTypeIdentifierService $epti,
        LoggerInterface $logger,
    ): Response {
        $form = $this->createForm(PasswordRecoveryType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('registration/reset_password.html.twig', ['form' => $form]);
        }

        $email = $epti->getStringValueFromArray(needle: 'email', array: $request->request->all('password_recovery'), trim: true, forceString: true);
        $user = $throttle->isAllowed($request->getClientIp(), $email)
            ? $appUserRepository->findOneBy(['email' => $email])
            : null;

        if ($user instanceof AppUser) {
            $resetUrl = $this->generateUrl('app_reset_password_confirm', $tokenManager->issue($user), UrlGeneratorInterface::ABSOLUTE_URL);
            $prefix = $epti->getStringValueFromEnv(needle: 'SW_NAME', trim: true, forceString: true, sanitizeHtml: true);
            $safeUrl = htmlspecialchars($resetUrl);
            $msg = \sprintf(
                '<p>%s - Clicca sul link qui sotto per scegliere una nuova password. Scade tra %d minuti e si può usare una sola volta.</p>'
                . '<p><a href="%s">%s</a></p>'
                . "<p>Se non l'hai richiesto tu, ignora questa email: la password non è stata modificata.</p>",
                htmlspecialchars($prefix),
                intdiv(PasswordResetTokenManager::TTL_SECONDS, 60),
                $safeUrl,
                $safeUrl,
            );
            $result = $emailHubService->emailResetPassword(new Address($email, $user->getUserIdentifier()), $msg, $prefix . ' - Reimpostazione della password');
            if (!$result['is_valid']) {
                $logger->error('Password reset e-mail could not be sent.', ['user_id' => $user->getId(), 'error' => $result['msg']]);
            }
        }

        $this->addFlash('success', self::GENERIC_MESSAGE);

        return $this->redirectToRoute('app_login');
    }

    #[Route(path: '/reset-password/confirm', name: 'app_reset_password_confirm', methods: ['GET', 'POST'])]
    public function confirm(
        Request $request,
        PasswordResetTokenManager $tokenManager,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        EffectivePrimitiveTypeIdentifierService $epti,
    ): Response {
        $query = $request->query->all();
        $user = $tokenManager->resolve(
            $epti->getIntValueFromArray('id', $query),
            $epti->getIntValueFromArray('expires', $query),
            $epti->getStringValueFromArray('hash', $query, trim: true, forceString: true),
        );

        if (!$user instanceof AppUser) {
            $this->addFlash('error', 'Il link non è valido, è scaduto o è già stato usato. Richiedine uno nuovo.');

            return $this->redirectToRoute('app_reset_password');
        }

        $form = $this->createForm(ResetPasswordConfirmType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $epti->getStringValue($form->get('newPlainPassword')->getData(), forceString: true);
            // Changing the hash also invalidates the link (the signature covers the password hash).
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
            $entityManager->flush();

            $this->addFlash('success', 'Password aggiornata. Ora puoi accedere.');

            return $this->redirectToRoute('app_login');
        }

        // The token is in the URL: the template sets <meta name="referrer" content="no-referrer"> (an HTTP
        // header would be overwritten by NelmioSecurityBundle's global Referrer-Policy).
        return $this->render('registration/reset_password_confirm.html.twig', ['form' => $form]);
    }
}
