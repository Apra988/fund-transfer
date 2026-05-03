<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Demo / debugging: exposes who Symfony Security resolved for this request (JWT sub, api-key-client, or anonymous).
 */
final class AuthStatusController
{
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function __invoke(Security $security): JsonResponse
    {
        $user = $security->getUser();
        if (!$user instanceof ApiUser) {
            return new JsonResponse([
                'subject' => null,
                'roles' => [],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'subject' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
        ]);
    }
}
