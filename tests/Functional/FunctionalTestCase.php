<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Tests\Fixtures\TestCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base for HTTP tests against the dedicated test catalog ({@see TestCatalog}).
 * Catalog writes are rolled back after each test by dama/doctrine-test-bundle;
 * effects on the managed MySQL server are NOT, and must be cleaned up by the test.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function loginAs(string $username): AppUser
    {
        $user = $this->entityManager()->getRepository(AppUser::class)->findOneBy(['username' => $username]);
        $this->assertInstanceOf(AppUser::class, $user, 'Fixture user ' . $username . ' is missing.');
        $this->client->loginUser($user);

        return $user;
    }

    /**
     * CSRF token for the /schema AJAX endpoints, read from the admin layout like the browser does.
     */
    protected function schemaCsrfToken(): string
    {
        $crawler = $this->client->request('GET', '/schema/databases');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    /**
     * @param array<string, string|int> $parameters
     */
    protected function schemaPost(string $uri, array $parameters): void
    {
        $token = $this->schemaCsrfToken();
        $this->client->request('POST', $uri, $parameters, [], ['HTTP_X_CSRF_TOKEN' => $token]);
    }

    /**
     * @return array<mixed>
     */
    protected function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    protected function sqlClient(string $name): SqlClient
    {
        $sqlClient = $this->entityManager()->getRepository(SqlClient::class)->findOneBy(['name' => $name]);
        $this->assertInstanceOf(SqlClient::class, $sqlClient);

        return $sqlClient;
    }

    protected function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    protected static function env(string $name): string
    {
        $value = $_ENV[$name] ?? '';
        self::assertIsString($value);

        return $value;
    }
}
