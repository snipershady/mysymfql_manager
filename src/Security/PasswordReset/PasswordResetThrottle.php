<?php

declare(strict_types=1);

namespace App\Security\PasswordReset;

use RateLimiter\Service\RateLimiterInterface;

/**
 * Rate limits password-reset requests per client IP and per target email address,
 * so the form cannot be used to flood a mailbox or to probe many accounts.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\Security\PasswordReset\PasswordResetThrottleTest
 */
final readonly class PasswordResetThrottle
{
    public const int PER_IP_LIMIT = 5;

    public const int PER_IP_WINDOW = 900;

    public const int PER_EMAIL_LIMIT = 3;

    public const int PER_EMAIL_WINDOW = 3600;

    public function __construct(private RateLimiterInterface $rateLimiter)
    {
    }

    public function isAllowed(?string $clientIp, string $email): bool
    {
        // Both counters are always incremented: hitting one limit must not leave the other untouched.
        $ipLimited = $this->rateLimiter->isLimited($this->ipKey($clientIp), self::PER_IP_LIMIT, self::PER_IP_WINDOW);
        $emailLimited = $this->rateLimiter->isLimited($this->emailKey($email), self::PER_EMAIL_LIMIT, self::PER_EMAIL_WINDOW);

        return !$ipLimited && !$emailLimited;
    }

    /**
     * Resets both counters (e.g. an admin unblocking a user, or test isolation).
     */
    public function clear(?string $clientIp, string $email): void
    {
        $this->rateLimiter->clearRateLimitedKey($this->ipKey($clientIp));
        $this->rateLimiter->clearRateLimitedKey($this->emailKey($email));
    }

    private function ipKey(?string $clientIp): string
    {
        return 'pwd_reset_ip_' . ($clientIp ?? 'unknown');
    }

    private function emailKey(string $email): string
    {
        return 'pwd_reset_email_' . hash('sha256', mb_strtolower(trim($email)));
    }
}
