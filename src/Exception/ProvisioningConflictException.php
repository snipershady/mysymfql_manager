<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The requested database or MySQL account already exists: nothing has been changed.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
final class ProvisioningConflictException extends ProvisioningException
{
}
