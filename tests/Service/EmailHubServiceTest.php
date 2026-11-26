<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EmailHubService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

#[CoversClass(EmailHubService::class)]
final class EmailHubServiceTest extends TestCase
{
    private mixed $previousFrom;

    /** @var list<RawMessage> */
    private array $sent = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->previousFrom = $_ENV['EMAIL_FROM'] ?? null;
    }

    #[\Override]
    protected function tearDown(): void
    {
        unset($_ENV['EMAIL_FROM']);
        if (null !== $this->previousFrom) {
            $_ENV['EMAIL_FROM'] = $this->previousFrom;
        }
    }

    public function testValidSenderSendsTheTemplatedEmail(): void
    {
        $_ENV['EMAIL_FROM'] = 'noreply@example.com';

        $result = $this->service()->emailResetPassword(new Address('alice@example.com', 'alice'), '<p>hi</p>', 'Subject');

        $this->assertSame(['is_valid' => true, 'msg' => 'ok'], $result);
        $this->assertCount(1, $this->sent);
        $this->assertInstanceOf(TemplatedEmail::class, $this->sent[0]);
        $this->assertSame('noreply@example.com', $this->sent[0]->getFrom()[0]->getAddress());
    }

    public function testInvalidSenderIsReportedInsteadOfThrown(): void
    {
        $_ENV['EMAIL_FROM'] = 'CHANGE';

        $result = $this->service()->emailNotify(new Address('alice@example.com'), 'msg', 'Subject');

        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('CHANGE', $result['msg']);
        $this->assertSame([], $this->sent);
    }

    public function testTransportFailureIsReported(): void
    {
        $_ENV['EMAIL_FROM'] = 'noreply@example.com';

        $result = $this->service(failing: true)->emailNotify(new Address('alice@example.com'), 'msg', 'Subject');

        $this->assertSame(['is_valid' => false, 'msg' => 'smtp down'], $result);
    }

    private function service(bool $failing = false): EmailHubService
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $message) use ($failing): void {
            if ($failing) {
                throw new TransportException('smtp down');
            }
            $this->sent[] = $message;
        });

        return new EmailHubService($mailer, new EffectivePrimitiveTypeIdentifierService());
    }
}
