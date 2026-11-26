<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * CSRF protection for the AJAX endpoints under /schema/.
 *
 * Every state-changing request (any non-safe method) must carry the session-bound token
 * {@see self::TOKEN_ID} in the {@see self::HEADER} header. The token is rendered in
 * layouts/admin.html.twig (<meta name="csrf-token">) and attached by public/asset/js/csrf-fetch.js.
 * Runs after the firewall (priority 8), before the controller, so a rejected request has no
 * effect on the remote server.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\EventListener\SchemaCsrfListenerTest
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final readonly class SchemaCsrfListener
{
    public const string TOKEN_ID = 'schema_ajax';

    public const string HEADER = 'X-CSRF-Token';

    private const string PATH_PREFIX = '/schema/';

    public function __construct(private CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->isMethodSafe() || !str_starts_with($request->getPathInfo(), self::PATH_PREFIX)) {
            return;
        }

        $token = (string) $request->headers->get(self::HEADER, '');
        if ('' !== $token && $this->csrfTokenManager->isTokenValid(new CsrfToken(self::TOKEN_ID, $token))) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['is_valid' => false, 'message' => 'Invalid or missing CSRF token. Reload the page and retry.'],
            Response::HTTP_FORBIDDEN
        ));
    }
}
