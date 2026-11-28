<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the AuditContext of a request from the arguments of the controller action, before it runs (so a record
 * being deleted is still described):
 *
 * - input DTOs (App\Dto\Input): "name" → server, "dbName" → database, table / 'user'@'host' / PID / dump file
 *   → object, the other public properties → details;
 * - panel entities resolved from the route (AppUser, SqlClient, DatabaseOwner) → object, plus server/database;
 * - without a DTO (panel forms), the submitted form fields → details.
 *
 * Secrets are never kept: constructor parameters marked #[\SensitiveParameter] and any field whose name contains
 * "password", "secret" or "token" are dropped; SQL text goes through SqlSecretRedactor. Long values are truncated.
 *
 * @see \App\Tests\Service\Audit\AuditContextExtractorTest
 */
final readonly class AuditContextExtractor
{
    /** Bytes kept of a long string (the SQL of the query console, a message). */
    public const int MAX_TEXT_BYTES = 60000;

    private const string SECRET_NAME = '/password|secret|token/i';

    public function __construct(
        private SqlSecretRedactor $redactor = new SqlSecretRedactor(),
    ) {
    }

    /**
     * @param array<array-key, mixed> $arguments controller arguments (resolved)
     */
    public function extract(array $arguments, Request $request): AuditContext
    {
        $serverName = null;
        $dbName = null;
        $target = null;
        $details = [];
        $hasInput = false;

        foreach ($arguments as $argument) {
            if (!\is_object($argument)) {
                continue;
            }

            if ($argument instanceof AppUser) {
                $target ??= \sprintf('Utente #%d %s', (int) $argument->getId(), (string) $argument->getUsername());
            } elseif ($argument instanceof SqlClient) {
                $serverName ??= $argument->getName();
                $target ??= \sprintf('Server #%d %s', (int) $argument->getId(), (string) $argument->getName());
            } elseif ($argument instanceof DatabaseOwner) {
                $serverName ??= $argument->getSqlClient()?->getName();
                $dbName ??= $argument->getDbName();
                $target ??= \sprintf('Assegnazione #%d a %s', (int) $argument->getId(), (string) $argument->getOwner()?->getUserIdentifier());
            } elseif (str_starts_with($argument::class, 'App\\Dto\\Input\\')) {
                $hasInput = true;
                $properties = $this->publicProperties($argument);
                $serverName ??= $this->stringOrNull($properties['name'] ?? null);
                $dbName ??= $this->stringOrNull($properties['dbName'] ?? null);
                $target ??= $this->targetOf($properties);
                unset($properties['name'], $properties['dbName'], $properties['table'], $properties['username'], $properties['userHost'], $properties['pid'], $properties['backupFilename'], $properties['filename']);
                if (isset($properties['sql']) && \is_string($properties['sql'])) {
                    $properties['sql'] = $this->truncate($this->redactor->redact($properties['sql']));
                }

                $details = [...$details, ...$properties];
            }
        }

        if (!$hasInput) {
            $details = $this->withoutSecrets($request->request->all());
        }

        return new AuditContext(
            serverName: null === $serverName ? null : mb_substr($serverName, 0, 31),
            dbName: null === $dbName ? null : mb_substr($dbName, 0, 63),
            target: null === $target ? null : mb_substr($target, 0, 255),
            details: [] === $details ? null : $details,
        );
    }

    public function truncate(string $text): string
    {
        return \strlen($text) > self::MAX_TEXT_BYTES ? mb_strcut($text, 0, self::MAX_TEXT_BYTES) . '…' : $text;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function targetOf(array $properties): ?string
    {
        $table = $this->stringOrNull($properties['table'] ?? null);
        if (null !== $table) {
            return $table;
        }

        $username = $this->stringOrNull($properties['username'] ?? null);
        if (null !== $username) {
            return \sprintf("'%s'@'%s'", $username, $this->stringOrNull($properties['userHost'] ?? null) ?? '%');
        }

        if (\is_int($properties['pid'] ?? null)) {
            return 'PID ' . $properties['pid'];
        }

        return $this->stringOrNull($properties['backupFilename'] ?? $properties['filename'] ?? null);
    }

    /**
     * Public properties of an input DTO, normalized (enums by value, nested DTOs as arrays), without secrets.
     *
     * @return array<string, mixed>
     */
    private function publicProperties(object $object): array
    {
        $reflection = new \ReflectionObject($object);
        $sensitive = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ([] !== $parameter->getAttributes(\SensitiveParameter::class)) {
                $sensitive[$parameter->getName()] = true;
            }
        }

        $properties = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            if (isset($sensitive[$name]) || 1 === preg_match(self::SECRET_NAME, $name) || !$property->isInitialized($object)) {
                continue;
            }

            $properties[$name] = $this->normalize($property->getValue($object));
        }

        return $properties;
    }

    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            \is_array($value) => array_map($this->normalize(...), $value),
            \is_object($value) => $this->publicProperties($value),
            \is_string($value) => $this->truncate($value),
            default => $value,
        };
    }

    /**
     * Submitted form fields without CSRF tokens and passwords, at any depth ("sql" fields redacted).
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function withoutSecrets(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (\is_string($key) && 1 === preg_match(self::SECRET_NAME, $key)) {
                continue;
            }

            $clean[$key] = match (true) {
                \is_array($value) => $this->withoutSecrets($value),
                \is_string($value) && 'sql' === $key => $this->truncate($this->redactor->redact($value)),
                \is_string($value) => $this->truncate($value),
                default => $value,
            };
        }

        return $clean;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
