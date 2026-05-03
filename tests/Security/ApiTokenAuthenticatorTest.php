<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ApiTokenAuthenticator;
use App\Security\ApiUser;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class ApiTokenAuthenticatorTest extends TestCase
{
    private const JWT_SECRET_AT_LEAST_32 = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /** @throws \Firebase\JWT\JWTExceptionWithPayloadInterface|\Throwable */
    private static function bearerRequest(string $path, string $token): Request
    {
        return Request::create($path, 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
    }

    public function testNoSecretsAllowsAnonymousSubject(): void
    {
        $auth = new ApiTokenAuthenticator('', '', false);
        $passport = $auth->authenticate(Request::create('/api/transfers', 'POST'));

        $badge = $this->extractUserBadge($passport);

        /** @phpstan-ignore-next-line */
        $user = ($badge->getUserLoader())('anonymous');
        self::assertInstanceOf(ApiUser::class, $user);
        self::assertSame('anonymous', $user->getUserIdentifier());
        self::assertContains(ApiTokenAuthenticator::ROLE_TRANSFER, $user->getRoles());
    }

    public function testJwtWithSubAuthenticatesCaller(): void
    {
        $now = \time();
        $token = JWT::encode([
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'sub' => 'tenant-acme',
        ], self::JWT_SECRET_AT_LEAST_32, 'HS256');

        $auth = new ApiTokenAuthenticator(self::JWT_SECRET_AT_LEAST_32, '', false);
        $badge = $this->extractUserBadge($auth->authenticate(self::bearerRequest('/api/accounts/x', $token)));

        /** @phpstan-ignore-next-line */
        $user = ($badge->getUserLoader())('tenant-acme');
        self::assertSame('tenant-acme', $user->getUserIdentifier());
        self::assertSame([ApiTokenAuthenticator::ROLE_TRANSFER], $user->getRoles());
    }

    public function testJwtRolesClaimMappedToSymfonyRoles(): void
    {
        $now = \time();
        $token = JWT::encode([
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'sub' => 'u1',
            'roles' => ['SUPPORT'],
        ], self::JWT_SECRET_AT_LEAST_32, 'HS256');

        $auth = new ApiTokenAuthenticator(self::JWT_SECRET_AT_LEAST_32, '', false);
        $badge = $this->extractUserBadge($auth->authenticate(self::bearerRequest('/api/transfers', $token)));
        /** @phpstan-ignore-next-line */
        $user = ($badge->getUserLoader())('u1');

        self::assertContains('ROLE_SUPPORT', $user->getRoles());
        self::assertContains(ApiTokenAuthenticator::ROLE_TRANSFER, $user->getRoles());
    }

    public function testExpiredJwtFails(): void
    {
        $now = \time() - 7200;
        $token = JWT::encode([
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 60,
            'sub' => 'tenant',
        ], self::JWT_SECRET_AT_LEAST_32, 'HS256');

        $auth = new ApiTokenAuthenticator(self::JWT_SECRET_AT_LEAST_32, '', false);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('JWT expired');
        $auth->authenticate(self::bearerRequest('/api/transfers', $token));
    }

    public function testCorruptJwtFallsBackToApiKeyWhenBothConfigured(): void
    {
        $auth = new ApiTokenAuthenticator(self::JWT_SECRET_AT_LEAST_32, 'my-secret-api-key-value', false);
        $request = Request::create('/api/transfers', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer not.a.valid.token',
            'HTTP_X_API_KEY' => 'my-secret-api-key-value',
        ]);

        $badge = $this->extractUserBadge($auth->authenticate($request));
        /** @phpstan-ignore-next-line */
        $user = ($badge->getUserLoader())('api-key-client');
        self::assertSame('api-key-client', $user->getUserIdentifier());
        self::assertContains(ApiTokenAuthenticator::ROLE_TRANSFER, $user->getRoles());
    }

    public function testAuthConfiguredAndPublicRejectedWithoutCredentials(): void
    {
        $auth = new ApiTokenAuthenticator(self::JWT_SECRET_AT_LEAST_32, '', false);

        $this->expectException(AuthenticationException::class);
        $auth->authenticate(Request::create('/api/transfers', 'POST'));
    }

    public function testPublicBypassWhenJwtConfiguredButFlagTrue(): void
    {
        $auth = new ApiTokenAuthenticator(self::JWT_SECRET_AT_LEAST_32, '', true);
        $badge = $this->extractUserBadge($auth->authenticate(Request::create('/api/transfers', 'POST')));
        /** @phpstan-ignore-next-line */
        $user = ($badge->getUserLoader())('anonymous');
        self::assertSame('anonymous', $user->getUserIdentifier());
    }

    public function testWrongApiKeyIsRejected(): void
    {
        $auth = new ApiTokenAuthenticator('', 'exact-secret-api-key-value', false);
        $request = Request::create('/api/transfers', 'POST', [], [], [], [
            'HTTP_X_API_KEY' => 'exact-secret-api-key-valueX',
        ]);

        $this->expectException(AuthenticationException::class);
        $auth->authenticate($request);
    }

    public function testSupportsFalseForHealthPath(): void
    {
        $auth = new ApiTokenAuthenticator(self::JWT_SECRET_AT_LEAST_32, 'k', false);
        self::assertFalse($auth->supports(Request::create('/api/health', 'GET')));
    }

    public function testJwtMissingSubThrows(): void
    {
        $now = \time();
        $token = JWT::encode([
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
        ], self::JWT_SECRET_AT_LEAST_32, 'HS256');

        $auth = new ApiTokenAuthenticator(self::JWT_SECRET_AT_LEAST_32, '', false);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('JWT missing sub claim');
        $auth->authenticate(self::bearerRequest('/api/transfers', $token));
    }

    private function extractUserBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Passport $passport): UserBadge
    {
        foreach ($passport->getBadges() as $badge) {
            if ($badge instanceof UserBadge) {
                /** @phpstan-ignore-next-line */
                return $badge;
            }
        }

        throw new \RuntimeException('Missing UserBadge');
    }
}
