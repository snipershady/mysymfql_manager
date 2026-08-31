<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\MysqlUser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MysqlUser}.
 */
final class MysqlUserTest extends TestCase
{
    public function testConstructorStoresValues(): void
    {
        $user = new MysqlUser('app', '10.0.0.%', true, false);

        $this->assertSame('app', $user->user);
        $this->assertSame('10.0.0.%', $user->host);
        $this->assertTrue($user->accountLocked);
        $this->assertFalse($user->hasDbGrant);
    }

    public function testFromArrayMapsLockedAccountAndGrant(): void
    {
        $user = MysqlUser::fromArray([
            'User' => 'app',
            'Host' => 'localhost',
            'account_locked' => 'Y',
            'has_db_grant' => 1,
        ]);

        $this->assertSame('app', $user->user);
        $this->assertSame('localhost', $user->host);
        $this->assertTrue($user->accountLocked);
        $this->assertTrue($user->hasDbGrant);
    }

    public function testFromArrayTreatsNonYLockFlagAsUnlocked(): void
    {
        $user = MysqlUser::fromArray([
            'User' => 'app',
            'Host' => 'localhost',
            'account_locked' => 'N',
            'has_db_grant' => 0,
        ]);

        $this->assertFalse($user->accountLocked);
        $this->assertFalse($user->hasDbGrant);
    }

    public function testFromArrayWithMissingKeysUsesSafeDefaults(): void
    {
        $user = MysqlUser::fromArray([]);

        $this->assertSame('', $user->user);
        $this->assertSame('', $user->host);
        $this->assertFalse($user->accountLocked);
        $this->assertFalse($user->hasDbGrant);
    }
}
