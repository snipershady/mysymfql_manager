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

    /**
     * The error as the mysql client prints it ("ERROR 1146 (42S02): Table 'shop.t' doesn't exist"), for the
     * panel users: system administrators who need MySQL's own message. Falls back to the message of the
     * previous exception, then to this one, when no MySQL error information is available.
     */
    public function mysqlError(): string
    {
        $previous = $this->getPrevious();
        if ($previous instanceof \PDOException) {
            /** @var array{0?: mixed, 1?: mixed, 2?: mixed}|null $info */
            $info = $previous->errorInfo;
            if (isset($info[1], $info[2]) && \is_int($info[1]) && \is_string($info[2])) {
                return \sprintf('ERROR %d (%s): %s', $info[1], \is_string($info[0] ?? null) ? $info[0] : 'HY000', $info[2]);
            }
        }

        return $previous instanceof \Throwable ? $previous->getMessage() : $this->getMessage();
    }
}
