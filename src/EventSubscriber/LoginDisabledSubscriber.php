<?php

namespace App\EventSubscriber;

use App\Entity\AppUser;
use App\Enum\RoleEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Intercepts a successful login for ROLE_DISABLED users:
 * immediately invalidates their session and redirects to the
 * login page with a query param that triggers a SweetAlert notice.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com>
 */
final readonly class LoginDisabledSubscriber implements EventSubscriberInterface
{
    public function __construct(private RouterInterface $router)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getAuthenticatedToken()->getUser();

        if (!$user instanceof AppUser) {
            return;
        }

        if (!in_array(RoleEnum::ROLE_DISABLED->value, $user->getRoles(), true)) {
            return;
        }

        $event->getRequest()->getSession()->invalidate();

        $event->setResponse(
            new RedirectResponse(
                $this->router->generate('app_login', ['disabled' => 1])
            )
        );
    }
}
