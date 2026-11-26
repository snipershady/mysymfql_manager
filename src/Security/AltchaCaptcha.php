<?php

declare(strict_types=1);

namespace App\Security;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class AltchaCaptcha
{
    private Altcha $altcha;

    public function __construct(#[Autowire(env: 'ALTCHAKEY')] #[\SensitiveParameter] string $key)
    {
        $this->altcha = new Altcha($key);
    }

    public function challenge(): Challenge
    {
        return $this->altcha->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2(),
            cost: 5000,
            expiresAt: new \DateTimeImmutable('+2 minutes'),
        ));
    }

    public function verify(string $payload): bool
    {
        if (\strlen($payload) > 4096) {
            return false;
        }

        try {
            $solution = Payload::fromBase64($payload);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $this->altcha->verifySolution(new VerifySolutionOptions(payload: $solution, algorithm: new Pbkdf2()))->verified;
    }
}
