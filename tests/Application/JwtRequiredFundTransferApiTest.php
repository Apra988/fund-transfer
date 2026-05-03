<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Entity\Account;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Runs only with {@see phpunit.jwt-required.xml.dist}: JWT enforced, PUBLIC_API_ALLOW_ANONYMOUS=0.
 *
 * Default {@see phpunit.xml.dist} excludes this group because it cannot resolve two conflicting env payloads in one run.
 */
#[Group('jwt-integration')]
final class JwtRequiredFundTransferApiTest extends ApiApplicationTestCase
{
    /** Keep in sync with `JWT_SECRET_KEY` in phpunit.jwt-required.xml.dist */
    private const JWT_TEST_SECRET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private static function mintJwtBearer(string $sub): string
    {
        $now = \time();

        return 'Bearer '.JWT::encode([
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'sub' => $sub,
        ], self::JWT_TEST_SECRET, 'HS256');
    }

    /** @return array<string, string> */
    private function authServer(string $sub = 'integration-client-1'): array
    {
        return ['HTTP_AUTHORIZATION' => self::mintJwtBearer($sub)];
    }

    public function testHealthBypassesJwt(): void
    {
        $client = static::createApiClient();
        $client->request('GET', '/api/health');
        self::assertResponseIsSuccessful();
    }

    public function testMeWithoutJwtReturns401(): void
    {
        $client = static::createApiClient();
        $client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testMeReturnsJwtSubject(): void
    {
        $client = static::createApiClient();
        $client->request('GET', '/api/me', server: $this->authServer('demo-tenant'));

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('demo-tenant', $data['subject']);
        self::assertContains('ROLE_TRANSFER', $data['roles']);
    }

    public function testUnauthorizedWithoutJwtOnProtectedRoute(): void
    {
        $client = static::createApiClient();
        $client->request('GET', '/api/accounts/'.Uuid::v4()->toRfc4122());
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTransferSucceedsWithValidJwtBearer(): void
    {
        $client = static::createApiClient();
        [$fromId, $toId] = $this->seedTwoAccounts($client, '5000', '1000');

        $headers = [
            ...$this->authServer(),
            'CONTENT_TYPE' => 'application/json',
        ];

        $client->request(
            'POST',
            '/api/transfers',
            server: $headers,
            content: json_encode([
                'fromAccountId' => $fromId,
                'toAccountId' => $toId,
                'amountMinor' => '100',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->request('GET', '/api/accounts/'.$fromId, server: $this->authServer());
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $fromBal = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('4900', $fromBal['balanceMinor']);
    }

    public function testIdempotencyScopesPerJwtSubject(): void
    {
        $client = static::createApiClient();
        [$fromId, $toId] = $this->seedTwoAccounts($client, '10000', '100');

        $body = json_encode([
            'fromAccountId' => $fromId,
            'toAccountId' => $toId,
            'amountMinor' => '50',
        ], JSON_THROW_ON_ERROR);

        $headersA = [...$this->authServer('partner-a'), 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'same-visible-key'];

        $client->request('POST', '/api/transfers', server: $headersA, content: $body);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $headersB = [...$this->authServer('partner-b'), 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'same-visible-key'];

        $client->request('POST', '/api/transfers', server: $headersB, content: $body);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $client->request('GET', '/api/accounts/'.$fromId, server: $this->authServer('partner-a'));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $balance = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['balanceMinor'];
        // Two distinct callers may reuse the same visible Idempotency-Key without colliding in storage.
        self::assertSame('9900', $balance);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function seedTwoAccounts(
        KernelBrowser $client,
        string $fromBalance,
        string $toBalance,
    ): array {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $from = new Account(Uuid::v4()->toRfc4122(), $fromBalance);
        $to = new Account(Uuid::v4()->toRfc4122(), $toBalance);
        $em->persist($from);
        $em->persist($to);
        $em->flush();

        return [$from->getPublicId(), $to->getPublicId()];
    }
}
