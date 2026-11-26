<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppUser;
use App\EventListener\SchemaCsrfListener;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP-level checks of the CSRF protection on /schema/*.
 *
 * Only targets a server name that does not exist, so no request can reach a managed MySQL server.
 */
#[CoversClass(SchemaCsrfListener::class)]
final class SchemaCsrfTest extends WebTestCase
{
    private const string MISSING_SERVER = '__csrf_test_missing_server__';

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        $user = array_find(
            $entityManager->getRepository(AppUser::class)->findAll(),
            static fn (AppUser $u): bool => [] !== array_intersect(['ROLE_USER', 'ROLE_ADMIN'], $u->getRoles())
                && !\in_array('ROLE_DISABLED', $u->getRoles(), true)
        );
        if (!$user instanceof AppUser) {
            self::markTestSkipped('No enabled AppUser in the application database.');
        }

        $this->client->loginUser($user);
    }

    public function testPostWithoutTokenIsRejectedBeforeTheController(): void
    {
        $this->client->request('POST', '/schema/table-optimize', ['name' => self::MISSING_SERVER, 'db_name' => 'x', 'table' => 'y']);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertStringContainsString('CSRF', (string) $this->client->getResponse()->getContent());
    }

    public function testPostWithForgedTokenIsRejected(): void
    {
        $this->client->request('POST', '/schema/drop-database', ['name' => self::MISSING_SERVER, 'db_name' => 'x'], [], [
            'HTTP_X_CSRF_TOKEN' => 'forged',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testPostWithTheTokenRenderedInTheLayoutReachesTheController(): void
    {
        $crawler = $this->client->request('GET', '/schema/databases');
        $this->assertResponseIsSuccessful();
        $token = (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
        $this->assertNotSame('', $token);

        $this->client->request('POST', '/schema/table-optimize', ['name' => self::MISSING_SERVER, 'db_name' => 'x', 'table' => 'y'], [], [
            'HTTP_X_CSRF_TOKEN' => $token,
        ]);

        // CSRF passed; the access resolver then answers 404 for the unknown server.
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTableOptimizeNoLongerAcceptsGet(): void
    {
        $this->client->request('GET', '/schema/table-optimize', ['name' => self::MISSING_SERVER, 'db_name' => 'x', 'table' => 'y']);

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
