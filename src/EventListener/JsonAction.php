<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Tells whether the controller of a request is an action declaring a JsonResponse return type: the error
 * listeners answer those in JSON and leave HTML pages to the standard error page.
 */
final class JsonAction
{
    public static function returnsJson(mixed $controller): bool
    {
        if (!\is_string($controller) || !str_contains($controller, '::')) {
            return false;
        }

        [$class, $method] = explode('::', $controller, 2);
        if (!method_exists($class, $method)) {
            return false;
        }

        $type = new \ReflectionMethod($class, $method)->getReturnType();

        return $type instanceof \ReflectionNamedType && is_a($type->getName(), JsonResponse::class, allow_string: true);
    }
}
