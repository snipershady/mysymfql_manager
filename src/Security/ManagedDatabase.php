<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\SqlClient;

/**
 * Authorization subject for {@see Voter\DatabaseVoter}: a database name on a given server.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
final readonly class ManagedDatabase
{
    public function __construct(
        public SqlClient $sqlClient,
        public string $dbName,
    ) {
    }
}
