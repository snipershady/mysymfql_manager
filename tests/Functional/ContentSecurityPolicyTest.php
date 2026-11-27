<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Fixtures\TestCatalog;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Security headers (NelmioSecurityBundle, config/packages/nelmio_security.yaml): strict CSP with a
 * per-request nonce carried by the inline import map, no framing, no MIME sniffing.
 * The browser side (no violations on any page) is covered by tests/browser/smoke.mjs.
 */
#[CoversNothing]
final class ContentSecurityPolicyTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function pages(): iterable
    {
        yield 'login (anonymous)' => ['/login', null];
        yield 'dashboard' => ['/', TestCatalog::ADMIN];
        yield 'databases' => ['/schema/databases', TestCatalog::OWNER];
    }

    #[DataProvider('pages')]
    public function testStrictPolicyWithTheNonceOfTheInlineImportMap(string $path, ?string $user): void
    {
        if (null !== $user) {
            $this->loginAs($user);
        }
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        $csp = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');
        foreach (["default-src 'self'", "object-src 'none'", "frame-ancestors 'none'", "base-uri 'self'", "form-action 'self'"] as $directive) {
            self::assertStringContainsString($directive, $csp);
        }
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        self::assertDoesNotMatchRegularExpression('#https?://#', $csp, 'No external host may be allowed.');

        self::assertMatchesRegularExpression("/script-src 'self' 'nonce-([^']+)'/", $csp);
        preg_match("/script-src 'self' 'nonce-([^']+)'/", $csp, $matches);
        self::assertSame($matches[1], $crawler->filter('script[type="importmap"]')->attr('nonce'));
        self::assertSame($matches[1], $crawler->filter('meta[name="csp-nonce"]')->attr('content'));

        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        // No inline script/style left in the rendered page, apart from the nonce-carrying import map.
        self::assertCount(0, $crawler->filter('script:not([nonce])'));
        self::assertCount(0, $crawler->filter('[style]'));
    }
}
