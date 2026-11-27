<?php

declare(strict_types=1);

namespace App\Tests\Dto\Input;

use App\Dto\Input\AccountTarget;
use App\Dto\Input\BackupTarget;
use App\Dto\Input\DatabaseTarget;
use App\Dto\Input\GrantChanges;
use App\Dto\Input\GrantInput;
use App\Dto\Input\KillProcess;
use App\Dto\Input\NewDatabase;
use App\Dto\Input\OptionalDatabaseTarget;
use App\Dto\Input\OptionalServer;
use App\Dto\Input\PrivilegeClause;
use App\Dto\Input\SqlStatement;
use App\Validator\Constraint\MysqlAccount;
use App\Validator\Constraint\MysqlHost;
use App\Validator\Constraint\MysqlIdentifier;
use App\Validator\Constraint\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validation rules of the request DTOs (the same attributes #[MapRequestPayload] / #[MapQueryString] use).
 */
#[CoversClass(MysqlIdentifier::class)]
#[CoversClass(ServerName::class)]
#[CoversClass(MysqlAccount::class)]
#[CoversClass(MysqlHost::class)]
#[CoversClass(PrivilegeClause::class)]
#[CoversClass(NewDatabase::class)]
#[CoversClass(GrantChanges::class)]
#[CoversClass(BackupTarget::class)]
#[CoversClass(OptionalDatabaseTarget::class)]
#[CoversClass(OptionalServer::class)]
final class InputDtoValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    #[\Override]
    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /**
     * @return list<string> "path: message" of every violation
     */
    private function violations(object $dto): array
    {
        $out = [];
        foreach ($this->validator->validate($dto) as $violation) {
            $out[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
        }

        return $out;
    }

    public function testValidTargetHasNoViolation(): void
    {
        $this->assertSame([], $this->violations(new DatabaseTarget('server_locale', 'shop-2024_€')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'slash' => ['a/b'];
        yield 'backslash' => ['a\b'];
        yield 'dot' => ['schema.table'];
        yield 'control character' => ["a\nb"];
        yield 'leading space' => [' shop'];
        yield 'trailing space' => ['shop '];
        yield 'too long' => [str_repeat('x', 65)];
    }

    #[DataProvider('invalidIdentifiers')]
    public function testInvalidDatabaseNamesAreRejectedNotAltered(string $dbName): void
    {
        $dto = new DatabaseTarget('server_locale', $dbName);

        $this->assertNotSame([], $this->violations($dto));
        $this->assertSame($dbName, $dto->dbName, 'The value is never rewritten.');
    }

    public function testEmptyOptionalValuesBecomeNull(): void
    {
        $this->assertNull(new BackupTarget('srv', 'shop', '')->table);
        $this->assertNull(new OptionalDatabaseTarget('srv', '')->dbName);
        $this->assertNull(new OptionalServer('')->name);
        $this->assertSame([], $this->violations(new BackupTarget('srv', 'shop', '')));
        $this->assertSame([], $this->violations(new OptionalServer()));
    }

    public function testSqlMustNotBeBlank(): void
    {
        $this->assertSame(['sql: La query non può essere vuota.'], $this->violations(new SqlStatement('srv', 'shop', "  \n ")));
    }

    public function testPidMustBePositive(): void
    {
        $this->assertSame(['pid: Il PID deve essere un numero positivo.'], $this->violations(new KillProcess('srv', 0)));
    }

    public function testNewDatabaseRejectsPrivilegesOutsideTheWhitelist(): void
    {
        $dto = new NewDatabase('srv', 'shop', 'app_rw', ' secret ', '%', 'SELECT,SUPER');

        $this->assertSame(['privileges: Privilegio non supportato: "SUPER".'], $this->violations($dto));
        $this->assertSame(' secret ', $dto->password, 'Passwords are never trimmed.');
    }

    public function testNewDatabaseGrantClauseIsNormalized(): void
    {
        $this->assertSame('SELECT, INSERT', new NewDatabase('srv', 'shop', 'app_rw', 'pw', '%', ' select ,insert')->grantClause());
    }

    public function testNestedGrantErrorsCarryTheirPath(): void
    {
        $dto = new GrantChanges('srv', 'app_rw', '%', [new GrantInput('shop', 'SELECT'), new GrantInput('a/b', 'NOPE')], ['ok_db', 'bad.db']);

        $this->assertEqualsCanonicalizing([
            'grants[1].db: Il nome non può contenere "/", "\", "." o caratteri di controllo.',
            'grants[1].privileges: Privilegio non supportato: "NOPE".',
            'revokedDbs[1]: Il nome non può contenere "/", "\", "." o caratteri di controllo.',
        ], $this->violations($dto));
    }

    public function testAccountAndHostRules(): void
    {
        $dto = new AccountTarget('srv', str_repeat('u', 33), '192.168.1. %');

        $this->assertCount(2, $this->violations($dto));
    }
}
