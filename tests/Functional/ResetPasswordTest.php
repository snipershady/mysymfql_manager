<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\ResetPasswordController;
use App\Entity\AppUser;
use App\Security\PasswordReset\PasswordResetThrottle;
use App\Security\PasswordReset\PasswordResetTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * HTTP-level checks of the password-reset flow.
 *
 * Read-only on the application catalog (the test DB is shared with dev, see CLAUDE.md):
 * tokens are issued for an existing user and the confirm form is only displayed, never submitted.
 * E-mails go to the null transport (MAILER_DSN in .env.test) and are inspected through the test mailer;
 * the throttle counters touched in Redis are cleared after each test.
 */
#[CoversClass(ResetPasswordController::class)]
final class ResetPasswordTest extends WebTestCase
{
    private const string CLIENT_IP = '127.0.0.1';

    private KernelBrowser $client;

    /** @var list<string> */
    private array $requestedEmails = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $throttle = self::getContainer()->get(PasswordResetThrottle::class);
        \assert($throttle instanceof PasswordResetThrottle);
        foreach ($this->requestedEmails as $email) {
            $throttle->clear(self::CLIENT_IP, $email);
        }

        parent::tearDown();
    }

    public function testRequestAnswersGenericallyAndDoesNotRevealAccounts(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        $this->assertResponseIsSuccessful();

        $this->submitRequest($crawler, 'nobody-' . bin2hex(random_bytes(4)) . '@example.invalid');

        $this->assertResponseRedirects('/login');
        $this->assertEmailCount(0);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'If the address belongs to an account');
    }

    public function testRequestForAnExistingAccountMailsALinkAndKeepsThePassword(): void
    {
        $user = $this->existingUser();
        $passwordBefore = $user->getPassword();

        $this->submitRequest($this->client->request('GET', '/reset-password'), (string) $user->getEmail());

        $this->assertResponseRedirects('/login');
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertNotNull($email);
        $this->assertEmailHtmlBodyContains($email, '/reset-password/confirm?');
        $this->assertEmailHtmlBodyNotContains($email, 'Your new password');

        // Requesting a reset alone never changes the password.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();
        $this->assertSame($passwordBefore, $entityManager->getRepository(AppUser::class)->find($user->getId())?->getPassword());
    }

    public function testRepeatedRequestsForTheSameAccountAreThrottled(): void
    {
        $address = (string) $this->existingUser()->getEmail();

        for ($i = 0; $i <= PasswordResetThrottle::PER_EMAIL_LIMIT; ++$i) {
            $this->submitRequest($this->client->request('GET', '/reset-password'), $address);
            $this->assertResponseRedirects('/login');
            $this->assertEmailCount($i < PasswordResetThrottle::PER_EMAIL_LIMIT ? 1 : 0);
        }
    }

    public function testInvalidTokenIsRejected(): void
    {
        $this->client->request('GET', '/reset-password/confirm', ['id' => 1, 'expires' => time() + 600, 'hash' => 'forged']);

        $this->assertResponseRedirects('/reset-password');
    }

    public function testValidTokenShowsTheFormWithoutLeakingTheReferer(): void
    {
        $user = $this->existingUser();
        $tokenManager = self::getContainer()->get(PasswordResetTokenManager::class);
        \assert($tokenManager instanceof PasswordResetTokenManager);

        $this->client->request('GET', '/reset-password/confirm', $tokenManager->issue($user));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="reset_password_confirm[newPlainPassword][first]"]');
        $this->assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $user = $this->existingUser();
        $tokenManager = self::getContainer()->get(PasswordResetTokenManager::class);
        \assert($tokenManager instanceof PasswordResetTokenManager);
        $token = $tokenManager->issue($user);

        $this->client->request('GET', '/reset-password/confirm', [...$token, 'expires' => time() - 1]);

        $this->assertResponseRedirects('/reset-password');
    }

    private function submitRequest(Crawler $crawler, string $email): void
    {
        $this->requestedEmails[] = $email;
        $form = $crawler->filter('form')->form();
        $form['password_recovery[email]'] = $email;
        $this->client->submit($form, [], ['REMOTE_ADDR' => self::CLIENT_IP]);
    }

    private function existingUser(): AppUser
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $user = $entityManager->getRepository(AppUser::class)->findOneBy([]);
        if (!$user instanceof AppUser) {
            self::markTestSkipped('No AppUser in the application database.');
        }

        return $user;
    }
}
