<?php

namespace App\Dto;

use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

/**
 * Represents a row from performance_schema.processlist.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
final readonly class ProcessList
{
    public function __construct(
        public int $id,
        public string $user,
        public string $host,
        public ?string $db,
        public string $command,
        public int $time,
        public ?string $state,
        public ?string $info,
        public string $executionEngine,
    ) {
    }

    /**
     * @param array<string, mixed> $row Row coming from PDO::FETCH_ASSOC
     */
    public static function fromArray(array $row): self
    {
        $epti = new EffectivePrimitiveTypeIdentifierService();

        return new self(
            id: $epti->getIntValueFromArray('ID', $row),
            user: $epti->getStringValueFromArray('USER', $row, forceString: true),
            host: $epti->getStringValueFromArray('HOST', $row, forceString: true),
            db: isset($row['DB']) ? $epti->getStringValueFromArray('DB', $row, forceString: true) : null,
            command: $epti->getStringValueFromArray('COMMAND', $row, forceString: true),
            time: $epti->getIntValueFromArray('TIME', $row),
            state: isset($row['STATE']) ? $epti->getStringValueFromArray('STATE', $row, forceString: true) : null,
            info: isset($row['INFO']) ? $epti->getStringValueFromArray('INFO', $row, forceString: true) : null,
            executionEngine: $epti->getStringValueFromArray('EXECUTION_ENGINE', $row, forceString: true),
        );
    }
}
