<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AppUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Unit tests for {@see AppUser}.
 */
final class AppUserTest extends TestCase
{
    private function makeUser(string $username = 'alice', string $email = 'alice@example.com'): AppUser
    {
        return new AppUser()
            ->setUsername($username)
            ->setEmail($email)
            ->setPassword('hashed-password');
    }

    public function testImplementsSecurityContracts(): void
    {
        $user = new AppUser();

        $this->assertInstanceOf(UserInterface::class, $user);
        $this->assertInstanceOf(PasswordAuthenticatedUserInterface::class, $user);
        $this->assertInstanceOf(\Stringable::class, $user);
    }

    public function testAccessorsRoundTrip(): void
    {
        $user = $this->makeUser();

        $this->assertNull($user->getId());
        $this->assertSame('alice', $user->getUsername());
        $this->assertSame('alice@example.com', $user->getEmail());
        $this->assertSame('hashed-password', $user->getPassword());
    }

    public function testGetUserIdentifierReturnsTheUsername(): void
    {
        $this->assertSame('alice', $this->makeUser()->getUserIdentifier());
    }

    public function testGetUserIdentifierThrowsWhenUsernameIsMissing(): void
    {
        $this->expectException(\LogicException::class);
        new AppUser()->getUserIdentifier();
    }

    public function testGetRolesRemovesDuplicates(): void
    {
        $user = new AppUser()->setRoles(['ROLE_ADMIN', 'ROLE_ADMIN', 'ROLE_USER']);

        $this->assertSame(['ROLE_ADMIN', 'ROLE_USER'], array_values($user->getRoles()));
    }

    public function testGetRolesDefaultsToAnEmptyList(): void
    {
        $this->assertSame([], new AppUser()->getRoles());
    }

    public function testToStringContainsIdentifyingFields(): void
    {
        $user = $this->makeUser()->setRoles(['ROLE_USER']);
        $string = (string) $user;

        $this->assertStringContainsString('username=alice', $string);
        $this->assertStringContainsString('email=alice@example.com', $string);
        $this->assertStringContainsString('roles=ROLE_USER', $string);
    }

    public function testEqualsComparesUsernameEmailAndId(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $this->assertTrue($a->equals($b));

        $b->setEmail('other@example.com');
        $this->assertFalse($a->equals($b));
    }

    public function testSerializeReplacesTheRawPasswordWithACrc32cDigest(): void
    {
        $user = $this->makeUser();
        $data = $user->__serialize();

        $key = "\0" . AppUser::class . "\0password";

        $this->assertArrayHasKey($key, $data);
        $this->assertSame(hash('crc32c', 'hashed-password'), $data[$key]);
        $this->assertNotContains('hashed-password', $data);
    }
}
