<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\SchemaCsrfListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[CoversClass(SchemaCsrfListener::class)]
final class SchemaCsrfListenerTest extends TestCase
{
    private const string VALID = 'valid-token';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ignoredRequests(): iterable
    {
        yield 'GET on schema' => ['GET', '/schema/table-show-create'];
        yield 'HEAD on schema' => ['HEAD', '/schema/databases'];
        yield 'POST outside schema' => ['POST', '/sqlclient/new'];
        yield 'POST on a lookalike prefix' => ['POST', '/schema-other/x'];
    }

    #[DataProvider('ignoredRequests')]
    public function testRequestsOutsideTheScopeAreLeftUntouched(string $method, string $path): void
    {
        $event = $this->dispatch(Request::create($path, $method));

        $this->assertFalse($event->hasResponse());
    }

    public function testSubRequestsAreLeftUntouched(): void
    {
        $event = $this->dispatch(Request::create('/schema/drop-database', 'POST'), HttpKernelInterface::SUB_REQUEST);

        $this->assertFalse($event->hasResponse());
    }

    public function testPostWithValidTokenPasses(): void
    {
        $request = Request::create('/schema/drop-database', 'POST');
        $request->headers->set(SchemaCsrfListener::HEADER, self::VALID);

        $this->assertFalse($this->dispatch($request)->hasResponse());
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function rejectedRequests(): iterable
    {
        yield 'POST without header' => ['POST', null];
        yield 'POST with empty header' => ['POST', ''];
        yield 'POST with wrong token' => ['POST', 'forged'];
        yield 'DELETE with wrong token' => ['DELETE', 'forged'];
    }

    #[DataProvider('rejectedRequests')]
    public function testStateChangingRequestWithoutValidTokenIsRejected(string $method, ?string $token): void
    {
        $request = Request::create('/schema/table-optimize', $method);
        if (null !== $token) {
            $request->headers->set(SchemaCsrfListener::HEADER, $token);
        }

        $response = $this->dispatch($request)->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertStringContainsString('"is_valid":false', (string) $response->getContent());
    }

    private function dispatch(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $manager = $this->createStub(CsrfTokenManagerInterface::class);
        $manager->method('isTokenValid')->willReturnCallback(
            static fn (CsrfToken $t): bool => SchemaCsrfListener::TOKEN_ID === $t->getId() && self::VALID === $t->getValue()
        );

        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
        new SchemaCsrfListener($manager)($event);

        return $event;
    }
}
