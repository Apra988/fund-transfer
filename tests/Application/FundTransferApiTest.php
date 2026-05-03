<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Entity\Account;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class FundTransferApiTest extends ApiApplicationTestCase
{
    /** Mirrors `when@test.framework.rate_limiter.transfer_write.limit` for loop counts. */
    private const TRANSFER_POST_LIMIT_UNDER_TEST = 35;

    public function testHealth(): void
    {
        $client = static::createApiClient();
        $client->request('GET', '/api/health');
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $data['status']);
    }

    public function testMeReflectsAnonymousWhenAllowAnonymous(): void
    {
        $client = static::createApiClient();
        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('anonymous', $data['subject']);
        self::assertContains('ROLE_TRANSFER', $data['roles']);
    }

    public function testTransferMovesFunds(): void
    {
        $client = static::createApiClient();
        [$fromId, $toId] = $this->seedTwoAccounts($client, '10000', '5000');

        $client->request(
            'POST',
            '/api/transfers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'fromAccountId' => $fromId,
                'toAccountId' => $toId,
                'amountMinor' => '3000',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('3000', $payload['amountMinor']);

        $client->request('GET', '/api/accounts/'.$fromId);
        self::assertResponseIsSuccessful();
        $fromBalance = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('7000', $fromBalance['balanceMinor']);

        $client->request('GET', '/api/accounts/'.$toId);
        $toBalance = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('8000', $toBalance['balanceMinor']);
    }

    public function testInsufficientFunds(): void
    {
        $client = static::createApiClient();
        [$fromId, $toId] = $this->seedTwoAccounts($client, '100', '0');

        $client->request(
            'POST',
            '/api/transfers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'fromAccountId' => $fromId,
                'toAccountId' => $toId,
                'amountMinor' => '500',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testIdempotencyKeyReturnsSameTransfer(): void
    {
        $client = static::createApiClient();
        [$fromId, $toId] = $this->seedTwoAccounts($client, '10000', '100');

        $body = json_encode([
            'fromAccountId' => $fromId,
            'toAccountId' => $toId,
            'amountMinor' => '100',
        ], JSON_THROW_ON_ERROR);

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => 'idem-test-'.Uuid::v4()->toRfc4122(),
        ];

        $client->request('POST', '/api/transfers', server: $headers, content: $body);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/transfers', server: $headers, content: $body);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $second = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($first['transferId'], $second['transferId']);

        $client->request('GET', '/api/accounts/'.$fromId);
        $bal = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('9900', $bal['balanceMinor']);
    }

    public function testIdempotencyKeyMismatchedBodyReturnsConflict(): void
    {
        $client = static::createApiClient();
        [$fromId, $toId] = $this->seedTwoAccounts($client, '10000', '100');

        $idem = 'idem-mismatch-'.Uuid::v4()->toRfc4122();
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => $idem,
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

        $client->request(
            'POST',
            '/api/transfers',
            server: $headers,
            content: json_encode([
                'fromAccountId' => $fromId,
                'toAccountId' => $toId,
                'amountMinor' => '200',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $client->request('GET', '/api/accounts/'.$fromId);
        $bal = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('9900', $bal['balanceMinor'], 'Original transfer amount must remain applied once');
    }

    public function testGetAccountNotFound(): void
    {
        $client = static::createApiClient();
        $client->request('GET', '/api/accounts/'.Uuid::v4()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testPostTransferFromAccountNotFound(): void
    {
        $client = static::createApiClient();
        $toId = $this->seedAccount($client, '1000');

        $client->request(
            'POST',
            '/api/transfers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'fromAccountId' => Uuid::v4()->toRfc4122(),
                'toAccountId' => $toId,
                'amountMinor' => '100',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testPostTransferToAccountNotFound(): void
    {
        $client = static::createApiClient();
        $fromId = $this->seedAccount($client, '1000');

        $client->request(
            'POST',
            '/api/transfers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'fromAccountId' => $fromId,
                'toAccountId' => Uuid::v4()->toRfc4122(),
                'amountMinor' => '100',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testPostTransferEmptyBody(): void
    {
        $client = static::createApiClient();
        $client->request(
            'POST',
            '/api/transfers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $payload);
    }

    public function testPostTransferMalformedJson(): void
    {
        $client = static::createApiClient();
        $client->request(
            'POST',
            '/api/transfers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{not json',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Invalid JSON', $payload['error']);
    }

    public function testPostTransferValidationInvalidUuid(): void
    {
        $client = static::createApiClient();

        $client->request(
            'POST',
            '/api/transfers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'fromAccountId' => 'not-a-uuid',
                'toAccountId' => Uuid::v4()->toRfc4122(),
                'amountMinor' => '100',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('errors', $payload);
        self::assertArrayHasKey('fromAccountId', $payload['errors']);
    }

    public function testPostTransferValidationInvalidAmountMinor(): void
    {
        $client = static::createApiClient();

        $client->request(
            'POST',
            '/api/transfers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'fromAccountId' => Uuid::v4()->toRfc4122(),
                'toAccountId' => Uuid::v4()->toRfc4122(),
                'amountMinor' => '0',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('amountMinor', $payload['errors']);
    }

    public function testIdempotencyKeyTooLong(): void
    {
        $client = static::createApiClient();

        $client->request(
            'POST',
            '/api/transfers',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => str_repeat('a', 256),
            ],
            content: json_encode([
                'fromAccountId' => Uuid::v4()->toRfc4122(),
                'toAccountId' => Uuid::v4()->toRfc4122(),
                'amountMinor' => '1',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testPostTransferReturns429AfterRateLimiterBudgetExceeded(): void
    {
        $limit = self::TRANSFER_POST_LIMIT_UNDER_TEST;

        $client = static::createApiClient();
        $client->setServerParameter('REMOTE_ADDR', '198.51.100.123');
        $server = ['CONTENT_TYPE' => 'application/json'];
        $budgetBody = json_encode([
            'fromAccountId' => Uuid::v4()->toRfc4122(),
            'toAccountId' => Uuid::v4()->toRfc4122(),
            'amountMinor' => '1',
        ], JSON_THROW_ON_ERROR);
        for ($i = 0; $i < $limit; ++$i) {
            $client->request('POST', '/api/transfers', server: $server, content: $budgetBody);
            self::assertNotSame(Response::HTTP_TOO_MANY_REQUESTS, $client->getResponse()->getStatusCode(), 'iteration '.$i);
        }
        $client->request('POST', '/api/transfers', server: $server, content: $budgetBody);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertResponseHasHeader('Retry-After');
    }

    public function testRateLimiterIsScopedPerSourceIp(): void
    {
        $limit = self::TRANSFER_POST_LIMIT_UNDER_TEST;

        $client = static::createApiClient();
        $client->setServerParameter('REMOTE_ADDR', '203.0.113.91');
        $exhaust = ['CONTENT_TYPE' => 'application/json'];
        $budgetBody = json_encode([
            'fromAccountId' => Uuid::v4()->toRfc4122(),
            'toAccountId' => Uuid::v4()->toRfc4122(),
            'amountMinor' => '1',
        ], JSON_THROW_ON_ERROR);
        for ($i = 0; $i < $limit; ++$i) {
            $client->request('POST', '/api/transfers', server: $exhaust, content: $budgetBody);
        }
        $client->request('POST', '/api/transfers', server: $exhaust, content: $budgetBody);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $client->setServerParameter('REMOTE_ADDR', '203.0.113.92');
        $freshIp = ['CONTENT_TYPE' => 'application/json'];
        $client->request('POST', '/api/transfers', server: $freshIp, content: $budgetBody);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $payload);
    }

    public function testManySequentialTransfersPreserveTotalBalance(): void
    {
        $client = static::createApiClient();
        [$fromId, $toId] = $this->seedTwoAccounts($client, '100000', '50000');

        for ($n = 0; $n < 10; ++$n) {
            $client->request(
                'POST',
                '/api/transfers',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode([
                    'fromAccountId' => $fromId,
                    'toAccountId' => $toId,
                    'amountMinor' => '250',
                ], JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'iteration '.$n);
        }

        $client->request('GET', '/api/accounts/'.$fromId);
        $fromBal = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['balanceMinor'];
        $client->request('GET', '/api/accounts/'.$toId);
        $toBal = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['balanceMinor'];

        self::assertSame('97500', $fromBal);
        self::assertSame('52500', $toBal);
        self::assertSame('150000', bcadd($fromBal, $toBal, 0));
    }

    public function testHealthIsNotAffectedByTransferWriteRateLimit(): void
    {
        $limit = self::TRANSFER_POST_LIMIT_UNDER_TEST;

        $client = static::createApiClient();
        $client->setServerParameter('REMOTE_ADDR', '198.51.100.201');
        $server = ['CONTENT_TYPE' => 'application/json'];
        $budgetBody = json_encode([
            'fromAccountId' => Uuid::v4()->toRfc4122(),
            'toAccountId' => Uuid::v4()->toRfc4122(),
            'amountMinor' => '1',
        ], JSON_THROW_ON_ERROR);
        for ($i = 0; $i < $limit; ++$i) {
            $client->request('POST', '/api/transfers', server: $server, content: $budgetBody);
        }
        $client->request('POST', '/api/transfers', server: $server, content: $budgetBody);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $client->request('GET', '/api/health');
        self::assertResponseIsSuccessful();
    }

    public function testValidationErrorForSameAccount(): void
    {
        $client = static::createApiClient();
        $id = Uuid::v4()->toRfc4122();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Account($id, '1000'));
        $em->flush();

        $client->request(
            'POST',
            '/api/transfers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'fromAccountId' => $id,
                'toAccountId' => $id,
                'amountMinor' => '100',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    private function seedAccount(KernelBrowser $client, string $balance): string
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $account = new Account(Uuid::v4()->toRfc4122(), $balance);
        $em->persist($account);
        $em->flush();

        return $account->getPublicId();
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
