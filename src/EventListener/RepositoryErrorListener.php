<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\RepositoryException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A failed statement on a managed server, in a JSON endpoint, answered as {is_valid: false, message} with
 * MySQL's native error ("ERROR 1451 (23000): Cannot delete or update a parent row…"): the panel is a tool
 * for system administrators, who need the real message rather than a generic one.
 *
 * Priority -64: after Symfony's ErrorListener has logged the exception (priority 0; setting a response
 * stops the propagation), before it renders the error page (-128). HTML pages keep the standard error page.
 *
 * @see \App\Tests\Functional\RepositoryErrorTest
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
final class RepositoryErrorListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof RepositoryException || !JsonAction::returnsJson($event->getRequest()->attributes->get('_controller'))) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['is_valid' => false, 'message' => $exception->mysqlError()],
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ));
    }
}
