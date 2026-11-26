<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Rebuilds the dedicated test catalog (APP_DB_NAME in .env.test) before the suite runs:
 * empties it, applies the versioned migrations, validates them against the mapping, then loads a fixed set of users,
 * servers and ownerships. Every test then runs in a transaction rolled back by
 * dama/doctrine-test-bundle, so this baseline is the same for all of them.
 *
 * Both managed servers point to the same MySQL instance (TEST_SERVER_*): what differs is who owns them.
 *
 * | user           | roles         | owns server   | owns database                  |
 * |----------------|---------------|---------------|--------------------------------|
 * | test_admin     | ROLE_ADMIN    | -             | -                              |
 * | test_owner     | ROLE_USER     | server_locale | unit_testing_one on server_locale |
 * | test_other     | ROLE_USER     | server_other  | unit_testing_two on server_other  |
 * | test_disabled  | ROLE_DISABLED | -             | -                              |
 */
final class TestCatalog
{
    public const string PASSWORD = 'Test-Passw0rd!';

    public const string ADMIN = 'test_admin';

    public const string OWNER = 'test_owner';

    public const string OTHER = 'test_other';

    public const string DISABLED = 'test_disabled';

    public const string OTHER_SERVER = 'server_other';

    public static function rebuild(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $entityManager = $container->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $database = (string) $entityManager->getConnection()->getDatabase();
        if (!str_ends_with($database, '_test')) {
            throw new \RuntimeException(\sprintf('Refusing to rebuild "%s": the test catalog name must end with "_test" (APP_DB_NAME in .env.test).', $database));
        }

        // Empty database, then the versioned migrations (the same path as a fresh install), then
        // a check that they produce exactly the entity mapping: a changed entity without its
        // migration fails the whole suite here.
        new SchemaTool($entityManager)->dropDatabase();
        self::console($kernel, ['command' => 'doctrine:migrations:migrate', '--no-interaction' => true, '--allow-no-migration' => true]);
        self::console($kernel, ['command' => 'doctrine:schema:validate']);

        $user = static function (string $username, string $role) use ($entityManager, $hasher): AppUser {
            $appUser = new AppUser()->setUsername($username)->setEmail($username . '@example.com')->setRoles([$role]);
            $appUser->setPassword($hasher->hashPassword($appUser, self::PASSWORD));
            $entityManager->persist($appUser);

            return $appUser;
        };
        $user(self::ADMIN, 'ROLE_ADMIN');
        $owner = $user(self::OWNER, 'ROLE_USER');
        $other = $user(self::OTHER, 'ROLE_USER');
        $user(self::DISABLED, 'ROLE_DISABLED');

        $server = static function (string $name, AppUser $serverOwner) use ($entityManager): SqlClient {
            $sqlClient = new SqlClient()
                ->setName($name)
                ->setHost(self::env('TEST_SERVER_HOST'))
                ->setPort((int) self::env('TEST_SERVER_PORT'))
                ->setUsername(self::env('TEST_SERVER_USER'))
                ->setPassword(self::env('TEST_SERVER_PASSWORD'))
                ->addOwner($serverOwner);
            $entityManager->persist($sqlClient);

            return $sqlClient;
        };
        $serverLocale = $server(self::env('TEST_SERVER_NAME'), $owner);
        $serverOther = $server(self::OTHER_SERVER, $other);

        $entityManager->persist(new DatabaseOwner()->setDbName(self::env('TEST_DB_NAME_ONE'))->setOwner($owner)->setSqlClient($serverLocale));
        $entityManager->persist(new DatabaseOwner()->setDbName(self::env('TEST_DB_NAME_TWO'))->setOwner($other)->setSqlClient($serverOther));

        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @param array<string, bool|string> $input
     */
    private static function console(KernelInterface $kernel, array $input): void
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        if (0 !== $application->run(new ArrayInput($input), $output)) {
            throw new \RuntimeException(\sprintf("Test catalog: \"%s\" failed:\n%s", $input['command'], $output->fetch()));
        }
    }

    private static function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        if (!\is_string($value) || '' === $value || 'CHANGE' === $value) {
            throw new \RuntimeException(\sprintf('%s must be set for the test suite (see .env.test / .env.test.local).', $name));
        }

        return $value;
    }
}
