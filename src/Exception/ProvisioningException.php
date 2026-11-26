<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A multi-step operation spanning the managed MySQL server and the application catalog failed.
 *
 * The message is safe to show to the panel user (no driver details); the cause is chained in
 * getPrevious() and logged. {@see self::$compensationFailures} lists the undo steps that could
 * not be completed: when non-empty, manual cleanup is required.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class ProvisioningException extends \RuntimeException
{
    /**
     * @param list<string> $compensationFailures
     */
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
        public readonly array $compensationFailures = [],
    ) {
        parent::__construct($message, 0, $previous);
    }
}
