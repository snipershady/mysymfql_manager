<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\AltchaCaptcha;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Validates the Altcha CAPTCHA token on every login attempt.
 * Runs during CheckPassportEvent, before credentials are verified,
 * so bots are rejected regardless of whether credentials are correct.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com>
 */
final readonly class AltchaLoginSubscriber
{
    public function __construct(
        private RequestStack $requestStack,
        private AltchaCaptcha $captcha,
    ) {
    }

    #[AsEventListener(event: CheckPassportEvent::class, priority: 10)]
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        if (!$event->getAuthenticator() instanceof FormLoginAuthenticator) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return;
        }

        $altchaPayload = $request->request->all()['altcha'] ?? '';

        if ('' === $altchaPayload) {
            throw new CustomUserMessageAuthenticationException('Completa la verifica CAPTCHA.');
        }

        if (!\is_string($altchaPayload) || !$this->captcha->verify($altchaPayload)) {
            throw new CustomUserMessageAuthenticationException('Verifica CAPTCHA non valida o scaduta. Riprova.');
        }
    }
}
