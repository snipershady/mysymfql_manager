<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\AuditActionEnum;
use App\Enum\AuditOutcomeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(AuditOutcomeEnum::class)]
#[CoversClass(AuditActionEnum::class)]
final class AuditOutcomeEnumTest extends TestCase
{
    /**
     * @return iterable<string, array{Response, AuditOutcomeEnum, ?string}>
     */
    public static function responses(): iterable
    {
        yield 'json ok' => [new JsonResponse(['is_valid' => true, 'message' => 'Tabella eliminata.']), AuditOutcomeEnum::SUCCESS, 'Tabella eliminata.'];
        yield 'json without is_valid' => [new JsonResponse(['data' => []]), AuditOutcomeEnum::SUCCESS, null];
        yield 'json is_valid false' => [new JsonResponse(['is_valid' => false, 'message' => 'ERROR 1146 (42S02): x']), AuditOutcomeEnum::FAILURE, 'ERROR 1146 (42S02): x'];
        yield 'query error' => [new JsonResponse(['is_valid' => false, 'message' => 'ERROR 1064'], Response::HTTP_BAD_REQUEST), AuditOutcomeEnum::FAILURE, 'ERROR 1064'];
        yield 'mysql error' => [new JsonResponse(['is_valid' => false, 'message' => 'ERROR 1451'], Response::HTTP_INTERNAL_SERVER_ERROR), AuditOutcomeEnum::FAILURE, 'ERROR 1451'];
        yield 'not found' => [new JsonResponse(['error' => 'Server non trovato.'], Response::HTTP_NOT_FOUND), AuditOutcomeEnum::DENIED, 'Server non trovato.'];
        yield 'forbidden' => [new JsonResponse(['is_valid' => false], Response::HTTP_FORBIDDEN), AuditOutcomeEnum::DENIED, null];
        yield 'conflict' => [new JsonResponse(['is_valid' => false, 'message' => 'Esiste già'], Response::HTTP_CONFLICT), AuditOutcomeEnum::DENIED, 'Esiste già'];
        yield 'form redirect' => [new RedirectResponse('/admin', Response::HTTP_SEE_OTHER), AuditOutcomeEnum::SUCCESS, null];
        yield 'invalid form' => [new Response('<html>', Response::HTTP_UNPROCESSABLE_ENTITY), AuditOutcomeEnum::INVALID, null];
        yield 'error page' => [new Response('<html>', Response::HTTP_INTERNAL_SERVER_ERROR), AuditOutcomeEnum::FAILURE, null];
        yield 'json not decodable' => [JsonResponse::fromJsonString('[1, 2]'), AuditOutcomeEnum::SUCCESS, null];
    }

    #[DataProvider('responses')]
    public function testOutcomeAndMessageFromResponse(Response $response, AuditOutcomeEnum $outcome, ?string $message): void
    {
        $this->assertSame($outcome, AuditOutcomeEnum::fromResponse($response));
        $this->assertSame($message, AuditOutcomeEnum::messageOf($response));
    }

    public function testEveryCaseHasALabel(): void
    {
        foreach (AuditOutcomeEnum::cases() as $case) {
            $this->assertNotSame('', $case->label());
            $this->assertNotSame('', $case->color());
        }

        foreach (AuditActionEnum::cases() as $case) {
            $this->assertNotSame('', $case->label());
        }
    }
}
