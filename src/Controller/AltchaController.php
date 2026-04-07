<?php

namespace App\Controller;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\ChallengeOptions;
use AltchaOrg\Altcha\Hasher\Algorithm;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

final class AltchaController extends AbstractController
{
    #[Route('/altcha_challenge', name: 'app_altcha_challenge')]
    public function index(): JsonResponse
    {
        $epti = new EffectivePrimitiveTypeIdentifierService();
        $hmacKey = $epti->getTypedValueFromServer(needle: 'ALTCHAKEY', trim: true, forceString: true, sanitizeHtml: true);

        $altcha = new Altcha($hmacKey);
        $randomIntValue = random_int(75000, 150000);
        $options = new ChallengeOptions(
            algorithm: Algorithm::SHA512,
            maxNumber: $randomIntValue,
            expires: new \DateTimeImmutable()->add(new \DateInterval('PT2M')) // Expires in 2 min
        );
        $challenge = $altcha->createChallenge($options);

        // Convert to format compatible with the JavaScript widget
        // The widget expects "maxnumber" (lowercase) not "maxNumber"
        $challengeData = [
            'algorithm' => $challenge->algorithm,
            'challenge' => $challenge->challenge,
            'maxnumber' => $challenge->maxNumber, // Convert from camelCase to lowercase
            'salt' => $challenge->salt,
            'signature' => $challenge->signature,
        ];

        return $this->json($challengeData);
    }
}
