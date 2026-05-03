<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * When API_KEY is set in the environment, require matching X-API-Key for /api/* routes.
 * Leave API_KEY unset locally for friction-free development.
 */
final class ApiKeySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ?string $apiKey,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 120]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ('' === (string) $this->apiKey) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        if ($request->getPathInfo() === '/api/health') {
            return;
        }

        if ($request->headers->get('X-API-Key') !== $this->apiKey) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Unauthorized'],
                Response::HTTP_UNAUTHORIZED,
            ));
        }
    }
}
