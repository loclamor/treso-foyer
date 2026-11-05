<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * POST /api/logout : le firewall déconnecte (session invalidée, cookie supprimé),
 * la réponse est un 204 sans contenu (contrat docs/api.md).
 */
final class ApiLogoutListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => 'onLogout'];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $event->setResponse(new Response('', Response::HTTP_NO_CONTENT));
    }
}
