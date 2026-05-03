<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\AccountNotFoundException;
use App\Exception\InsufficientFundsException;
use App\Exception\IdempotencyKeyMismatchException;
use App\Exception\InvalidTransferException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 5]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $throwable = $event->getThrowable();
        if ($throwable instanceof HttpExceptionInterface) {
            return;
        }

        if ($throwable instanceof AccountNotFoundException) {
            $event->setResponse(new JsonResponse(
                ['error' => $throwable->getMessage()],
                Response::HTTP_NOT_FOUND,
            ));

            return;
        }

        if ($throwable instanceof InsufficientFundsException) {
            $event->setResponse(new JsonResponse(
                ['error' => $throwable->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            ));

            return;
        }

        if ($throwable instanceof InvalidTransferException) {
            $event->setResponse(new JsonResponse(
                ['error' => $throwable->getMessage()],
                Response::HTTP_BAD_REQUEST,
            ));

            return;
        }

        if ($throwable instanceof IdempotencyKeyMismatchException) {
            $event->setResponse(new JsonResponse(
                ['error' => $throwable->getMessage()],
                Response::HTTP_CONFLICT,
            ));

            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => 'Internal server error'],
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ));
    }
}
