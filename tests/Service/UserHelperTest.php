<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\UserHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see UserHelper}.
 *
 * Under the CLI SAPI {@see filter_input()} with INPUT_SERVER yields no data,
 * so a freshly built helper falls back to its documented defaults; the
 * mutators are then exercised directly.
 */
final class UserHelperTest extends TestCase
{
    private UserHelper $helper;

    #[\Override]
    protected function setUp(): void
    {
        $this->helper = new UserHelper();
    }

    public function testDefaultsWhenNoRequestDataIsAvailable(): void
    {
        $this->assertSame('0.0.0.0', $this->helper->getClientIp());
        $this->assertSame('', $this->helper->getUserAgent());
        $this->assertSame('', $this->helper->getCfCountryCode());
        $this->assertSame('', $this->helper->getCfRay());
        $this->assertFalse($this->helper->isLocal());
    }

    public function testInitialisersReturnTheStoredValue(): void
    {
        $this->assertSame($this->helper->getClientIp(), $this->helper->initUserIp());
        $this->assertSame($this->helper->getCfCountryCode(), $this->helper->initCFCountryCode());
        $this->assertSame($this->helper->getUserAgent(), $this->helper->initUserAgent());
        $this->assertSame($this->helper->getCfRay(), $this->helper->initHttpCfRay());
    }

    #[DataProvider('localIpProvider')]
    public function testIsLocalRecognisesLoopbackAddresses(string $ip): void
    {
        $this->helper->setClientIp($ip);

        $this->assertTrue($this->helper->isLocal());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localIpProvider(): iterable
    {
        yield 'ipv4 loopback' => ['127.0.0.1'];
        yield 'ipv6 loopback short' => ['::1'];
        yield 'ipv6 loopback expanded' => ['0:0:0:0:0:0:0:1'];
    }

    #[DataProvider('remoteIpProvider')]
    public function testIsLocalRejectsRoutableAddresses(string $ip): void
    {
        $this->helper->setClientIp($ip);

        $this->assertFalse($this->helper->isLocal());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function remoteIpProvider(): iterable
    {
        yield 'public v4' => ['8.8.8.8'];
        yield 'private v4' => ['192.168.1.10'];
        yield 'empty' => [''];
    }

    public function testSettersAreFluentAndRoundTrip(): void
    {
        $result = $this->helper
            ->setClientIp('203.0.113.7')
            ->setUserAgent('Mozilla/5.0 (Test)')
            ->setCfCountryCode('it');

        $this->assertSame($this->helper, $result);
        $this->assertSame('203.0.113.7', $this->helper->getClientIp());
        $this->assertSame('Mozilla/5.0 (Test)', $this->helper->getUserAgent());
        $this->assertSame('it', $this->helper->getCfCountryCode());
    }
}
