<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Repository\DatabaseOwnerRepository;
use App\Security\ManagedDatabase;
use App\Security\Voter\DatabaseVoter;
use App\Security\Voter\SqlClientVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(DatabaseVoter::class)]
#[UsesClass(ManagedDatabase::class)]
final class DatabaseVoterTest extends TestCase
{
    public function testAbstainsOnOtherAttributesAndSubjects(): void
    {
        $voter = $this->voter(isAdmin: true, isServerOwner: true, ownedDbs: []);
        $token = $this->token();

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $this->subject('shop'), [SqlClientVoter::VIEW]));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new SqlClient(), [DatabaseVoter::ACCESS]));
    }

    public function testAnonymousIsDenied(): void
    {
        $voter = $this->voter(isAdmin: true, isServerOwner: true, ownedDbs: ['shop']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), $this->subject('shop'), [DatabaseVoter::ACCESS]));
    }

    public function testAdminIsGrantedOnAnyDatabase(): void
    {
        $voter = $this->voter(isAdmin: true, isServerOwner: false, ownedDbs: []);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token(), $this->subject('anything'), [DatabaseVoter::ACCESS]));
    }

    public function testServerOwnerIsGrantedOnOwnedDatabase(): void
    {
        $voter = $this->voter(isAdmin: false, isServerOwner: true, ownedDbs: ['shop']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token(), $this->subject('shop'), [DatabaseVoter::ACCESS]));
    }

    public function testServerOwnerIsDeniedOnForeignDatabase(): void
    {
        $voter = $this->voter(isAdmin: false, isServerOwner: true, ownedDbs: ['shop']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token(), $this->subject('other_tenant'), [DatabaseVoter::ACCESS]));
    }

    public function testDatabaseOwnerWhoLostTheServerIsDenied(): void
    {
        $voter = $this->voter(isAdmin: false, isServerOwner: false, ownedDbs: ['shop']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token(), $this->subject('shop'), [DatabaseVoter::ACCESS]));
    }

    public function testEmptyDatabaseNameIsDeniedToNonAdmin(): void
    {
        $voter = $this->voter(isAdmin: false, isServerOwner: true, ownedDbs: ['']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token(), $this->subject(''), [DatabaseVoter::ACCESS]));
    }

    /**
     * @param list<string> $ownedDbs
     */
    private function voter(bool $isAdmin, bool $isServerOwner, array $ownedDbs): DatabaseVoter
    {
        $adm = $this->createStub(AccessDecisionManagerInterface::class);
        $adm->method('decide')->willReturnCallback(
            static fn (TokenInterface $t, array $attributes): bool => match ($attributes) {
                ['ROLE_ADMIN'] => $isAdmin,
                [SqlClientVoter::VIEW] => $isServerOwner,
                default => false,
            }
        );
        $repository = $this->createStub(DatabaseOwnerRepository::class);
        $repository->method('findDbNamesOwnedOn')->willReturn($ownedDbs);

        return new DatabaseVoter($adm, $repository);
    }

    private function token(): TokenInterface
    {
        $user = new AppUser()->setUsername('user');

        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function subject(string $dbName): ManagedDatabase
    {
        return new ManagedDatabase(new SqlClient(), $dbName);
    }
}
