<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TransferRequestDto;
use App\Service\FundTransferService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class TransferController
{
    public function __construct(
        private FundTransferService $fundTransferService,
        private ValidatorInterface $validator,
        private SerializerInterface $serializer,
        private Security $security,
        #[Autowire(service: 'limiter.transfer_write')]
        private RateLimiterFactory $transferLimiter,
    ) {
    }

    #[Route('/api/transfers', name: 'api_transfer_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $content = $request->getContent();
        if ('' === $content) {
            return new JsonResponse(['error' => 'Empty body'], Response::HTTP_BAD_REQUEST);
        }

        try {
            /** @var TransferRequestDto $dto */
            $dto = $this->serializer->deserialize($content, TransferRequestDto::class, 'json');
        } catch (NotEncodableValueException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }
        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[$v->getPropertyPath()] = $v->getMessage();
            }

            return new JsonResponse(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $limiter = $this->transferLimiter->create($request->getClientIp() ?? 'anon');
        if (false === $limiter->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException(retryAfter: 60);
        }

        $idempotencyKey = $request->headers->get('Idempotency-Key');
        if (null !== $idempotencyKey && strlen($idempotencyKey) > 255) {
            return new JsonResponse(['error' => 'Idempotency-Key too long'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->security->getUser();
        $idempotencyOwner = null !== $user ? $user->getUserIdentifier() : 'anonymous';

        $result = $this->fundTransferService->transfer(
            $dto->fromAccountId,
            $dto->toAccountId,
            $dto->amountMinor,
            $idempotencyKey,
            $idempotencyOwner,
        );

        return new JsonResponse($result->toArray(), Response::HTTP_CREATED);
    }
}
