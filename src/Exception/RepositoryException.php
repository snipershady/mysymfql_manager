<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Exception used by the various repositories when data retrieval methods fail.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\Exception\RepositoryExceptionTest
 */
class RepositoryException extends \Exception
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $throwable = null)
    {
        parent::__construct($message . ($throwable instanceof \Throwable ? ', caller message = ' . $throwable->getMessage() : ''), $code, $throwable);
    }
}
