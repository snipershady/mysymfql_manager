<?php

declare(strict_types=1);

namespace App\Security\PasswordReset;

use App\Entity\AppUser;
use App\Repository\AppUserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\Signature\Exception\ExpiredSignatureException;
use Symfony\Component\Security\Core\Signature\Exception\InvalidSignatureException;
use Symfony\Component\Security\Core\Signature\SignatureHasher;

/**
 * Stateless, signed password-reset tokens (no database table).
 *
 * The signature covers the user id, email and current password hash plus the expiry, so a token:
 *  - expires after {@see self::TTL_SECONDS};
 *  - is single-use: once the password changes, the signature no longer matches;
 *  - dies if the email or the password change by any other means.
 * The HMAC key is derived from APP_SECRET with a purpose label, so these tokens cannot be
 * confused with other signatures built on the same secret.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\Security\PasswordReset\PasswordResetTokenManagerTest
 */
final readonly class PasswordResetTokenManager
{
    public const int TTL_SECONDS = 1800;

    private SignatureHasher $signatureHasher;

    public function __construct(
        private AppUserRepository $appUserRepository,
        private ClockInterface $clock,
        PropertyAccessorInterface $propertyAccessor,
        #[Autowire(param: 'kernel.secret')]
        #[\SensitiveParameter]
        string $secret,
    ) {
        $this->signatureHasher = new SignatureHasher(
            $propertyAccessor,
            ['id', 'email', 'password'],
            hash_hmac('sha256', 'mysymfql.password_reset', $secret),
        );
    }

    /**
     * @return array{id: int, expires: int, hash: string} the query parameters of the reset link
     */
    public function issue(AppUser $user): array
    {
        $id = $user->getId() ?? throw new \InvalidArgumentException('Only a persisted user can reset the password.');
        $expires = $this->clock->now()->getTimestamp() + self::TTL_SECONDS;

        return ['id' => $id, 'expires' => $expires, 'hash' => $this->signatureHasher->computeSignatureHash($user, $expires)];
    }

    /**
     * Returns the user the token was issued for, or null when the token is unknown, tampered with,
     * expired or already used.
     */
    public function resolve(int $id, int $expires, string $hash): ?AppUser
    {
        if ($id <= 0 || '' === $hash || $expires < $this->clock->now()->getTimestamp()) {
            return null;
        }

        $user = $this->appUserRepository->find($id);
        if (!$user instanceof AppUser) {
            return null;
        }

        try {
            $this->signatureHasher->verifySignatureHash($user, $expires, $hash);
        } catch (ExpiredSignatureException|InvalidSignatureException) {
            return null;
        }

        return $user;
    }
}
