<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\SqlClientController;
use App\Entity\SqlClient;
use App\Form\SqlClientType;
use App\Tests\Fixtures\TestCatalog;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The stored server password is never rendered back and is changed only when a new one is typed.
 * Catalog changes are rolled back by dama/doctrine-test-bundle.
 */
#[CoversClass(SqlClientController::class)]
#[CoversClass(SqlClientType::class)]
final class SqlClientFormTest extends FunctionalTestCase
{
    private const string STORED = 'Stored-Secret-4f9c2a7e';

    private int $id;

    protected function setUp(): void
    {
        parent::setUp();
        $sqlClient = $this->sqlClient(TestCatalog::OTHER_SERVER);
        $sqlClient->setPassword(self::STORED);
        $this->entityManager()->flush();
        $this->id = (int) $sqlClient->getId();
        // As in production: the page loads the server from the database (decrypted by SqlClientListener).
        $this->entityManager()->clear();
        $this->loginAs(TestCatalog::ADMIN);
    }

    public function testEditPageNeverRendersTheStoredPassword(): void
    {
        $crawler = $this->client->request('GET', '/sqlclient/' . $this->id . '/edit');

        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString(self::STORED, (string) $this->client->getResponse()->getContent());
        $input = $crawler->filter('input[name="sql_client[password]"]');
        $this->assertSame('password', $input->attr('type'));
        $this->assertSame('', (string) $input->attr('value'));
        self::assertSelectorTextContains('body', 'Lascia vuoto per mantenere la password attuale');
    }

    public function testEmptyPasswordOnEditKeepsTheStoredOne(): void
    {
        $this->submitEdit(['sql_client[password]' => '', 'sql_client[host]' => 'db.example.test']);

        $reloaded = $this->reload();
        $this->assertSame('db.example.test', $reloaded->getHost());
        $this->assertSame(self::STORED, $reloaded->getPassword());
    }

    public function testTypedPasswordOnEditReplacesTheStoredOneVerbatimAndEncrypted(): void
    {
        $this->submitEdit(['sql_client[password]' => '  New Secret#1  ']);

        $this->assertSame('  New Secret#1  ', $this->reload()->getPassword());
        $raw = $this->entityManager()->getConnection()->fetchOne('SELECT password FROM sql_client WHERE id = ?', [$this->id]);
        $this->assertIsString($raw);
        $this->assertStringStartsWith('$enc$:', $raw);
    }

    public function testNewServerRequiresAPassword(): void
    {
        $crawler = $this->client->request('GET', '/sqlclient/new');
        $form = $crawler->selectButton('Salva')->count() > 0 ? $crawler->selectButton('Salva')->form() : $crawler->filter('form')->form();
        $form['sql_client[name]'] = 'no_password_srv';
        $form['sql_client[host]'] = 'db.example.test';
        $form['sql_client[username]'] = 'manager';
        $form['sql_client[password]'] = '';
        $form['sql_client[port]'] = '3306';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $this->assertSame(0, $this->entityManager()->getRepository(SqlClient::class)->count(['name' => 'no_password_srv']));
    }

    /**
     * @param array<string, string> $values
     */
    private function submitEdit(array $values): void
    {
        $crawler = $this->client->request('GET', '/sqlclient/' . $this->id . '/edit');
        $form = $crawler->filter('form[name="sql_client"]')->form();
        foreach ($values as $field => $value) {
            $form[$field] = $value;
        }
        $this->client->submit($form);
        self::assertResponseRedirects('/sqlclient', 303);
    }

    private function reload(): SqlClient
    {
        $this->entityManager()->clear();
        $sqlClient = $this->entityManager()->find(SqlClient::class, $this->id);
        $this->assertInstanceOf(SqlClient::class, $sqlClient);

        return $sqlClient;
    }
}
