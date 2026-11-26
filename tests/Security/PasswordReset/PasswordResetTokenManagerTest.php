<?php

declare(strict_types=1);

namespace App\Tests\Security\PasswordReset;

use App\Entity\AppUser;
use App\Repository\AppUserRepository;
use App\Security\PasswordReset\PasswordResetTokenManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PropertyAccess\PropertyAccess;

#[CoversClass(PasswordResetTokenManager::class)]
final class PasswordResetTokenManagerTest extends TestCase
{
    private AppUser $user;

    private MockClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        $this->user = new AppUser()->setUsername('alice')->setEmail('alice@example.com')->setPassword('$2y$hash-1');
        new \ReflectionProperty(AppUser::class, 'id')->setValue($this->user, 42);
        $this->clock = new MockClock('now');
    }

    public function testIssuedTokenResolvesToItsUser(): void
    {
        $manager = $this->manager();
        $token = $manager->issue($this->user);

        $this->assertSame(42, $token['id']);
        $this->assertSame($this->clock->now()->getTimestamp() + PasswordResetTokenManager::TTL_SECONDS, $token['expires']);
        $this->assertSame($this->user, $manager->resolve($token['id'], $token['expires'], $token['hash']));
    }

    public function testTamperedHashIsRejected(): void
    {
        $manager = $this->manager();
        $token = $manager->issue($this->user);

        $this->assertNull($manager->resolve($token['id'], $token['expires'], $token['hash'] . 'x'));
        $this->assertNull($manager->resolve($token['id'], $token['expires'], ''));
    }

    public function testExtendingTheExpiryInvalidatesTheSignature(): void
    {
        $manager = $this->manager();
        $token = $manager->issue($this->user);

        $this->assertNull($manager->resolve($token['id'], $token['expires'] + 3600, $token['hash']));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $manager = $this->manager();
        $token = $manager->issue($this->user);

        $this->clock->sleep(PasswordResetTokenManager::TTL_SECONDS + 1);

        $this->assertNull($manager->resolve($token['id'], $token['expires'], $token['hash']));
    }

    public function testTokenIsSingleUseBecauseThePasswordHashChanges(): void
    {
        $manager = $this->manager();
        $token = $manager->issue($this->user);

        $this->user->setPassword('$2y$hash-2');

        $this->assertNull($manager->resolve($token['id'], $token['expires'], $token['hash']));
    }

    public function testChangingTheEmailInvalidatesTheToken(): void
    {
        $manager = $this->manager();
        $token = $manager->issue($this->user);

        $this->user->setEmail('mallory@example.com');

        $this->assertNull($manager->resolve($token['id'], $token['expires'], $token['hash']));
    }

    public function testTokenOfAnotherUserIdIsRejected(): void
    {
        $manager = $this->manager();
        $token = $manager->issue($this->user);

        $this->assertNull($manager->resolve(43, $token['expires'], $token['hash']));
        $this->assertNull($manager->resolve(0, $token['expires'], $token['hash']));
    }

    public function testTokensSignedWithAnotherSecretAreRejected(): void
    {
        $token = $this->manager('another-secret')->issue($this->user);

        $this->assertNull($this->manager()->resolve($token['id'], $token['expires'], $token['hash']));
    }

    public function testUnsavedUserCannotGetAToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->manager()->issue(new AppUser()->setEmail('x@example.com')->setPassword('h'));
    }

    private function manager(string $secret = 'test-secret'): PasswordResetTokenManager
    {
        $repository = $this->createStub(AppUserRepository::class);
        $repository->method('find')->willReturnCallback(fn (mixed $id): ?AppUser => 42 === $id ? $this->user : null);

        return new PasswordResetTokenManager($repository, $this->clock, PropertyAccess::createPropertyAccessor(), $secret);
    }
}
