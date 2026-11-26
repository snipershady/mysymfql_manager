<?php

declare(strict_types=1);

namespace App\Tests\Security\PasswordReset;

use App\Security\PasswordReset\PasswordResetThrottle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RateLimiter\Service\RateLimiterInterface;

#[CoversClass(PasswordResetThrottle::class)]
final class PasswordResetThrottleTest extends TestCase
{
    public function testRepeatedRequestsForTheSameEmailAreLimited(): void
    {
        $throttle = new PasswordResetThrottle($this->inMemoryLimiter());

        for ($i = 1; $i <= PasswordResetThrottle::PER_EMAIL_LIMIT; ++$i) {
            $this->assertTrue($throttle->isAllowed('10.0.0.' . $i, 'Alice@Example.com '), 'request ' . $i);
        }

        // Case and surrounding spaces do not open a new bucket.
        $this->assertFalse($throttle->isAllowed('10.0.0.99', 'alice@example.com'));
        $this->assertTrue($throttle->isAllowed('10.0.0.99', 'bob@example.com'));
    }

    public function testManyEmailsFromOneIpAreLimited(): void
    {
        $throttle = new PasswordResetThrottle($this->inMemoryLimiter());

        for ($i = 1; $i <= PasswordResetThrottle::PER_IP_LIMIT; ++$i) {
            $this->assertTrue($throttle->isAllowed('10.0.0.1', \sprintf('user%d@example.com', $i)));
        }

        $this->assertFalse($throttle->isAllowed('10.0.0.1', 'another@example.com'));
        $this->assertTrue($throttle->isAllowed('10.0.0.2', 'another@example.com'));
    }

    public function testUnknownClientIpSharesOneBucket(): void
    {
        $throttle = new PasswordResetThrottle($this->inMemoryLimiter());

        for ($i = 1; $i <= PasswordResetThrottle::PER_IP_LIMIT; ++$i) {
            $throttle->isAllowed(null, \sprintf('user%d@example.com', $i));
        }

        $this->assertFalse($throttle->isAllowed(null, 'another@example.com'));
    }

    public function testClearResetsBothCounters(): void
    {
        $throttle = new PasswordResetThrottle($this->inMemoryLimiter());
        for ($i = 0; $i < PasswordResetThrottle::PER_IP_LIMIT; ++$i) {
            $throttle->isAllowed('10.0.0.1', 'alice@example.com');
        }
        $this->assertFalse($throttle->isAllowed('10.0.0.1', 'alice@example.com'));

        $throttle->clear('10.0.0.1', ' ALICE@example.com');

        $this->assertTrue($throttle->isAllowed('10.0.0.1', 'alice@example.com'));
    }

    /**
     * Counts calls per key: limited once the counter exceeds $limit (TTL ignored).
     */
    private function inMemoryLimiter(): RateLimiterInterface
    {
        return new class implements RateLimiterInterface {
            /** @var array<string, int> */
            private array $hits = [];

            #[\Override]
            public function isLimited(string $key, int $limit, int $ttl): bool
            {
                $this->hits[$key] = ($this->hits[$key] ?? 0) + 1;

                return $this->hits[$key] > $limit;
            }

            #[\Override]
            public function isLimitedWithBan(string $key, int $limit, int $ttl, int $maxAttempts, int $banTimeFrame, int $banTtl, ?string $clientIp): bool
            {
                return $this->isLimited($key, $limit, $ttl);
            }

            #[\Override]
            public function clearRateLimitedKey(string $key): bool
            {
                unset($this->hits[$key]);

                return true;
            }

            #[\Override]
            public function clearBan(string $key, ?string $clientIp = null): bool
            {
                return $this->clearRateLimitedKey($key);
            }
        };
    }
}
