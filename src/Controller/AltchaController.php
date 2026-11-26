<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\AltchaCaptcha;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AltchaController extends AbstractController
{
    #[Route(path: '/altcha_challenge', name: 'app_altcha_challenge')]
    public function index(AltchaCaptcha $captcha): JsonResponse
    {
        return $this->json($captcha->challenge()->toArray());
    }
}
