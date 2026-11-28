<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Attribute\Audited;
use App\Enum\AuditActionEnum;
use App\Exception\RepositoryException;
use App\Service\Audit\AuditContext;
use App\Service\Audit\AuditContextExtractor;
use App\Service\Audit\AuditTrail;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Audit log of the actions marked #[Audited] (non-safe requests only):
 *
 * 1. kernel.controller_arguments, priority -10200: after #[IsGranted] (20), #[IsCsrfTokenValid] (25) and the
 *    #[MapRequestPayload]/#[MapQueryString] mapping (-10100, it replaces the placeholders with the validated
 *    DTOs), so refused requests are not recorded; the context is read from the arguments, before the action;
 * 2. kernel.exception: the message of an unexpected exception (MySQL's native error for a RepositoryException);
 * 3. kernel.response, priority -1024 (the final response, error pages included): the entry is written.
 *
 * @see \App\Tests\Functional\AuditLogTest
 */
final readonly class AuditListener
{
    private const string CONTEXT = '_audit_context';

    private const string ACTION = '_audit_action';

    private const string ERROR = '_audit_error';

    public function __construct(
        private AuditContextExtractor $extractor,
        private AuditTrail $trail,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS, priority: -10200)]
    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethodSafe()) {
            return;
        }

        $audited = $event->getAttributes(Audited::class)[0] ?? null;
        if (!$audited instanceof Audited) {
            return;
        }

        $request->attributes->set(self::ACTION, $audited->action);
        $request->attributes->set(self::CONTEXT, $this->extractor->extract($event->getArguments(), $request));
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 64)]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->attributes->has(self::ACTION)) {
            return;
        }

        $throwable = $event->getThrowable();
        $request->attributes->set(self::ERROR, $throwable instanceof RepositoryException ? $throwable->mysqlError() : $throwable->getMessage());
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest()) {
            return;
        }

        $action = $request->attributes->get(self::ACTION);
        $context = $request->attributes->get(self::CONTEXT);
        if (!$action instanceof AuditActionEnum || !$context instanceof AuditContext) {
            return;
        }

        $request->attributes->remove(self::ACTION);
        $error = $request->attributes->get(self::ERROR);

        $this->trail->record($action, $context, $request, $event->getResponse(), \is_string($error) ? $error : null);
    }
}
