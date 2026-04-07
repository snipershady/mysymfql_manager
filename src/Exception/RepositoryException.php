<?php

namespace App\Exception;

/**
 * Exception utilizzata dai vari repository in caso di fallimento nei vari metodi di recupero dei dati.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class RepositoryException extends \Exception
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $throwable = null)
    {
        parent::__construct($message.(null !== $throwable ? ', msg del chiamante = '.$throwable->getMessage() : ''), $code, $throwable);
    }
}
