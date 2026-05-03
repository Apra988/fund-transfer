<?php

declare(strict_types=1);

namespace App\Security;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Stateless API auth: optional HS256 JWT (Authorization: Bearer) or X-API-Key when configured.
 * If neither JWT_SECRET_KEY nor API_KEY is set, requests are accepted as anonymous (local dev parity).
 */
final class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const ROLE_TRANSFER = 'ROLE_TRANSFER';

    private const ALG = 'HS256';

    public function __construct(
        private readonly string $jwtSecretKey,
        private readonly ?string $apiKey,
        private readonly bool $publicApiAllowAnonymous,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return false;
        }
        if ('/api/health' === $request->getPathInfo()) {
            return false;
        }

        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $jwtSecret = trim($this->jwtSecretKey);
        $apiKeyConfigured = '' !== trim((string) $this->apiKey);
        $authConfigured = '' !== $jwtSecret || $apiKeyConfigured;

        if (!$authConfigured) {
            return $this->anonymousPassport();
        }

        $authHeader = $request->headers->get('Authorization', '');
        if ('' !== $jwtSecret && preg_match('/^Bearer\s+(.+)/i', $authHeader, $m)) {
            $token = trim($m[1]);
            if ('' !== $token) {
                JWT::$leeway = 60;
                try {
                    $decoded = JWT::decode($token, new Key($jwtSecret, self::ALG));
                    /** @phpstan-ignore-next-line */
                    $subject = isset($decoded->sub) ? (string) $decoded->sub : '';
                    if ('' === $subject) {
                        throw new AuthenticationException('JWT missing sub claim');
                    }
                    /** @phpstan-ignore-next-line */
                    $roles = isset($decoded->roles) && \is_array($decoded->roles) ? $decoded->roles : [];

                    /** @var list<string> $symfonyRoles */
                    $symfonyRoles = [];
                    foreach ($roles as $r) {
                        if (\is_string($r) && '' !== $r) {
                            $symfonyRoles[] = str_starts_with($r, 'ROLE_') ? $r : 'ROLE_'.$r;
                        }
                    }
                    $symfonyRoles[] = self::ROLE_TRANSFER;

                    return new SelfValidatingPassport(
                        new UserBadge($subject, static fn (): ApiUser => new ApiUser($subject, array_values(array_unique($symfonyRoles)))),
                    );
                } catch (ExpiredException) {
                    throw new AuthenticationException('JWT expired');
                } catch (AuthenticationException $jwtAuthException) {
                    throw $jwtAuthException;
                } catch (\Throwable) {
                    // Bearer present but unusable → allow X-API-Key fallback when configured
                }
            }
        }

        $headerApiKey = (string) $request->headers->get('X-API-Key', '');
        $expectedApiKey = (string) $this->apiKey;
        if ($apiKeyConfigured && '' !== $expectedApiKey && hash_equals($expectedApiKey, $headerApiKey)) {
            return new SelfValidatingPassport(
                new UserBadge('api-key-client', fn (): ApiUser => new ApiUser('api-key-client', [self::ROLE_TRANSFER])),
            );
        }

        if ($this->publicApiAllowAnonymous) {
            return $this->anonymousPassport();
        }

        throw new AuthenticationException('Missing or invalid API credentials');
    }

    /**
     * @return SelfValidatingPassport
     */
    private function anonymousPassport(): Passport
    {
        return new SelfValidatingPassport(
            new UserBadge('anonymous', fn (): ApiUser => new ApiUser('anonymous', [self::ROLE_TRANSFER])),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['error' => $exception->getMessage() ?: 'Unauthorized'],
            Response::HTTP_UNAUTHORIZED,
        );
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(
            ['error' => 'Authentication required', 'hint' => 'Provide Authorization: Bearer <JWT> or X-API-Key when API authentication is configured.'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer realm="fund-transfer-api"'],
        );
    }
}
