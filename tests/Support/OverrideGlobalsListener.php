<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Test environment only (registered under when@test in config/services.yaml).
 *
 * Controllers read input through EffectivePrimitiveTypeIdentifierService::get*FromGet/Post(),
 * i.e. from the PHP superglobals, which the KernelBrowser does not populate. Copying the
 * Request into $_GET/$_POST/$_SERVER reproduces what PHP does on a real request.
 * Remove once controllers read from the Request object (AGENTS_EPTI.md section 7).
 */
final class OverrideGlobalsListener
{
    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->overrideGlobals();
        }
    }
}
