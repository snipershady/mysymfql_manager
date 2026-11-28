<?php

declare(strict_types=1);

namespace App\Enum;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Result of an audited action, read from the response it produced.
 */
enum AuditOutcomeEnum: string
{
    /** Done: 2xx (JSON without is_valid: false) or a redirect after a successful form. */
    case SUCCESS = 'success';
    /** Attempted and failed: MySQL error, failed backup/restore, unexpected exception. */
    case FAILURE = 'failure';
    /** Refused by the panel before any operation: unknown or not owned server/database (403/404/409). */
    case DENIED = 'denied';
    /** Form not valid (422): nothing was run. */
    case INVALID = 'invalid';

    public static function fromResponse(Response $response): self
    {
        $status = $response->getStatusCode();

        return match (true) {
            \in_array($status, [Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND, Response::HTTP_CONFLICT], strict: true) => self::DENIED,
            Response::HTTP_UNPROCESSABLE_ENTITY === $status => self::INVALID,
            $status >= 400 => self::FAILURE,
            $response instanceof JsonResponse && false === (self::decode($response)['is_valid'] ?? null) => self::FAILURE,
            default => self::SUCCESS,
        };
    }

    /**
     * Message shown to the user by a JSON action ("message", or "error" for the 404 of some endpoints).
     */
    public static function messageOf(Response $response): ?string
    {
        if (!$response instanceof JsonResponse) {
            return null;
        }

        $body = self::decode($response);
        $message = $body['message'] ?? $body['error'] ?? null;

        return \is_string($message) && '' !== $message ? $message : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::SUCCESS => 'Riuscita',
            self::FAILURE => 'Fallita',
            self::DENIED => 'Negata',
            self::INVALID => 'Dati non validi',
        };
    }

    /** Bootstrap contextual colour of the badge. */
    public function color(): string
    {
        return match ($this) {
            self::SUCCESS => 'success',
            self::FAILURE => 'danger',
            self::DENIED => 'warning',
            self::INVALID => 'secondary',
        };
    }

    /**
     * @return array<mixed>
     */
    private static function decode(JsonResponse $response): array
    {
        $content = $response->getContent();
        if (false === $content || !json_validate($content)) {
            return [];
        }

        $decoded = json_decode($content, associative: true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}
