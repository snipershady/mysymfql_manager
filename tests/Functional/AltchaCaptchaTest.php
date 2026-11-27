<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\Solution;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Security\AltchaCaptcha;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AltchaCaptchaTest extends WebTestCase
{
    public function testBothWidgetsUseASolvableChallenge(): void
    {
        $client = self::createClient();
        foreach (['/login', '/register'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form altcha-widget[challenge="/altcha_challenge"][language="it"]');
        }

        $client->request('GET', '/altcha_challenge');
        self::assertResponseIsSuccessful();
        $challenge = Challenge::fromArray($client->getInternalResponse()->toArray());
        $solution = (new Altcha())->solveChallenge(new SolveChallengeOptions($challenge, new Pbkdf2()));
        $this->assertInstanceOf(Solution::class, $solution);

        $captcha = self::getContainer()->get(AltchaCaptcha::class);
        $this->assertTrue($captcha->verify(new Payload($challenge, $solution)->toBase64()));
        $this->assertFalse($captcha->verify('malformed'));
    }
}
