<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\ResetPasswordController;
use App\Entity\AppUser;
use App\Security\PasswordReset\PasswordResetThrottle;
use App\Security\PasswordReset\PasswordResetTokenManager;
use App\Tests\Fixtures\TestCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * HTTP-level checks of the password-reset flow.
 *
 * Runs on the dedicated test catalog; changes are rolled back by dama/doctrine-test-bundle.
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

    protected function tearDown(): void
    {
        $throttle = self::getContainer()->get(PasswordResetThrottle::class);
        $this->assertInstanceOf(PasswordResetThrottle::class, $throttle);
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
        $this->assertInstanceOf(RawMessage::class, $email);
        $this->assertEmailHtmlBodyContains($email, '/reset-password/confirm?');
        $this->assertEmailHtmlBodyNotContains($email, 'Your new password');

        // Requesting a reset alone never changes the password.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
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
        $this->assertInstanceOf(PasswordResetTokenManager::class, $tokenManager);

        $this->client->request('GET', '/reset-password/confirm', $tokenManager->issue($user));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="reset_password_confirm[newPlainPassword][first]"]');
        $this->assertSelectorExists('meta[name="referrer"][content="no-referrer"]');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $user = $this->existingUser();
        $tokenManager = self::getContainer()->get(PasswordResetTokenManager::class);
        $this->assertInstanceOf(PasswordResetTokenManager::class, $tokenManager);
        $token = $tokenManager->issue($user);

        $this->client->request('GET', '/reset-password/confirm', [...$token, 'expires' => time() - 1]);

        $this->assertResponseRedirects('/reset-password');
    }

    public function testValidLinkChangesThePasswordOnceAndCannotBeReused(): void
    {
        $user = $this->existingUser();
        $tokenManager = self::getContainer()->get(PasswordResetTokenManager::class);
        $this->assertInstanceOf(PasswordResetTokenManager::class, $tokenManager);
        $url = '/reset-password/confirm?' . http_build_query($tokenManager->issue($user));

        $crawler = $this->client->request('GET', $url);
        $form = $crawler->filter('form')->form();
        $form['reset_password_confirm[newPlainPassword][first]'] = 'Brand-new-passw0rd';
        $form['reset_password_confirm[newPlainPassword][second]'] = 'Brand-new-passw0rd';
        $this->client->submit($form);

        $this->assertResponseRedirects('/login');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
        $reloaded = $entityManager->getRepository(AppUser::class)->find($user->getId());
        $this->assertInstanceOf(AppUser::class, $reloaded);
        $this->assertTrue($hasher->isPasswordValid($reloaded, 'Brand-new-passw0rd'));

        // Same link again: the password hash is part of the signature, so it is now invalid.
        $this->client->request('GET', $url);
        $this->assertResponseRedirects('/reset-password');
    }

    public function testMismatchedPasswordsAreRejectedAndTheLinkStaysValid(): void
    {
        $user = $this->existingUser();
        $passwordBefore = $user->getPassword();
        $tokenManager = self::getContainer()->get(PasswordResetTokenManager::class);
        $this->assertInstanceOf(PasswordResetTokenManager::class, $tokenManager);
        $url = '/reset-password/confirm?' . http_build_query($tokenManager->issue($user));

        $form = $this->client->request('GET', $url)->filter('form')->form();
        $form['reset_password_confirm[newPlainPassword][first]'] = 'Brand-new-passw0rd';
        $form['reset_password_confirm[newPlainPassword][second]'] = 'Different-passw0rd';
        $this->client->submit($form);

        // Invalid form re-rendered with its errors.
        $this->assertResponseStatusCodeSame(422);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
        $this->assertSame($passwordBefore, $entityManager->getRepository(AppUser::class)->find($user->getId())?->getPassword());
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
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $user = $entityManager->getRepository(AppUser::class)->findOneBy(['username' => TestCatalog::OWNER]);
        if (!$user instanceof AppUser) {
            self::markTestSkipped('No AppUser in the application database.');
        }

        return $user;
    }
}
