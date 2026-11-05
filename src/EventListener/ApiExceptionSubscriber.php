<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Api\ApiException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Traduit les exceptions des requêtes /api en réponses JSON du contrat docs/api.md :
 * 404 introuvable, 422 validation (avec violations), 400 JSON invalide, 409 conflit,
 * 500 générique sans détail hors debug.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', -64]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/') && '/api' !== $request->getPathInfo()) {
            return;
        }
        $throwable = $event->getThrowable();

        if ($throwable instanceof ApiException) {
            $event->setResponse($this->json($throwable->toPayload(), $throwable->status));

            return;
        }

        // Validation de #[MapRequestPayload] : 422 + violations [{field, message}].
        $validation = $throwable instanceof ValidationFailedException
            ? $throwable
            : ($throwable->getPrevious() instanceof ValidationFailedException ? $throwable->getPrevious() : null);
        if ($validation instanceof ValidationFailedException) {
            $violations = [];
            foreach ($validation->getViolations() as $violation) {
                $violations[] = [
                    'field' => $this->normalizeField((string) $violation->getPropertyPath()),
                    'message' => $violation->getMessage(),
                ];
            }
            $event->setResponse($this->json([
                'error' => 'validation_failed',
                'message' => 'Données invalides.',
                'violations' => $violations,
            ], Response::HTTP_UNPROCESSABLE_ENTITY));

            return;
        }

        if ($throwable instanceof UniqueConstraintViolationException) {
            $event->setResponse($this->json([
                'error' => 'conflict',
                'message' => 'Une ressource avec ces valeurs existe déjà.',
            ], Response::HTTP_CONFLICT));

            return;
        }

        if ($throwable instanceof BadRequestHttpException) {
            // JSON mal formé / propriété inconnue (MapRequestPayload).
            $event->setResponse($this->json([
                'error' => 'invalid_json',
                'message' => 'Corps de requête JSON invalide.',
            ], Response::HTTP_BAD_REQUEST));

            return;
        }

        if ($throwable instanceof AccessDeniedHttpException) {
            $event->setResponse($this->json([
                'error' => 'forbidden',
                'message' => 'Accès refusé.',
            ], Response::HTTP_FORBIDDEN));

            return;
        }

        if ($throwable instanceof NotFoundHttpException) {
            $event->setResponse($this->json([
                'error' => 'not_found',
                'message' => 'Ressource introuvable.',
            ], Response::HTTP_NOT_FOUND));

            return;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $event->setResponse($this->json([
                'error' => $this->errorCode($throwable->getStatusCode()),
                'message' => $throwable->getMessage() ?: Response::$statusTexts[$throwable->getStatusCode()] ?? 'Erreur.',
            ], $throwable->getStatusCode()));

            return;
        }

        // 500 générique : aucun détail en prod.
        $this->logger->error('API exception', ['exception' => $throwable]);
        $event->setResponse($this->json([
            'error' => 'internal_error',
            'message' => $this->debug ? $throwable->getMessage() : 'Une erreur interne est survenue.',
        ], Response::HTTP_INTERNAL_SERVER_ERROR));
    }

    /** "holderIds[0]" → "holderIds", "[0]" → champ racine. */
    private function normalizeField(string $propertyPath): string
    {
        $field = preg_replace('/\[.*$/u', '', $propertyPath) ?? $propertyPath;

        return '' === $field ? 'payload' : $field;
    }

    private function errorCode(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            415 => 'unsupported_media_type',
            422 => 'validation_failed',
            default => 'error_'.$status,
        };
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status): JsonResponse
    {
        return new JsonResponse($payload, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }
}
