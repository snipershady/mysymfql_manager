<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The UI is in Italian (framework.default_locale: it): Symfony's own security and validation messages
 * are translated by the framework, the application messages are written in Italian.
 */
#[CoversNothing]
final class LocaleTest extends FunctionalTestCase
{
    public function testFrameworkMessagesAreItalian(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $this->assertInstanceOf(TranslatorInterface::class, $translator);

        $this->assertSame('it', $translator->getLocale());
        $this->assertSame('Credenziali non valide.', $translator->trans('Invalid credentials.', [], 'security'));
        $this->assertStringStartsWith('Questo valore non dovrebbe essere vuoto', $translator->trans('This value should not be blank.', [], 'validators'));
    }

    public function testLoginErrorIsShownInItalian(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form')->form();
        $form['_username'] = 'nobody';
        $form['_password'] = 'wrong';
        $this->client->submit($form);
        $this->client->followRedirect();

        // Unknown user: Symfony's own "Invalid credentials." rendered through the security translation domain.
        self::assertSelectorTextContains('.alert-danger', 'Credenziali non valide.');
        self::assertSelectorExists('html[lang="it"]');
    }
}
