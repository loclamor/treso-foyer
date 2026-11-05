<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Exige Content-Type: application/json sur les écritures /api avec corps
 * (POST/PATCH/PUT/DELETE), sauf l'upload multipart de POST /api/imports ⇒ sinon 415.
 */
final class JsonContentTypeListener implements EventSubscriberInterface
{
    private const WRITE_METHODS = ['POST', 'PATCH', 'PUT', 'DELETE'];

    public static function getSubscribedEvents(): array
    {
        // Avant le firewall (8) pour maîtriser le format du 415, après le router (32).
        return [KernelEvents::REQUEST => ['onKernelRequest', 16]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest()) {
            return;
        }
        if (!str_starts_with($request->getPathInfo(), '/api/') && '/api' !== $request->getPathInfo()) {
            return;
        }
        if (!in_array($request->getMethod(), self::WRITE_METHODS, true)) {
            return;
        }
        if ('' === trim($request->getContent())) {
            return; // pas de corps ⇒ rien à contrôler
        }
        // Upload multipart autorisé uniquement pour POST /api/imports.
        if ('POST' === $request->getMethod() && '/api/imports' === $request->getPathInfo()) {
            return;
        }
        $contentType = (string) $request->headers->get('Content-Type', '');
        $mime = strtolower(trim(explode(';', $contentType)[0]));
        if ('application/json' !== $mime) {
            $event->setResponse(new JsonResponse(
                ['error' => 'unsupported_media_type', 'message' => 'Content-Type application/json exigé.'],
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            ));
        }
    }
}
