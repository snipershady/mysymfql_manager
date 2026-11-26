<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Security\Voter\SqlClientVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(SqlClientVoter::class)]
final class SqlClientVoterTest extends TestCase
{
    public function testAbstainsOnUnsupportedAttribute(): void
    {
        $result = $this->voter(isAdmin: true)->vote($this->token($this->user(1)), new SqlClient(), ['SOMETHING_ELSE']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testAbstainsOnUnsupportedSubject(): void
    {
        $result = $this->voter(isAdmin: true)->vote($this->token($this->user(1)), new \stdClass(), [SqlClientVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function attributes(): iterable
    {
        yield 'view' => [SqlClientVoter::VIEW];
        yield 'manage' => [SqlClientVoter::MANAGE];
    }

    #[DataProvider('attributes')]
    public function testAnonymousIsDenied(string $attribute): void
    {
        $result = $this->voter(isAdmin: false)->vote(new NullToken(), new SqlClient(), [$attribute]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[DataProvider('attributes')]
    public function testAdminIsGrantedEvenWhenNotOwner(string $attribute): void
    {
        $result = $this->voter(isAdmin: true)->vote($this->token($this->user(1)), new SqlClient(), [$attribute]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testOwnerCanView(): void
    {
        $owner = $this->user(7);
        // A distinct instance with the same id (e.g. user reloaded from the session) must still match.
        $sqlClient = new SqlClient()->addOwner($this->user(7));

        $result = $this->voter(isAdmin: false)->vote($this->token($owner), $sqlClient, [SqlClientVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testOwnerCannotManage(): void
    {
        $owner = $this->user(7);
        $sqlClient = new SqlClient()->addOwner($owner);

        $result = $this->voter(isAdmin: false)->vote($this->token($owner), $sqlClient, [SqlClientVoter::MANAGE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testNonOwnerCannotView(): void
    {
        $sqlClient = new SqlClient()->addOwner($this->user(7));

        $result = $this->voter(isAdmin: false)->vote($this->token($this->user(8)), $sqlClient, [SqlClientVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testUnpersistedUserNeverMatchesAnUnpersistedOwner(): void
    {
        $sqlClient = new SqlClient()->addOwner(new AppUser());

        $result = $this->voter(isAdmin: false)->vote($this->token(new AppUser()), $sqlClient, [SqlClientVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    private function voter(bool $isAdmin): SqlClientVoter
    {
        $adm = $this->createStub(AccessDecisionManagerInterface::class);
        $adm->method('decide')->willReturn($isAdmin);

        return new SqlClientVoter($adm);
    }

    private function token(AppUser $user): TokenInterface
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function user(int $id): AppUser
    {
        $user = new AppUser()->setUsername('user' . $id);
        new \ReflectionProperty(AppUser::class, 'id')->setValue($user, $id);

        return $user;
    }
}
