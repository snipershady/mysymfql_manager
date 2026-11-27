<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\RegistrationController;
use App\Tests\Fixtures\TestCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turbo Drive only renders a form response when it is a redirect or a 422: an invalid form
 * must be answered with 422 Unprocessable Content.
 */
#[CoversClass(RegistrationController::class)]
final class ChangePasswordTest extends FunctionalTestCase
{
    public function testInvalidFormIsAnsweredWith422(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $crawler = $this->client->request('GET', '/change-password');
        $form = $crawler->filter('form[name="change_password"]')->form();
        $form['change_password[oldPlainPassword]'] = TestCatalog::PASSWORD;
        $form['change_password[newPlainPassword][first]'] = 'New-passw0rd-1';
        $form['change_password[newPlainPassword][second]'] = 'Different-passw0rd';

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('body', 'the passwords do not match');
    }
}
