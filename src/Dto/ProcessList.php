<?php

namespace App\Dto;

/**
 * Rappresenta una riga di performance_schema.processlist.
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
     * @param array<string, mixed> $row Riga proveniente da PDO::FETCH_ASSOC
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (int) $row['ID'],
            user: (string) $row['USER'],
            host: (string) $row['HOST'],
            db: isset($row['DB']) ? (string) $row['DB'] : null,
            command: (string) $row['COMMAND'],
            time: (int) $row['TIME'],
            state: isset($row['STATE']) ? (string) $row['STATE'] : null,
            info: isset($row['INFO']) ? (string) $row['INFO'] : null,
            executionEngine: (string) ($row['EXECUTION_ENGINE'] ?? ''),
        );
    }
}
