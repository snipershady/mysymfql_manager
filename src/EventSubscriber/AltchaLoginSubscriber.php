<?php

namespace App\EventSubscriber;

use AltchaOrg\Altcha\Altcha;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

/**
 * Validates the Altcha CAPTCHA token on every login attempt.
 * Runs during CheckPassportEvent, before credentials are verified,
 * so bots are rejected regardless of whether credentials are correct.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com>
 */
final readonly class AltchaLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [CheckPassportEvent::class => ['onCheckPassport', 10]];
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        if (!$event->getAuthenticator() instanceof FormLoginAuthenticator) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return;
        }

        $altchaPayload = $request->request->get('altcha', '');

        if ('' === $altchaPayload) {
            throw new CustomUserMessageAuthenticationException('Please complete the CAPTCHA verification.');
        }

        $epti = new EffectivePrimitiveTypeIdentifierService();
        $hmacKey = $epti->getTypedValueFromServer(needle: 'ALTCHAKEY', trim: true, forceString: true, sanitizeHtml: true);

        $altcha = new Altcha($hmacKey);

        if (!$altcha->verifySolution($altchaPayload, checkExpires: true)) {
            throw new CustomUserMessageAuthenticationException('CAPTCHA verification invalid or expired. Please try again.');
        }
    }
}
