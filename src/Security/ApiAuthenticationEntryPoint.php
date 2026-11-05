<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Point d'entrée du firewall main : toute requête /api protégée sans session
 * reçoit 401 {"error":"unauthenticated"} (contrat docs/api.md).
 */
final class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?\Throwable $authException = null): Response
    {
        return new JsonResponse(
            ['error' => 'unauthenticated', 'message' => 'Authentification requise.'],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
