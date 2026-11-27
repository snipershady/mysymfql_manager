<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Invalid input of a JSON endpoint (#[MapRequestPayload] / #[MapQueryString] validation or type errors)
 * answered in the format the front end reads: {is_valid: false, message, errors[]}, status 422.
 *
 * Only for actions declaring a JsonResponse return type; HTML pages keep the standard error page.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class JsonValidationErrorListener
{
    /** Request fields (form/query/JSON keys and DTO properties) as shown to the user. */
    private const array LABELS = [
        'name' => 'Server',
        'db_name' => 'Database', 'dbName' => 'Database',
        'table' => 'Tabella',
        'sql' => 'Query',
        'pid' => 'PID',
        'username' => 'Utente',
        'user_host' => 'Host', 'userHost' => 'Host',
        'password' => 'Password',
        'privileges' => 'Privilegi',
        'charset' => 'Charset',
        'collation' => 'Collation',
        'grants' => 'Grant',
        'revoked_dbs' => 'Database da revocare', 'revokedDbs' => 'Database da revocare',
        'backup_filename' => 'File di backup', 'backupFilename' => 'File di backup',
        'filename' => 'File',
    ];

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $validation = $exception->getPrevious();
        if (!$exception instanceof HttpExceptionInterface || !$validation instanceof ValidationFailedException) {
            return;
        }

        if (!JsonAction::returnsJson($event->getRequest()->attributes->get('_controller'))) {
            return;
        }

        $errors = [];
        foreach ($validation->getViolations() as $violation) {
            $errors[] = ['field' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
        }

        $event->setResponse(new JsonResponse([
            'is_valid' => false,
            'message' => [] === $errors ? 'Dati non validi.' : $this->describe($errors[0]),
            'errors' => $errors,
        ], $exception->getStatusCode()));
    }

    /**
     * @param array{field: string, message: string} $error
     */
    private function describe(array $error): string
    {
        // "grants[0].privileges" → "Grant: …"
        $root = preg_replace('/[.\[].*$/', '', $error['field']) ?? '';
        $label = self::LABELS[$root] ?? $root;

        return '' === $label ? $error['message'] : \sprintf('%s: %s', $label, $error['message']);
    }
}
